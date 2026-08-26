<?php

/**
 * Autoloader für den Betrieb.
 *
 * Bewusst ohne Composer: Composer und npm sind Entwicklungswerkzeuge, und
 * nichts davon wird ausgeliefert. Der Dienst läuft mit dem, was unter src/
 * und public/ liegt - sonst nichts.
 *
 * Die Tests laden dieselben Klassen über den Composer-Autoloader; beide
 * Wege zeigen auf dieselben Dateien. Dass der Betrieb ohne vendor/
 * auskommt, prüft tests/Unit/AuslieferungTest.php.
 */

declare(strict_types=1);

spl_autoload_register(static function (string $klasse): void {
    $praefix = 'Einmalpost\\';

    if (!str_starts_with($klasse, $praefix)) {
        return;
    }

    $relativ = str_replace('\\', '/', substr($klasse, strlen($praefix)));
    $pfad    = __DIR__ . '/' . $relativ . '.php';

    if (is_file($pfad)) {
        require_once $pfad;
    }
});
