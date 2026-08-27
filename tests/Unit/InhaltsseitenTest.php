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
 * Die Inhaltsseiten müssen vollständig sein, bevor sie öffentlich stehen.
 *
 * Ein Impressum mit Lücken ist abmahnfähig, und eine Datenschutzerklärung,
 * die einen Dienstleister verschweigt, ist falsch. Beides fällt niemandem
 * auf, solange niemand hinsieht - deshalb sieht hier ein Test hin.
 */
final class InhaltsseitenTest extends TestCase
{
    private function seite(string $pfad): string
    {
        $zugang = new ExplodierenderZugang();

        $router = new Router(
            new SecretStore($zugang),
            new RateLimiter($zugang, str_repeat('T', 32), 20),
        );

        return $router->dispatch(new Request('GET', $pfad, '', '203.0.113.7'))->body;
    }

    /**
     * @return list<array{string}>
     */
    public static function alleSeiten(): array
    {
        return [['/'], ['/impressum'], ['/datenschutz'], ['/sicherheit'], ['/s/AAAAAAAAAAAAAAAAAAAAAA']];
    }

    #[DataProvider('alleSeiten')]
    public function testKeineSeiteEnthaeltNochPlatzhalter(string $pfad): void
    {
        $inhalt = $this->seite($pfad);

        foreach (['PLATZHALTER', 'TODO', 'Lorem ipsum', 'XXX'] as $marke) {
            self::assertStringNotContainsString(
                $marke,
                $inhalt,
                $pfad . ' enthält noch die Marke "' . $marke . '".'
            );
        }
    }

    public function testDasImpressumIstVollstaendig(): void
    {
        $inhalt = $this->seite('/impressum');

        // Pflichtangaben nach § 5 DDG.
        foreach ([
            'Sven Gauditz',
            'Ringstraße 3',
            '24321',
            'Behrensdorf',
            'info@pixagentur.com',
            '§ 18 Abs. 2 MStV',
        ] as $angabe) {
            self::assertStringContainsString($angabe, $inhalt, 'Im Impressum fehlt: ' . $angabe);
        }
    }

    public function testDieDatenschutzerklaerungNenntVerantwortlichenUndDienstleister(): void
    {
        $inhalt = $this->seite('/datenschutz');

        foreach ([
            'Sven Gauditz',
            'Behrensdorf',
            'IONOS SE',
            'Auftragsverarbeitung',
            // Was tatsächlich gespeichert wird - und was nicht.
            'Ablaufzeitpunkt',
            'HMAC-SHA256',
            'Keine IP-Adresse im Klartext',
            'Kein Erstellungszeitpunkt',
            'Keine Zugriffsprotokolle',
            'Artikel 6 Absatz 1 Buchstabe f',
            'Artikel 11',
        ] as $angabe) {
            self::assertStringContainsString($angabe, $inhalt, 'Im Datenschutz fehlt: ' . $angabe);
        }
    }

    /**
     * Wer Offenheit behauptet, nennt die Adresse.
     *
     * Eine Behauptung ohne Beleg ist auf dieser Seite besonders teuer: Der
     * ganze Absatz handelt davon, dass man uns nicht glauben muss. Steht
     * dort "der Quellcode liegt offen" ohne Verweis, ist genau das die
     * Stelle, an der man es doch müsste.
     */
    public function testWerOffenheitBehauptetNenntAuchDieAdresse(): void
    {
        foreach (['/', '/sicherheit'] as $pfad) {
            $inhalt = $this->seite($pfad);

            if (!str_contains($inhalt, 'Quellcode liegt offen')) {
                continue;
            }

            self::assertStringContainsString(
                'github.com/Nicooo76/einmalpost.de',
                $inhalt,
                $pfad . ' behauptet, der Quellcode liege offen, nennt aber keine Adresse.'
            );
        }
    }

