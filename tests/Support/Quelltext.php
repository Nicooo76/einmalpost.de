<?php

declare(strict_types=1);

namespace Einmalpost\Tests\Support;

/**
 * Entfernt Kommentare aus Quelltext, damit der Prüfer auf verbotene Muster
 * den wirksamen Code liest und nicht die Erklärungen darüber.
 *
 * Bewusst zurückhaltend: Entfernt werden nur Zeilen, die ausschließlich aus
 * einem Kommentar bestehen, sowie Blockkommentare. Steht Code neben einem
 * Kommentar, bleibt die Zeile vollständig erhalten und wird geprüft. Der
 * Fehler in die andere Richtung - ein übersehenes Muster - wäre der
 * gefährliche.
 *
 * Zeilennummern bleiben erhalten: Entfernte Stellen werden durch Leerzeichen
 * ersetzt, nicht herausgeschnitten.
 */
final class Quelltext
{
    public static function ohneKommentare(string $inhalt, string $endung): string
    {
        $inhalt = match ($endung) {
            'php'   => self::phpOhneKommentare($inhalt),
            'js'    => self::zeilenUndBloecke($inhalt, '//'),
            'sql'   => self::zeilenUndBloecke($inhalt, '--'),
            'css'   => self::zeilenUndBloecke($inhalt, '//'),
            default => $inhalt,
        };

        return self::ohneHtmlKommentare($inhalt);
    }

    /**
     * Für PHP über den Zerteiler der Sprache selbst - genauer geht es nicht.
     */
    private static function phpOhneKommentare(string $inhalt): string
    {
        $ergebnis = '';

        foreach (token_get_all($inhalt) as $abschnitt) {
            if (is_array($abschnitt)) {
                $ergebnis .= in_array($abschnitt[0], [T_COMMENT, T_DOC_COMMENT], true)
                    ? self::leeren($abschnitt[1])
                    : $abschnitt[1];

                continue;
            }

            $ergebnis .= $abschnitt;
        }

        return $ergebnis;
    }

    private static function zeilenUndBloecke(string $inhalt, string $zeilenanfang): string
    {
        $inhalt = (string) preg_replace_callback(
            '~/\*.*?\*/~s',
            static fn (array $treffer): string => self::leeren((string) $treffer[0]),
            $inhalt
        );

        $zeilen = explode("\n", $inhalt);

        foreach ($zeilen as $nummer => $zeile) {
            if (str_starts_with(ltrim($zeile), $zeilenanfang)) {
                $zeilen[$nummer] = '';
            }
        }

        return implode("\n", $zeilen);
    }

    private static function ohneHtmlKommentare(string $inhalt): string
    {
        return (string) preg_replace_callback(
            '~<!--.*?-->~s',
            static fn (array $treffer): string => self::leeren((string) $treffer[0]),
            $inhalt
        );
    }

    /**
     * Ersetzt alles außer Zeilenumbrüchen durch Leerzeichen, damit die
     * Zeilennummern stimmen bleiben.
     */
    private static function leeren(string $text): string
    {
        return (string) preg_replace('/[^\n]/', ' ', $text);
    }
}
