<?php

declare(strict_types=1);

namespace Einmalpost\Http;

/**
 * Die Kopfzeilen, die auf jeder Antwort stehen.
 *
 * Strict-Transport-Security steht bewusst NICHT hier: HSTS wird auf
 * nginx-Ebene über Plesk gesetzt. Setzen es beide, kommt die Kopfzeile
 * doppelt an, und wie ein Client zwei widersprüchliche HSTS-Zeilen auflöst,
 * ist nichts, worauf sich eine Sicherheitszusage stützen darf.
 * Genau eine Quelle, und das ist nginx.
 */
final class SecurityHeaders
{
    /** 16 Byte = 128 Bit. */
    public const NONCE_BYTES = 16;

    public static function nonce(): string
    {
        return base64_encode(random_bytes(self::NONCE_BYTES));
    }

    /**
     * @return array<string, string>
     */
    public static function forNonce(string $nonce): array
    {
        return [
            'Content-Security-Policy' => sprintf(
                "default-src 'none'; "
                . "script-src 'nonce-%s' 'strict-dynamic'; "
                // 'self' kam mit der Gestaltung dazu: theme-default.css und
                // theme.css sind eigene Dateien. 'unsafe-inline' bleibt für
                // das hidden-Umschalten der Zustände. Fremde Hosts sind
                // weiterhin ausgeschlossen - default-src 'none' gilt.
                . "style-src 'self' 'unsafe-inline'; "
                // Schriften und Bilder ausschließlich aus dem eigenen
                // Projekt. Kein data: - der QR-Code ist Inline-SVG, der
                // Anhang lädt über blob:, kein Bild braucht es.
                . "font-src 'self'; "
                . "img-src 'self'; "
                . "connect-src 'self'; "
                . "object-src 'none'; base-uri 'none'; frame-ancestors 'none'; form-action 'none'; "
                . "require-trusted-types-for 'script'",
                $nonce
            ),
            'Referrer-Policy'        => 'no-referrer',
            'X-Content-Type-Options' => 'nosniff',
            'Permissions-Policy'     => self::PERMISSIONS_POLICY,
            // Ohne no-store könnte eine zwischengespeicherte Seite denselben
            // Nonce ein zweites Mal ausliefern. Ein Nonce, der zweimal gilt,
            // ist kein Nonce.
            'Cache-Control'          => 'no-store, no-cache, must-revalidate',
            'Pragma'                 => 'no-cache',
        ];
    }

    /**
     * Für Antworten ohne Nonce (JSON). Enthält alles außer der CSP-Zeile.
     *
     * @return array<string, string>
     */
    public static function forApi(): array
    {
        return [
            'Content-Security-Policy' => "default-src 'none'; base-uri 'none'; frame-ancestors 'none'",
            'Referrer-Policy'         => 'no-referrer',
            'X-Content-Type-Options'  => 'nosniff',
            'Permissions-Policy'      => self::PERMISSIONS_POLICY,
            // Antworten von /api/reveal enthalten das Geheimnis. Sie dürfen
            // nirgendwo liegenbleiben.
            'Cache-Control'           => 'no-store, no-cache, must-revalidate',
            'Pragma'                  => 'no-cache',
        ];
    }

    private const PERMISSIONS_POLICY =
        'accelerometer=(), ambient-light-sensor=(), autoplay=(), battery=(), camera=(), '
        . 'display-capture=(), document-domain=(), encrypted-media=(), fullscreen=(), '
        . 'geolocation=(), gyroscope=(), magnetometer=(), microphone=(), midi=(), '
        . 'payment=(), picture-in-picture=(), publickey-credentials-get=(), '
        . 'screen-wake-lock=(), serial=(), usb=(), xr-spatial-tracking=(), interest-cohort=()';
}
