<?php

declare(strict_types=1);

namespace Einmalpost\Tests\Unit;

use Einmalpost\Http\Request;
use Einmalpost\Http\Router;
use Einmalpost\Http\SecurityHeaders;
use Einmalpost\RateLimiter;
use Einmalpost\SecretStore;
use Einmalpost\Tests\Support\ExplodierenderZugang;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Zusage 17: Alle Kopfzeilen sind gesetzt.
 *
 * Dazu gehört auch das Gegenteil: Strict-Transport-Security darf hier NICHT
 * gesetzt werden. HSTS kommt von nginx über Plesk. Setzen es beide, kommt
 * die Kopfzeile doppelt an, und wie ein Client zwei widersprüchliche
 * HSTS-Zeilen auflöst, ist nichts, worauf sich eine Zusage stützen darf.
 */
final class KopfzeilenTest extends TestCase
{
    private Router $router;

    protected function setUp(): void
    {
        parent::setUp();

        $zugang = new ExplodierenderZugang();

        $this->router = new Router(
            new SecretStore($zugang),
            new RateLimiter($zugang, str_repeat('T', 32), 20),
        );
    }

    /**
     * Liest die erste Klammergruppe aus einem Ausdruck und stellt sicher,
     * dass sie da ist.
     */
    private static function ersterTreffer(string $muster, string $text, string $meldung): string
    {
        $treffer = [];
        preg_match($muster, $text, $treffer);

        $wert = $treffer[1] ?? null;

        self::assertIsString($wert, $meldung);

        return $wert;
    }

    /**
     * @return list<array{string}>
     */
    public static function seiten(): array
    {
        return [['/'], ['/s/AAAAAAAAAAAAAAAAAAAAAA']];
    }

    #[DataProvider('seiten')]
    public function testJedeSeiteTraegtDieSicherheitskopfzeilen(string $pfad): void
    {
        $kopf = $this->router->dispatch(new Request('GET', $pfad, '', '203.0.113.7'))->headers;

        self::assertArrayHasKey('Content-Security-Policy', $kopf);
        self::assertSame('no-referrer', $kopf['Referrer-Policy']);
        self::assertSame('nosniff', $kopf['X-Content-Type-Options']);
        self::assertArrayHasKey('Permissions-Policy', $kopf);
    }

    #[DataProvider('seiten')]
    public function testDieCspEnthaeltGenauDieVorgegebenenAnweisungen(string $pfad): void
    {
        $csp = $this->router->dispatch(new Request('GET', $pfad, '', '203.0.113.7'))
            ->headers['Content-Security-Policy'];

        self::assertMatchesRegularExpression("/script-src 'nonce-[A-Za-z0-9+\/=]+' 'strict-dynamic'/", $csp);
        self::assertStringContainsString("object-src 'none'", $csp);
        self::assertStringContainsString("base-uri 'none'", $csp);
        self::assertStringContainsString("require-trusted-types-for 'script'", $csp);
    }

    #[DataProvider('seiten')]
    public function testSeitenMitNonceDuerfenNichtZwischengespeichertWerden(string $pfad): void
    {
        $kopf = $this->router->dispatch(new Request('GET', $pfad, '', '203.0.113.7'))->headers;

        // Ohne no-store könnte eine zwischengespeicherte Seite denselben
        // Nonce ein zweites Mal ausliefern. Ein Nonce, der zweimal gilt,
        // ist kein Nonce.
        self::assertStringContainsString('no-store', $kopf['Cache-Control']);
    }

    #[DataProvider('seiten')]
    public function testDerNonceImSkriptTagStehtAuchInDerCsp(string $pfad): void
    {
        $antwort = $this->router->dispatch(new Request('GET', $pfad, '', '203.0.113.7'));

        $nonce = self::ersterTreffer(
            '/<script nonce="([^"]+)"/',
            $antwort->body,
            'Auf der Seite steht kein Skript mit Nonce.'
        );

        self::assertStringContainsString(
            "'nonce-" . $nonce . "'",
            $antwort->headers['Content-Security-Policy'],
            'Der Nonce im Skript-Tag steht nicht in der CSP - das Skript würde blockiert.'
        );
    }

