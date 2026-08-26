<?php

/**
 * Vorlage für config/config.php.
 *
 * Kopieren nach config/config.php und ausfüllen. Diese Datei liegt bewusst
 * NICHT unter public/ — sie ist über den Webserver nicht erreichbar.
 *
 * config/config.php ist in .gitignore und gehört niemals ins Repository.
 *
 * Alternativ lässt sich alles über Umgebungsvariablen setzen; existiert
 * config/config.php nicht, werden diese gelesen:
 *
 *   EINMALPOST_DSN            z. B. mysql:host=127.0.0.1;dbname=einmalpost;charset=utf8mb4
 *   EINMALPOST_DB_USER
 *   EINMALPOST_DB_PASSWORD
 *   EINMALPOST_RATE_PEPPER    base64, mindestens 32 Byte Rohmaterial
 *   EINMALPOST_RATE_MAX       ganze Zahl, Erstellungen je Stunde und IP
 *
 * Werte aus config/config.php haben Vorrang vor Umgebungsvariablen.
 */

declare(strict_types=1);

return [
    // --- Datenbank ---
    'dsn'         => 'mysql:host=127.0.0.1;dbname=einmalpost;charset=utf8mb4',
    'db_user'     => 'einmalpost',
    'db_password' => '',

    /*
     * --- Pepper für das Rate-Limit ---
     *
     * Aus diesem Wert wird täglich ein neuer Schlüssel abgeleitet, mit dem die
     * IP-Adresse zu einem HMAC verrechnet wird. Die IP selbst wird nirgends
     * gespeichert; nach einem Tageswechsel ist der Bezug zur IP auch rechnerisch
     * nicht mehr herstellbar, ohne den Pepper zu kennen.
     *
     * Erzeugen mit:
     *   php -r 'echo base64_encode(random_bytes(32)), PHP_EOL;'
     *
     * Mindestens 32 Byte. Der Platzhalter unten wird aktiv abgelehnt.
     */
    'rate_pepper' => 'BITTE-ERSETZEN',

    // --- Rate-Limit: Erstellungen je Stunde und IP ---
    'rate_max'    => 20,
];
