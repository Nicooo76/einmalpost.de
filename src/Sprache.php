<?php

declare(strict_types=1);

namespace Einmalpost;

/**
 * Welche Sprache eine Anfrage bekommt.
 *
 * Deutsch unter /, Englisch unter /en/. Keine Weiterleitung nach
 * Browsereinstellung: Wer einen Link bekommt, soll die Seite sehen, auf die
 * der Link zeigt - und nicht eine andere, weil sein Browser etwas anderes
 * bevorzugt. Auf /s/* wäre eine Weiterleitung sogar schädlich, weil
 * Fragmente sie nicht zuverlässig überleben.
 */
final class Sprache
{
    public const DEUTSCH = 'de';

    public const ENGLISCH = 'en';

    /** @var list<string> */
    public const ALLE = [self::DEUTSCH, self::ENGLISCH];

    /**
     * Trennt die Sprache vom Pfad.
     *
     * @return array{string, string} Sprache und der Pfad ohne Sprachpräfix.
     */
    public static function ausPfad(string $pfad): array
    {
        if ($pfad === '/en' || str_starts_with($pfad, '/en/')) {
            $rest = substr($pfad, 3);

            return [self::ENGLISCH, $rest === '' ? '/' : $rest];
        }

        return [self::DEUTSCH, $pfad];
    }

    /**
     * Setzt ein Sprachpräfix wieder vor einen Pfad.
     */
    public static function zuPfad(string $sprache, string $pfad): string
    {
        if ($sprache !== self::ENGLISCH) {
            return $pfad;
        }

        return $pfad === '/' ? '/en' : '/en' . $pfad;
    }

    /**
     * Dieselbe Seite in beiden Sprachen.
     *
     * Die Wege heißen nicht überall gleich: /sicherheit gegen /security.
     * Ohne diese Tabelle zeigte der Sprachwechsel auf einen Pfad, den es in
     * der anderen Fassung nicht gibt - und hreflang behauptete dasselbe
     * gegenüber Suchmaschinen.
     *
     * @var array<string, array{de: string, en: string}>
     */
    private const WEGE = [
        'start'      => ['de' => '/', 'en' => '/'],
        'sicherheit' => ['de' => '/sicherheit', 'en' => '/security'],
    ];

    /**
     * Beide Fassungen eines Pfads, mit Sprachpräfix.
     *
     * @return array{de: string, en: string}
     */
    public static function beideFassungen(string $pfadOhnePraefix): array
    {
        foreach (self::WEGE as $seite) {
            if ($seite['de'] === $pfadOhnePraefix || $seite['en'] === $pfadOhnePraefix) {
                return [
                    'de' => self::zuPfad(self::DEUTSCH, $seite['de']),
                    'en' => self::zuPfad(self::ENGLISCH, $seite['en']),
                ];
            }
        }

        // Seiten ohne Gegenstück - etwa Impressum und Datenschutz, die es
        // nur auf Deutsch gibt.
        return [
            'de' => self::zuPfad(self::DEUTSCH, $pfadOhnePraefix),
            'en' => self::zuPfad(self::ENGLISCH, '/'),
        ];
    }

    /**
     * Verzeichnis der Vorlagen für diese Sprache.
     */
    public static function vorlagenOrdner(string $sprache): string
    {
        return $sprache === self::ENGLISCH ? 'en/' : '';
    }
}