    /**
     * Nicht nur das erste Skript-Tag - jedes. Ein Skript ohne Nonce würde
     * von der CSP blockiert, und die Seite wäre stumm kaputt.
     */
    #[DataProvider('seiten')]
    public function testJedesSkriptAufDerSeiteTraegtDenNonce(string $pfad): void
    {
        $antwort = $this->router->dispatch(new Request('GET', $pfad, '', '203.0.113.7'));

        $alleTags = [];
        preg_match_all('/<script\b[^>]*>/i', $antwort->body, $alleTags);

        self::assertNotSame([], $alleTags[0], 'Die Seite lädt überhaupt kein Skript.');

        $nonce = self::ersterTreffer(
            "/'nonce-([^']+)'/",
            $antwort->headers['Content-Security-Policy'],
            'In der CSP steht kein Nonce.'
        );

        foreach ($alleTags[0] as $tag) {
            self::assertStringContainsString(
                'nonce="' . $nonce . '"',
                $tag,
                'Dieses Skript-Tag trägt nicht den Nonce der Seite: ' . $tag
            );
        }
    }

    public function testBeideSeitenLadenDieGemeinsamenKryptoBausteine(): void
    {
        foreach (['/', '/s/AAAAAAAAAAAAAAAAAAAAAA'] as $pfad) {
            $antwort = $this->router->dispatch(new Request('GET', $pfad, '', '203.0.113.7'));

            self::assertStringContainsString(
                '/assets/krypto.js',
                $antwort->body,
                $pfad . ' lädt krypto.js nicht - ohne die gemeinsamen Bausteine tut die Seite nichts.'
            );
        }
    }

    public function testDerNonceIstMindestensHundertachtundzwanzigBit(): void
    {
        for ($i = 0; $i < 20; $i++) {
            $nonce = SecurityHeaders::nonce();
            $roh   = base64_decode($nonce, true);

            self::assertIsString($roh);
            self::assertGreaterThanOrEqual(16, strlen($roh), 'Der Nonce ist kürzer als 128 Bit.');
        }
    }

    public function testJedeAntwortBekommtEinenNeuenNonce(): void
    {
        $gesehen = [];

        for ($i = 0; $i < 50; $i++) {
            $csp = $this->router->dispatch(new Request('GET', '/', '', '203.0.113.7'))
                ->headers['Content-Security-Policy'];

            $gesehen[] = self::ersterTreffer("/'nonce-([^']+)'/", $csp, 'Kein Nonce in der CSP.');
        }

        self::assertCount(50, array_unique($gesehen), 'Ein Nonce wurde wiederverwendet.');
    }

    /**
     * @return list<array{string, string, string}>
     */
    public static function alleAntworten(): array
    {
        return [
            ['GET',  '/', ''],
            ['GET',  '/s/AAAAAAAAAAAAAAAAAAAAAA', ''],
            ['GET',  '/gibtsnicht', ''],
            ['PUT',  '/api/reveal', ''],
            ['POST', '/api/create', 'kein json'],
        ];
    }

    /**
     * Kein Weg durch die Anwendung darf HSTS setzen - auch kein Fehlerweg.
     */
    #[DataProvider('alleAntworten')]
    public function testPhpSetztNiemalsHsts(string $methode, string $pfad, string $rumpf): void
    {
        $kopf = $this->router->dispatch(new Request($methode, $pfad, $rumpf, '203.0.113.7'))->headers;

        foreach (array_keys($kopf) as $name) {
            self::assertNotSame(
                'strict-transport-security',
                strtolower((string) $name),
                'HSTS gehört auf die nginx-Ebene, nicht in den PHP-Code.'
            );
        }
    }

    public function testDieQuelltexteSetzenNirgendsHsts(): void
    {
        $wurzel = dirname(__DIR__, 2);
        $treffer = [];

        foreach (['src', 'public', 'bin'] as $ordner) {
            $verzeichnis = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($wurzel . '/' . $ordner)
            );

            /** @var \SplFileInfo $datei */
            foreach ($verzeichnis as $datei) {
                if (!$datei->isFile() || !in_array($datei->getExtension(), ['php', 'js'], true)) {
                    continue;
                }

                $inhalt = (string) file_get_contents($datei->getPathname());

                if (preg_match('/header\s*\(\s*[\'"]\s*Strict-Transport-Security/i', $inhalt) === 1) {
                    $treffer[] = $datei->getPathname();
                }
            }
        }

        self::assertSame([], $treffer, 'HSTS wird im Code gesetzt: ' . implode(', ', $treffer));
    }

    public function testDieAntwortenDerSchnittstelleDuerfenNirgendsLiegenbleiben(): void
    {
        // Die Antwort von /api/reveal enthält das Geheimnis.
        $kopf = SecurityHeaders::forApi();

        self::assertStringContainsString('no-store', $kopf['Cache-Control']);
        self::assertSame('no-referrer', $kopf['Referrer-Policy']);
    }
}
