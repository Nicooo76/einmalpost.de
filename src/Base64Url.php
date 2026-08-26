<?php

declare(strict_types=1);

namespace Einmalpost;

/**
 * base64url ohne Auffüllzeichen (RFC 4648, Abschnitt 5).
 *
 * Wird für die Geheimnis-ID im Link und für den payload auf dem Transportweg
 * verwendet. Das Dekodieren ist streng: Zeichen außerhalb des Alphabets
 * werden abgelehnt, statt still übergangen zu werden. Sonst würde die
 * Anwendung Eingaben annehmen, die sie nicht versteht.
 */
final class Base64Url
{
    public static function encode(string $raw): string
    {
        return rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
    }

    /**
     * @return string|null Null, wenn die Eingabe kein gültiges base64url ist.
     */
    public static function decode(string $encoded): ?string
    {
        if ($encoded === '') {
            return null;
        }

        // Klassisches base64 mit + / = wird nicht angenommen. Wer das
        // schickt, hat etwas anderes gemeint, als hier erwartet wird.
        if (preg_match('/\A[A-Za-z0-9_-]+\z/', $encoded) !== 1) {
            return null;
        }

        $padded  = strtr($encoded, '-_', '+/');
        $rest    = strlen($padded) % 4;

        if ($rest === 1) {
            // Länge, die aus keiner Kodierung entstehen kann.
            return null;
        }

        if ($rest !== 0) {
            $padded .= str_repeat('=', 4 - $rest);
        }

        $decoded = base64_decode($padded, true);

        return $decoded === false ? null : $decoded;
    }
}
