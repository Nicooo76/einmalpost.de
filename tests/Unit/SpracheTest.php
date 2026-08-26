<?php

declare(strict_types=1);

namespace Einmalpost\Tests\Unit;

use Einmalpost\Sprache;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Die Wegestruktur der Sprachen.
 */
final class SpracheTest extends TestCase
{
    /**
     * @return list<array{string, string, string}> Pfad, erwartete Sprache, erwarteter Restpfad
     */
    public static function pfade(): array
    {
        return [
            ['/',                    Sprache::DEUTSCH,  '/'],
            ['/sicherheit',          Sprache::DEUTSCH,  '/sicherheit'],
            ['/s/AAAA',              Sprache::DEUTSCH,  '/s/AAAA'],
            ['/en',                  Sprache::ENGLISCH, '/'],
            ['/en/',                 Sprache::ENGLISCH, '/'],
            ['/en/security',         Sprache::ENGLISCH, '/security'],
            ['/en/s/AAAA',           Sprache::ENGLISCH, '/s/AAAA'],
            // Kein Sprachpräfix, nur ein ähnlich beginnender Pfad.
            ['/end',                 Sprache::DEUTSCH,  '/end'],
            ['/entwurf',             Sprache::DEUTSCH,  '/entwurf'],
        ];
    }

    #[DataProvider('pfade')]
    public function testDieSpracheWirdAusDemPfadGelesen(string $pfad, string $sprache, string $rest): void
    {
        self::assertSame([$sprache, $rest], Sprache::ausPfad($pfad));
    }

    public function testDerWegLaesstSichWiederZusammensetzen(): void
    {
        foreach (['/', '/sicherheit', '/s/AAAA'] as $pfad) {
            foreach (Sprache::ALLE as $sprache) {
                $zusammen = Sprache::zuPfad($sprache, $pfad);

                self::assertSame(
                    [$sprache, $pfad],
                    Sprache::ausPfad($zusammen),
                    $sprache . ' + ' . $pfad . ' ergab ' . $zusammen
                );
            }
        }
    }

    public function testBeideFassungenZeigenAufDieselbeSeite(): void
    {
        self::assertSame(['de' => '/', 'en' => '/en'], Sprache::beideFassungen('/'));

        // Der Weg heißt in beiden Sprachen anders - beide Richtungen müssen
        // dasselbe Paar ergeben.
        $erwartet = ['de' => '/sicherheit', 'en' => '/en/security'];

        self::assertSame($erwartet, Sprache::beideFassungen('/sicherheit'));
        self::assertSame($erwartet, Sprache::beideFassungen('/security'));
    }

    public function testSeitenOhneGegenstueckVerweisenAufDieStartseite(): void
    {
        // Impressum und Datenschutz gibt es nur auf Deutsch.
        $fassungen = Sprache::beideFassungen('/impressum');

        self::assertSame('/impressum', $fassungen['de']);
        self::assertSame('/en', $fassungen['en']);
    }

    public function testDeutschBekommtKeinPraefix(): void
    {
        self::assertSame('/', Sprache::zuPfad(Sprache::DEUTSCH, '/'));
        self::assertSame('/sicherheit', Sprache::zuPfad(Sprache::DEUTSCH, '/sicherheit'));
    }
}
