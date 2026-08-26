#!/usr/bin/env php
<?php

/**
 * Räumt abgelaufene Zeilen weg. Aufruf über Cron, etwa alle zehn Minuten:
 *
 *   *_/10 * * * *  /opt/plesk/php/8.3/bin/php /pfad/zu/bin/cleanup.php
 *
 * Das MariaDB-Event aus db/event.sql tut dasselbe noch einmal, unabhängig
 * davon. Zwei Netze - und selbst wenn beide reißen, prüft der Abruf den
 * Ablaufzeitpunkt zusätzlich selbst.
 *
 * Die Konfiguration kommt aus config/config.php. Liegt sie woanders, wird
 * ihr Pfad über die Umgebungsvariable EINMALPOST_CONFIG gesetzt.
 *
 * Ausgabe: eine Zeile mit der Anzahl gelöschter Zeilen. Keine IDs, keine
 * Zeitpunkte, nichts, was in ein Protokoll gehören würde.
 */

declare(strict_types=1);

require_once dirname(__DIR__) . '/src/autoload.php';

use Einmalpost\Config;
use Einmalpost\Database;
use Einmalpost\SecretStore;

try {
    $store = new SecretStore(new Database(Config::load()));

    printf('%d Zeilen entfernt.%s', $store->purgeExpired(), PHP_EOL);

    exit(0);
} catch (Throwable $fehler) {
    // Auf stderr, damit Cron es meldet. Ohne Einzelheiten zu den Daten.
    fwrite(STDERR, 'Aufräumen fehlgeschlagen: ' . $fehler::class . PHP_EOL);

    exit(1);
}
