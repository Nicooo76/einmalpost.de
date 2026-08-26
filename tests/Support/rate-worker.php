<?php

/**
 * Arbeitsskript für die Nebenläufigkeitsprobe des Rate-Limits.
 *
 * Ruft allow() genau einmal für dieselbe Adresse auf, verabredet auf einen
 * gemeinsamen Startzeitpunkt. Gibt "ALLOW" oder "DENY" aus.
 *
 * Aufruf: php rate-worker.php <max> <ip> <startzeit-float>
 */

declare(strict_types=1);

require_once __DIR__ . '/../../vendor/autoload.php';

use Einmalpost\Config;
use Einmalpost\Database;
use Einmalpost\RateLimiter;

$max     = (int) ($argv[1] ?? '5');
$ip      = (string) ($argv[2] ?? '203.0.113.99');
$startAt = (float) ($argv[3] ?? '0');

$config = Config::fromArray([
    'dsn'         => (string) getenv('EINMALPOST_TEST_DSN'),
    'db_user'     => (string) getenv('EINMALPOST_TEST_DB_USER'),
    'db_password' => (string) getenv('EINMALPOST_TEST_DB_PASSWORD'),
    'rate_pepper' => base64_encode(str_repeat('T', 32)),
    'rate_max'    => (string) $max,
]);

$limiter = new RateLimiter(new Database($config), base64_decode(base64_encode(str_repeat('T', 32))), $max);

// Verbindung vorher aufbauen, damit der Verbindungsaufbau nicht ins Zeitfenster fällt.
(new Database($config))->pdo();

try {
    while (microtime(true) < $startAt) {
        // aktiv warten
    }

    echo $limiter->allow($ip) ? 'ALLOW' : 'DENY';
} catch (\Throwable $fehler) {
    echo 'ERROR ' . $fehler::class . ' ' . $fehler->getMessage();
}