    /**
     * Auf der Anzeigeseite darf nichts stehen, was das Fragment ersetzt.
     *
     * Die Sprungmarke ist der erste Tab-Stopp, und ihr Ziel schreibt beim
     * Betätigen `#hauptbereich` in die Adresse - der Schlüssel wäre damit weg,
     * und der Empfänger stünde vor einem scheinbar kaputten Link. Für den
     * Sprachwechsel im Fußbereich gilt dasselbe; beide fehlen dort mit Absicht.
     */
    public function testDieAnzeigeseiteTraegtNichtsWasDasFragmentErsetzt(): void
    {
        $anzeige = $this->seite('/s/AAAAAAAAAAAAAAAAAAAAAA');

        self::assertStringNotContainsString(
            'skip-link',
            $anzeige,
            'Die Sprungmarke ersetzt beim Betätigen das Fragment - und damit den Schlüssel.'
        );

        self::assertStringNotContainsString('site-footer__sprache', $anzeige);

        // Gegenprobe: Auf einer gewöhnlichen Seite gehört beides hin. Ohne sie
        // wäre der Test auch dann grün, wenn die Sprungmarke überall fehlte.
        $start = $this->seite('/');

        self::assertStringContainsString('skip-link', $start);
        self::assertStringContainsString('site-footer__sprache', $start);
    }

    /**
     * Und was bleibt, muss in der Sprache der Seite stehen.
     */
    public function testDieSprungmarkeStehtInDerSpracheDerSeite(): void
    {
        self::assertStringContainsString('Zum Inhalt springen', $this->seite('/'));
        self::assertStringContainsString('Skip to content', $this->seite('/en'));

        self::assertStringContainsString('content="de_DE"', $this->seite('/'));
        self::assertStringContainsString('content="en_US"', $this->seite('/en'));
    }

    public function testDerFussbereichVerweistAufDenQuellcode(): void
    {
        foreach (['/', '/impressum', '/sicherheit', '/s/AAAAAAAAAAAAAAAAAAAAAA'] as $pfad) {
            $inhalt = $this->seite($pfad);

            self::assertStringContainsString(
                'github.com/Nicooo76/einmalpost.de',
                $inhalt,
                'Auf ' . $pfad . ' fehlt der Verweis auf den Quellcode.'
            );

            // Das Zeichen liegt als Auszeichnung in der Seite, nicht als
            // Bilddatei von einem fremden Server.
            self::assertStringContainsString('<svg class="symbol"', $inhalt, $pfad);
            self::assertStringNotContainsString('githubusercontent', $inhalt, $pfad);
        }
    }

    public function testDieSicherheitsseiteBenenntDieOffeneGrenze(): void
    {
        $inhalt = $this->seite('/sicherheit');

        // Die unangenehme Stelle muss draufstehen, nicht nur die schöne.
        self::assertStringContainsString('das JavaScript dafür kommt von', $inhalt);
        self::assertStringContainsString('Sie würden es beim Benutzen nicht bemerken', $inhalt);
        self::assertStringContainsString('Was wir nicht versprechen', $inhalt);
    }

    #[DataProvider('alleSeiten')]
    public function testJedeSeiteHatEinenEigenenTitelUndEineBeschreibung(string $pfad): void
    {
        $inhalt = $this->seite($pfad);

        $titel = [];
        preg_match('/<title>(.*?)<\/title>/s', $inhalt, $titel);
        $titelText = $titel[1] ?? null;

        self::assertIsString($titelText, $pfad . ' hat keinen Titel.');
        self::assertGreaterThan(10, strlen(trim($titelText)), $pfad . ': Titel zu kurz.');

        $beschreibung = [];
        preg_match('/<meta name="description" content="([^"]*)"/', $inhalt, $beschreibung);
        $beschreibungText = $beschreibung[1] ?? null;

        self::assertIsString($beschreibungText, $pfad . ' hat keine Beschreibung.');
        self::assertGreaterThan(30, strlen($beschreibungText), $pfad . ': Beschreibung zu kurz.');
    }

    public function testDieTitelUnterscheidenSich(): void
    {
        $titel = [];

        foreach (['/', '/impressum', '/datenschutz', '/sicherheit'] as $pfad) {
            $treffer = [];
            preg_match('/<title>(.*?)<\/title>/s', $this->seite($pfad), $treffer);
            $titel[] = $treffer[1] ?? '';
        }

        self::assertSame(count($titel), count(array_unique($titel)), 'Zwei Seiten tragen denselben Titel.');
    }
}
