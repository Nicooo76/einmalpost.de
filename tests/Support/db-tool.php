<?php

/**
 * Zugriff auf die Testdatenbank aus den Browsertests heraus.
 *
 * Playwright läuft in Node und kann nicht selbst mit MariaDB sprechen. Statt
 * dafür einen Testendpunkt in den Dienst einzubauen - der dann in der
 * Produktion mitliefe - ruft der Browsertest dieses Skript als Prozess auf.
 *
 * Aufrufe:
 *   php db-tool.php zaehle                 Anzahl Zeilen in secrets
 *   php db-tool.php existiert <id>         1, wenn die Zeile noch da ist, sonst 0
 *   php db-tool.php suche <text>           Sucht den Text in allen payloads
 *   php db-tool.php laenge <id>            Länge des payload in Byte
 *   php db-tool.php kippe-bit <id>         Kippt ein Bit im Tag (letztes Byte)
 *   php db-tool.php kippe-iv <id>          Kippt ein Bit im IV (erstes Byte)
 *   php db-tool.php verfallen <id>         Setzt expires_at in die Vergangenheit
 *   php db-tool.php leere                  Leert beide Tabellen
 */

declare(strict_types=1);

require_once __DIR__ . '/../../vendor/autoload.php';

use Einmalpost\Base64Url;
use Einmalpost\Config;
use Einmalpost\Database;

$befehl = $argv[1] ?? '';
$wert   = $argv[2] ?? '';

$konfig = getenv('EINMALPOST_CONFIG');
$pdo    = (new Database(Config::load(is_string($konfig) && $konfig !== '' ? $konfig : null)))->pdo();

function rohId(string $base64url): string
{
    $roh = Base64Url::decode($base64url);

    if ($roh === null) {
        fwrite(STDERR, 'unbrauchbare ID' . PHP_EOL);

        exit(2);
    }

    return $roh;
}

switch ($befehl) {
    case 'zaehle':
        $anweisung = $pdo->prepare('SELECT COUNT(*) FROM secrets');
        $anweisung->execute();

        echo (int) $anweisung->fetchColumn();

        break;

    case 'existiert':
        // Gezielt statt global: Die Browsertests laufen nebenläufig, eine
        // Gesamtzahl wäre von fremden Testfällen verfälscht.
        $anweisung = $pdo->prepare('SELECT COUNT(*) FROM secrets WHERE id = ?');
        $anweisung->bindValue(1, rohId($wert), PDO::PARAM_LOB);
        $anweisung->execute();

        echo (int) $anweisung->fetchColumn();

        break;

    case 'suche':
        // Sucht den Text in allen gespeicherten payloads. Findet er sich
        // dort, läge der Klartext in der Datenbank.
        $anweisung = $pdo->prepare('SELECT COUNT(*) FROM secrets WHERE LOCATE(?, payload) > 0');
        $anweisung->bindValue(1, $wert, PDO::PARAM_LOB);
        $anweisung->execute();

        echo (int) $anweisung->fetchColumn();

        break;

    case 'laenge':
        $anweisung = $pdo->prepare('SELECT LENGTH(payload) FROM secrets WHERE id = ?');
        $anweisung->bindValue(1, rohId($wert), PDO::PARAM_LOB);
        $anweisung->execute();

        echo (int) $anweisung->fetchColumn();

        break;

    case 'kippe-bit':
        // Kippt das niederwertigste Bit im letzten Byte - also im
        // Authentifizierungs-Tag.
        $anweisung = $pdo->prepare(
            'UPDATE secrets SET payload = CONCAT('
            . '  LEFT(payload, LENGTH(payload) - 1),'
            . '  CHAR(ASCII(RIGHT(payload, 1)) ^ 1)'
            . ') WHERE id = ?'
        );
        $anweisung->bindValue(1, rohId($wert), PDO::PARAM_LOB);
        $anweisung->execute();

        echo $anweisung->rowCount();

        break;

    case 'kippe-iv':
        // Das erste Byte gehört zum IV. Ein verändertes IV führt zu einem
        // anderen Schlüsselstrom - die Prüfung des Tags muss fehlschlagen.
        $anweisung = $pdo->prepare(
            'UPDATE secrets SET payload = CONCAT('
            . '  CHAR(ASCII(LEFT(payload, 1)) ^ 1),'
            . '  SUBSTRING(payload, 2)'
            . ') WHERE id = ?'
        );
        $anweisung->bindValue(1, rohId($wert), PDO::PARAM_LOB);
        $anweisung->execute();

        echo $anweisung->rowCount();

        break;

    case 'verfallen':
        $anweisung = $pdo->prepare(
            'UPDATE secrets SET expires_at = DATE_ADD(UTC_TIMESTAMP(), INTERVAL -1 SECOND) WHERE id = ?'
        );
        $anweisung->bindValue(1, rohId($wert), PDO::PARAM_LOB);
        $anweisung->execute();

        echo $anweisung->rowCount();

        break;

    case 'leere':
        $pdo->exec('DELETE FROM secrets');
        $pdo->exec('DELETE FROM rate_limits');

        echo 'ok';

        break;

    default:
        fwrite(STDERR, 'Unbekannter Befehl: ' . $befehl . PHP_EOL);

        exit(1);
}
