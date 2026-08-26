<?php

/**
 * Einziger Einstiegspunkt.
 *
 * Dient zugleich als Router für den eingebauten PHP-Server in den
 * Browsertests: Vorhandene Dateien unter public/ liefert der Server selbst
 * aus, alles andere landet hier.
 */

declare(strict_types=1);

use Einmalpost\Application;
use Einmalpost\Http\Request;
use Einmalpost\Http\Response;
use Einmalpost\Http\SecurityHeaders;

// Beim eingebauten Server: vorhandene Dateien direkt ausliefern.
if (PHP_SAPI === 'cli-server') {
    $angefragt = $_SERVER['REQUEST_URI'] ?? '/';
    $pfad      = parse_url(is_string($angefragt) ? $angefragt : '/', PHP_URL_PATH);
    $datei     = __DIR__ . (is_string($pfad) ? $pfad : '/');

    if (is_file($datei)) {
        return false;
    }
}

require_once dirname(__DIR__) . '/src/autoload.php';

// Nichts von dem, was hier schiefgehen kann, darf als Text in einer Antwort
// landen: Eine PHP-Meldung könnte Pfade oder Zugangsdaten enthalten.
ini_set('display_errors', '0');
ini_set('log_errors', '1');

/**
 * Warnungen und Hinweise werden zu Ausnahmen. Sonst könnte eine Warnung
 * mitten in eine Antwort geraten und sie unbrauchbar machen.
 */
set_error_handler(static function (int $stufe, string $meldung, string $datei, int $zeile): bool {
    if ((error_reporting() & $stufe) === 0) {
        return false;
    }

    throw new ErrorException($meldung, 0, $stufe, $datei, $zeile);
});

try {
    $anwendung = Application::boot();

    $anfrage = Request::fromGlobals($_SERVER, (string) file_get_contents('php://input'));
    $antwort = $anwendung->router->dispatch($anfrage);
} catch (Throwable $fehler) {
    // Kein Detail nach außen - aber sehr wohl eines nach innen. Ein Fehler,
    // der nirgends steht, ist nicht zu finden: Genau das ist beim ersten
    // großen Anhang passiert.
    //
    // Protokolliert werden Klasse, Meldung und Ort. Keine Nutzdaten: Die
    // Meldung einer Ausnahme kann eine Eingabe enthalten, deshalb wird sie
    // auf eine Länge gekürzt, in die kein Geheimnis passt.
    error_log(sprintf(
        'einmalpost: %s in %s:%d - %s',
        $fehler::class,
        basename($fehler->getFile()),
        $fehler->getLine(),
        substr($fehler->getMessage(), 0, 200)
    ));

    $antwort = new Response(
        500,
        '{"fehler":"serverfehler"}',
        ['Content-Type' => 'application/json; charset=utf-8'] + SecurityHeaders::forApi()
    );
}

$antwort->send();
