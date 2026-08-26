<?php

declare(strict_types=1);

namespace Einmalpost\Tests\Unit;

use Einmalpost\Http\Request;
use Einmalpost\Http\Router;
use Einmalpost\RateLimiter;
use Einmalpost\SecretStore;
use Einmalpost\Tests\Support\ExplodierenderZugang;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Zusage 4: GET /s/{id} verbraucht nichts und fragt die Datenbank nicht ab.
 * Zusage 5: Vorschau-Bots verbrennen nichts.
 *
 * Der Router bekommt hier einen Datenbankzugang, der bei jeder Berührung
 * eine Ausnahme wirft. Wenn diese Tests grün sind, kann der geprüfte Weg die
 * Datenbank nicht angefasst haben - unabhängig davon, was der Code behauptet.
 */
final class RouterOhneDatenbankTest extends TestCase
{
    private ExplodierenderZugang $zugang;

    private Router $router;

    protected function setUp(): void
    {
        parent::setUp();

        $this->zugang = new ExplodierenderZugang();
        $this->router = new Router(
            new SecretStore($this->zugang),
            new RateLimiter($this->zugang, str_repeat('T', 32), 20),
        );
    }

    public function testDieAnzeigeseiteFasstDieDatenbankNichtAn(): void
    {
        $antwort = $this->router->dispatch(
            new Request('GET', '/s/AAAAAAAAAAAAAAAAAAAAAA', '', '203.0.113.7')
        );

        self::assertSame(200, $antwort->status);
        self::assertSame(0, $this->zugang->versuche, 'Es wurde keine Verbindung angefordert.');
    }

    public function testDasFormularFasstDieDatenbankNichtAn(): void
    {
        $antwort = $this->router->dispatch(new Request('GET', '/', '', '203.0.113.7'));

        self::assertSame(200, $antwort->status);
        self::assertSame(0, $this->zugang->versuche);
    }

    /**
     * Vorschau-Bots rufen Links automatisch ab. Sie führen kein JavaScript
     * aus und senden kein POST - aber sie holen die Seite. Genau deshalb
     * darf GET nichts verbrauchen.
     *
     * @return list<array{string, string}>
     */
    public static function botAbrufe(): array
    {
        return [
            ['GET',  '/s/AAAAAAAAAAAAAAAAAAAAAA'],
            ['HEAD', '/s/AAAAAAAAAAAAAAAAAAAAAA'],
            ['GET',  '/s/AAAAAAAAAAAAAAAAAAAAAA?utm_source=slack'],
            ['GET',  '/s/'],
            ['GET',  '/s/beliebiger-unsinn'],
            ['HEAD', '/'],
        ];
    }

    #[DataProvider('botAbrufe')]
    public function testKeinAbrufPerGetOderHeadBeruehrtDieDatenbank(string $methode, string $pfad): void
    {
        $pfadOhneAbfrage = (string) (parse_url($pfad, PHP_URL_PATH) ?: '/');

        $antwort = $this->router->dispatch(
            new Request($methode, $pfadOhneAbfrage, '', '203.0.113.7')
        );

        self::assertSame(0, $this->zugang->versuche, $methode . ' ' . $pfad . ' hat die Datenbank angefasst.');
        self::assertContains($antwort->status, [200, 404]);
    }

    public function testDieAnzeigeseiteEnthaeltDasGeheimnisNicht(): void
    {
        $antwort = $this->router->dispatch(
            new Request('GET', '/s/AAAAAAAAAAAAAAAAAAAAAA', '', '203.0.113.7')
        );

        // Die Seite ist ein Gerüst mit einem Knopf. Sie kennt den Inhalt
        // nicht einmal - er wird erst nach dem Klick geholt.
        self::assertStringContainsString('anzeigen', $antwort->body);
        self::assertStringNotContainsString('payload', $antwort->body);
    }

    public function testFalscheMethodeAufDenSchnittstellenBeruehrtNichts(): void
    {
        foreach (['GET', 'PUT', 'DELETE', 'PATCH', 'OPTIONS'] as $methode) {
            $antwort = $this->router->dispatch(new Request($methode, '/api/reveal', '', '203.0.113.7'));

            self::assertSame(405, $antwort->status, $methode . ' /api/reveal');
            self::assertSame(0, $this->zugang->versuche);
        }
    }

    public function testUnbekannterPfadBeruehrtNichts(): void
    {
        foreach (['/gibtsnicht', '/api', '/api/', '/.env', '/config/config.php', '/../src/Config.php'] as $pfad) {
            $antwort = $this->router->dispatch(new Request('GET', $pfad, '', '203.0.113.7'));

            self::assertSame(404, $antwort->status, $pfad);
            self::assertSame(0, $this->zugang->versuche, $pfad);
        }
    }
}
