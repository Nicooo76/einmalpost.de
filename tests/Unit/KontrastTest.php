<?php

declare(strict_types=1);

namespace Einmalpost\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Kontrast nach WCAG 2.1.
 *
 * Der Entwurf ist die Quelle für das Aussehen, aber nicht für die
 * Lesbarkeit: Gedämpfte Grautöne und Platzhaltertexte liegen in Entwürfen
 * häufig unter dem Mindestwert. Hier wird gerechnet statt geschätzt, und im
 * Zweifel gewinnt die Lesbarkeit gegen die Vorlage.
 *
 * Geprüft werden beide Farbschemata. Ein Element, das nur in einem davon
 * lesbar ist, fällt hier auf.
 */
final class KontrastTest extends TestCase
{
    /** Mindestkontrast für Fließtext. */
    private const AA_TEXT = 4.5;

    /**
     * Relative Luminanz eines Farbwerts nach WCAG.
     */
    private static function luminanz(string $hex): float
    {
        $hex = ltrim($hex, '#');

        if (strlen($hex) === 3) {
            $hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
        }

        $kanaele = [];

        foreach ([0, 2, 4] as $versatz) {
            $wert = ((int) hexdec(substr($hex, $versatz, 2))) / 255;

            $kanaele[] = $wert <= 0.03928
                ? $wert / 12.92
                : (($wert + 0.055) / 1.055) ** 2.4;
        }

        return 0.2126 * $kanaele[0] + 0.7152 * $kanaele[1] + 0.0722 * $kanaele[2];
    }

    public static function kontrast(string $vordergrund, string $hintergrund): float
    {
        $a = self::luminanz($vordergrund);
        $b = self::luminanz($hintergrund);

        $heller  = max($a, $b);
        $dunkler = min($a, $b);

        return ($heller + 0.05) / ($dunkler + 0.05);
    }

    /**
     * Die Rechnung selbst muss stimmen, bevor man ihr etwas glaubt.
     */
    public function testDieRechnungStimmtAnBekanntenWerten(): void
    {
        // Schwarz auf Weiß ist der Höchstwert 21:1.
        self::assertEqualsWithDelta(21.0, self::kontrast('#000000', '#FFFFFF'), 0.05);
        // Gleiche Farbe ergibt 1:1.
        self::assertEqualsWithDelta(1.0, self::kontrast('#777777', '#777777'), 0.01);
        // Ein bekannter Zwischenwert: #767676 auf Weiß ist genau AA-konform.
        self::assertGreaterThanOrEqual(4.5, self::kontrast('#767676', '#FFFFFF'));
        self::assertLessThan(4.5, self::kontrast('#777777', '#FFFFFF') - 0.01);
    }

    /**
     * @return list<array{string, string, string, float}> Name, Vordergrund, Hintergrund, Mindestwert
     */
    public static function hellePaare(): array
    {
        return [
            ['Fließtext auf Grund',        '#141B2E', '#FAF8F2', self::AA_TEXT],
            ['Fließtext auf Karte',        '#141B2E', '#FFFFFF', self::AA_TEXT],
            ['Gedämpfter Text auf Grund',  '#55606F', '#FAF8F2', self::AA_TEXT],
            ['Gedämpfter Text auf Karte',  '#55606F', '#FFFFFF', self::AA_TEXT],
            ['Platzhalter im Eingabefeld', '#55606F', '#FAF8F2', self::AA_TEXT],
            ['Verweis auf Grund',          '#8C5D06', '#FAF8F2', self::AA_TEXT],
            ['Verweis auf Karte',          '#8C5D06', '#FFFFFF', self::AA_TEXT],
            ['Knopftext auf Akzent',       '#FFFFFF', '#8C5D06', self::AA_TEXT],
            ['Schrittüberschrift',         '#8C5D06', '#FFFFFF', self::AA_TEXT],
            ['Code auf stiller Fläche',    '#141B2E', '#ECEAE3', self::AA_TEXT],
        ];
    }

    /**
     * @return list<array{string, string, string, float}>
     */
    public static function dunklePaare(): array
    {
        return [
            ['Fließtext auf Grund',        '#FAF8F2', '#141B2E', self::AA_TEXT],
            ['Fließtext auf Karte',        '#FAF8F2', '#1B2440', self::AA_TEXT],
            ['Gedämpfter Text auf Grund',  '#A9B4C8', '#141B2E', self::AA_TEXT],
            ['Gedämpfter Text auf Karte',  '#A9B4C8', '#1B2440', self::AA_TEXT],
            ['Platzhalter im Eingabefeld', '#A9B4C8', '#141B2E', self::AA_TEXT],
            ['Verweis auf Grund',          '#F5A81C', '#141B2E', self::AA_TEXT],
            ['Verweis auf Karte',          '#F5A81C', '#1B2440', self::AA_TEXT],
            ['Knopftext auf Akzent',       '#141B2E', '#F5A81C', self::AA_TEXT],
            ['Statuszeile auf Karte',      '#F5A81C', '#1B2440', self::AA_TEXT],
            ['Code auf stiller Fläche',    '#FAF8F2', '#212B4A', self::AA_TEXT],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('hellePaare')]
    public function testHellesSchemaIstLesbar(string $name, string $vorn, string $hinten, float $mindestens): void
    {
        $wert = self::kontrast($vorn, $hinten);

        self::assertGreaterThanOrEqual(
            $mindestens,
            $wert,
            sprintf('%s: %s auf %s ergibt nur %.2f:1, nötig sind %.1f:1.', $name, $vorn, $hinten, $wert, $mindestens)
        );
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('dunklePaare')]
    public function testDunklesSchemaIstLesbar(string $name, string $vorn, string $hinten, float $mindestens): void
    {
        $wert = self::kontrast($vorn, $hinten);

        self::assertGreaterThanOrEqual(
            $mindestens,
            $wert,
            sprintf('%s: %s auf %s ergibt nur %.2f:1, nötig sind %.1f:1.', $name, $vorn, $hinten, $wert, $mindestens)
        );
    }

    /**
     * Die geprüften Farbwerte müssen auch tatsächlich in theme.css stehen -
     * sonst prüft dieser Test eine Liste, die mit der Gestaltung nichts mehr
     * zu tun hat.
     */
    public function testDieGeprueftenFarbenStehenAuchInDerGestaltung(): void
    {
        $datei = dirname(__DIR__, 2) . '/public/assets/theme.css';

        if (!is_file($datei)) {
            // Im frischen Klon liegt nur die schmucklose Fassung. Dann gibt
            // es nichts abzugleichen - und nichts zu verschweigen.
            self::assertFileExists(
                dirname(__DIR__, 2) . '/public/assets/theme-default.css',
                'Weder theme.css noch theme-default.css vorhanden.'
            );

            return;
        }

        $inhalt = (string) file_get_contents($datei);
        $fehlend = [];

        foreach ([...self::hellePaare(), ...self::dunklePaare()] as [$name, $vorn, $hinten]) {
            foreach ([$vorn, $hinten] as $farbe) {
                if (stripos($inhalt, $farbe) === false) {
                    $fehlend[$farbe] = $farbe;
                }
            }
        }

        self::assertSame([], array_values($fehlend), 'Diese geprüften Farben stehen nicht in theme.css.');
    }
}
