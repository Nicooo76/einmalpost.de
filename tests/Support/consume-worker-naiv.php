<?php

/**
 * ACHTUNG: Dieses Skript enthält absichtlich das verbotene Muster
 * "SELECT, dann separates DELETE" - mit einer künstlichen Pause dazwischen.
 *
 * Es ist kein Teil des Dienstes und wird nie ausgeliefert. Es ist die
 * Gegenprobe zum Nebenläufigkeitstest: Es zeigt, dass der Testaufbau eine
 * Mehrfachauslieferung tatsächlich bemerkt. Ohne diese Gegenprobe wäre ein
 * grüner Nebenläufigkeitstest wertlos - er könnte auch deshalb grün sein,
 * weil die Prozesse gar nicht gleichzeitig laufen.
 *
 * Der Prüfer auf verbotene Muster (Zusage 20) nimmt tests/Support
 * ausdrücklich aus und begründet das dort.
 *
 * Aufruf: php consume-worker-naiv.php <id-base64url> <startzeit-als-float>
 */

declare(strict_types=1);

require_once __DIR__ . '/../../vendor/autoload.php';

use Einmalpost\Base64Url;
use Einmalpost\Config;
use Einmalpost\Database;

$idEncoded = $argv[1] ?? '';
$startAt   = (float) ($argv[2] ?? '0');

$config = Config::fromArray([
    'dsn'         => (string) getenv('EINMALPOST_TEST_DSN'),
    'db_user'     => (string) getenv('EINMALPOST_TEST_DB_USER'),
    'db_password' => (string) getenv('EINMALPOST_TEST_DB_PASSWORD'),
    'rate_pepper' => base64_encode(str_repeat('T', 32)),
    'rate_max'    => '1000',
]);

$pdo = (new Database($config))->pdo();
$id  = Base64Url::decode($idEncoded) ?? '';

try {
    while (microtime(true) < $startAt) {
        // absichtlich leer
    }

    $lesen = $pdo->prepare('SELECT payload FROM secrets WHERE id = ? AND expires_at > UTC_TIMESTAMP()');
    $lesen->execute([$id]);
    /** @var mixed $payload */
    $payload = $lesen->fetchColumn();
    $lesen->closeCursor();

    // Genau dieses Fenster ist gemeint. Im Betrieb ist es nur Millisekunden
    // breit; hier wird es aufgezogen, damit die Gegenprobe zuverlässig ist.
    usleep(60_000);

    $loeschen = $pdo->prepare('DELETE FROM secrets WHERE id = ?');
    $loeschen->execute([$id]);

    echo is_string($payload) && $payload !== '' ? 'HIT ' . hash('sha256', $payload) : 'MISS';
} catch (\Throwable $fehler) {
    echo 'ERROR ' . $fehler::class;
}
