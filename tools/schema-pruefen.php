#!/usr/bin/env php
<?php

/**
 * Vergleicht das Schema einer laufenden Datenbank mit dem Soll.
 *
 * Der Anlass ist ein echter Fehler: Ein Deploy spielt Dateien hoch, ändert
 * aber keine Tabellen. Nach der Umstellung auf Anhänge bis 16 MB stand in
 * der Produktion noch die alte Spalte - und der erste große Anhang scheiterte
 * mit einem Serverfehler, den niemand sah, weil er auch nirgends
 * protokolliert wurde.
 *
 * Aufruf auf dem Server:
 *   php tools/schema-pruefen.php
 *
 * Liest die Konfiguration wie der Dienst selbst (config/config.php oder
 * EINMALPOST_CONFIG). Rückgabewert 1, wenn etwas abweicht.
 */

declare(strict_types=1);

require_once dirname(__DIR__) . '/src/autoload.php';

use Einmalpost\Config;
use Einmalpost\Database;
use Einmalpost\SecretStore;

/** Das Soll. Muss zu db/schema.sql und zum Code passen. */
const SOLL_SPALTEN = [
    'secrets' => [
        'id'         => 'binary(16)',
        'payload'    => 'longblob',
        'expires_at' => 'datetime',
    ],
    'rate_limits' => [
        'ip_hmac'    => 'binary(32)',
        'hits'       => 'int(10) unsigned',
        'expires_at' => 'datetime',
    ],
];

const SOLL_CONSTRAINT = 'payload_hoechstens_16m';

try {
    $pdo = (new Database(Config::load()))->pdo();
} catch (Throwable $fehler) {
    fwrite(STDERR, 'Keine Verbindung zur Datenbank: ' . $fehler::class . PHP_EOL);

    exit(2);
}

$abweichungen = [];

// --- Tabellen und Spalten -------------------------------------------------

$anweisung = $pdo->query(
    'SELECT TABLE_NAME, COLUMN_NAME, COLUMN_TYPE FROM information_schema.COLUMNS '
    . 'WHERE TABLE_SCHEMA = DATABASE() ORDER BY TABLE_NAME, ORDINAL_POSITION'
);

$ist = [];

foreach ($anweisung === false ? [] : $anweisung->fetchAll() as $zeile) {
    $ist[(string) $zeile['TABLE_NAME']][(string) $zeile['COLUMN_NAME']] = (string) $zeile['COLUMN_TYPE'];
}

foreach (SOLL_SPALTEN as $tabelle => $spalten) {
    if (!isset($ist[$tabelle])) {
        $abweichungen[] = sprintf('Tabelle %s fehlt.', $tabelle);

        continue;
    }

    foreach ($spalten as $name => $typ) {
        $vorhanden = $ist[$tabelle][$name] ?? null;

        if ($vorhanden === null) {
            $abweichungen[] = sprintf('%s.%s fehlt.', $tabelle, $name);

            continue;
        }

        if ($vorhanden !== $typ) {
            $abweichungen[] = sprintf(
                '%s.%s ist %s, erwartet wird %s.',
                $tabelle,
                $name,
                $vorhanden,
                $typ
            );
        }
    }

    $zuviel = array_diff(array_keys($ist[$tabelle]), array_keys($spalten));

    foreach ($zuviel as $name) {
        $abweichungen[] = sprintf(
            '%s.%s ist da, gehört aber nicht ins Schema. Jede zusätzliche Spalte ist ein '
            . 'Datum, das jemand herausverlangen kann.',
            $tabelle,
            $name
        );
    }
}

$fremd = array_diff(array_keys($ist), array_keys(SOLL_SPALTEN));

foreach ($fremd as $tabelle) {
    $abweichungen[] = sprintf('Tabelle %s gehört nicht in dieses Schema.', $tabelle);
}

// --- Die Größengrenze -----------------------------------------------------

$check = $pdo->query(
    'SELECT CHECK_CLAUSE FROM information_schema.CHECK_CONSTRAINTS '
    . "WHERE CONSTRAINT_SCHEMA = DATABASE() AND CONSTRAINT_NAME = '" . SOLL_CONSTRAINT . "'"
);

$klausel = $check === false ? false : $check->fetchColumn();

if (!is_string($klausel)) {
    $abweichungen[] = sprintf(
        'Der CHECK-Constraint %s fehlt. Migration db/migrationen/ ausstehend?',
        SOLL_CONSTRAINT
    );
} elseif (!str_contains($klausel, (string) SecretStore::PAYLOAD_MAX_BYTES)) {
    $abweichungen[] = sprintf(
        'Der CHECK nennt eine andere Grenze als der Code (%d): %s',
        SecretStore::PAYLOAD_MAX_BYTES,
        $klausel
    );
}

// --- Passt das Netzprotokoll zur Größengrenze? -----------------------------

// Ein payload dieser Größe muss durch eine einzelne MariaDB-Anweisung passen.
// Der Standardwert von max_allowed_packet ist 16 MiB - das läge nur knapp über
// dem größten möglichen payload und ließe keinen Raum für den Protokollrahmen.
// Reißt die Grenze, scheitert erst das Schreiben eines großen Anhangs, und
// zwar in der Produktion und bei einem echten Nutzer.
$paket = $pdo->query('SELECT @@max_allowed_packet');
$erlaubt = $paket === false ? 0 : (int) $paket->fetchColumn();

// Der payload plus der Rahmen der Anweisung. 64 KB sind großzügig gerechnet -
// die Anweisung selbst ist kurz, und der payload geht als Parameter mit.
$noetig = SecretStore::PAYLOAD_MAX_BYTES + 65_536;

if ($erlaubt < $noetig) {
    $abweichungen[] = sprintf(
        'max_allowed_packet ist %d Byte (%.1f MB). Für einen payload von bis zu %d Byte '
        . 'werden mindestens %d Byte gebraucht. Ein großer Anhang ließe sich nicht speichern.',
        $erlaubt,
        $erlaubt / 1_048_576,
        SecretStore::PAYLOAD_MAX_BYTES,
        $noetig
    );
}

// --- Ergebnis -------------------------------------------------------------

if ($abweichungen === []) {
    echo 'Das Schema entspricht dem Stand des Repositorys.' . PHP_EOL;

    exit(0);
}

echo 'Das Schema weicht ab:' . PHP_EOL;

foreach ($abweichungen as $eintrag) {
    echo '  - ' . $eintrag . PHP_EOL;
}

echo PHP_EOL
    . 'Ein Deploy spielt Dateien hoch, aber ändert keine Tabellen. Die passende' . PHP_EOL
    . 'Migration liegt in db/migrationen/ und wird von Hand eingespielt:' . PHP_EOL . PHP_EOL
    . '  ( echo "USE <datenbank>;"; cat db/migrationen/<datei>.sql ) | plesk db' . PHP_EOL;

exit(1);
