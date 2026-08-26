<?php

/**
 * Arbeitsskript für den Nebenläufigkeitstest.
 *
 * Wird in mehreren Prozessen gleichzeitig gestartet und versucht, dasselbe
 * Geheimnis abzurufen. Alle Prozesse warten auf denselben Startzeitpunkt,
 * damit sie tatsächlich gleichzeitig zugreifen und nicht nacheinander.
 *
 * Ausgabe: "HIT <sha256 des payload>" oder "MISS" oder "ERROR <klasse>".
 *
 * Aufruf: php consume-worker.php <id-base64url> <startzeit-als-float>
 */

declare(strict_types=1);

require_once __DIR__ . '/../../vendor/autoload.php';

use Einmalpost\Config;
use Einmalpost\Database;
use Einmalpost\SecretStore;

$idEncoded = $argv[1] ?? '';
$startAt   = (float) ($argv[2] ?? '0');

$config = Config::fromArray([
    'dsn'         => (string) getenv('EINMALPOST_TEST_DSN'),
    'db_user'     => (string) getenv('EINMALPOST_TEST_DB_USER'),
    'db_password' => (string) getenv('EINMALPOST_TEST_DB_PASSWORD'),
    'rate_pepper' => base64_encode(str_repeat('T', 32)),
    'rate_max'    => '1000',
]);

$store = new SecretStore(new Database($config));

// Verbindung vorher aufbauen, damit der Verbindungsaufbau nicht in das
// Zeitfenster fällt, um das es hier geht.
$config = null;

try {
    // Aktives Warten. Schlafen wäre zu ungenau für ein Fenster, das nur
    // Millisekunden breit ist.
    while (microtime(true) < $startAt) {
        // absichtlich leer
    }

    $payload = $store->consume($idEncoded);

    echo $payload === null ? 'MISS' : 'HIT ' . hash('sha256', $payload);
} catch (\Throwable $fehler) {
    echo 'ERROR ' . $fehler::class . ' ' . $fehler->getMessage();
}
