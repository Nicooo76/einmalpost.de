#!/usr/bin/env php
<?php

/**
 * Prüft die laufende Produktion.
 *
 * Getrennt von "make verify", weil sich diese Dinge nur an einem echten
 * Server prüfen lassen: die tatsächlich ausgelieferten Kopfzeilen und die
 * Frage, ob Strict-Transport-Security genau einmal ankommt.
 *
 * Ein grüner Lauf ist die Voraussetzung dafür, die Domain bei
 * hstspreload.org anzumelden.
 *
 * Aufruf: make verify-live LIVE_URL=https://einmalpost.de
 */

declare(strict_types=1);

/**
 * Sammelt Prüfungen und ihr Ergebnis.
 */
final class LivePruefung
{
    private int $geprueft = 0;

    private int $beanstandet = 0;

    public function abschnitt(string $titel): void
    {
        printf('%s%s%s', PHP_EOL, $titel, PHP_EOL);
    }

    public function pruefe(string $was, bool $bedingung, string $hinweis = ''): void
    {
        ++$this->geprueft;

        if ($bedingung) {
            printf('  ok    %s%s', $was, PHP_EOL);

            return;
        }

        ++$this->beanstandet;
        printf('  FEHLT %s%s', $was, PHP_EOL);

        if ($hinweis !== '') {
            printf('        %s%s', $hinweis, PHP_EOL);
        }
    }

    public function bericht(): int
    {
        printf('%s%d Prüfungen, %d Beanstandungen.%s', PHP_EOL, $this->geprueft, $this->beanstandet, PHP_EOL);

        if ($this->beanstandet > 0) {
            echo PHP_EOL
                . 'Solange etwas beanstandet wird, darf die Domain NICHT bei hstspreload.org' . PHP_EOL
                . 'angemeldet werden - eine Preload-Eintragung ist praktisch nicht zurückzunehmen.' . PHP_EOL;

            return 1;
        }

        echo PHP_EOL
            . 'Alles in Ordnung. Das Zugriffsprotokoll des vhosts lässt sich von außen nicht' . PHP_EOL
            . 'prüfen - dieser Schritt bleibt der Kontrolle auf dem Server vorbehalten (README).' . PHP_EOL;

        return 0;
    }

    /**
     * Holt die rohen Kopfzeilen, ohne Weiterleitungen zu folgen.
     *
     * Bewusst roh und nicht nach Namen gebündelt: Nur so lässt sich zählen,
     * ob eine Kopfzeile mehrfach ankommt.
     *
     * @return list<string>
     */
    public function kopfzeilen(string $adresse, string $methode = 'GET'): array
    {
        $kontext = stream_context_create([
            'http' => [
                'method'          => $methode,
                'follow_location' => 0,
                'ignore_errors'   => true,
                'timeout'         => 15,
                'header'          => "User-Agent: einmalpost-verify-live\r\n",
            ],
            'ssl'  => ['verify_peer' => true, 'verify_peer_name' => true],
        ]);

        $roh = @get_headers($adresse, false, $kontext);

        if ($roh === false) {
            return [];
        }

        $zeilen = [];

        foreach ($roh as $zeile) {
            if (is_string($zeile)) {
                $zeilen[] = $zeile;
            }
        }

        return $zeilen;
    }

    /**
     * Alle Werte einer Kopfzeile, in der Reihenfolge ihres Auftretens.
     *
     * @param list<string> $kopfzeilen
     *
     * @return list<string>
     */
    public function werte(array $kopfzeilen, string $name): array
    {
        $gefunden = [];

        foreach ($kopfzeilen as $zeile) {
            if (stripos($zeile, $name . ':') === 0) {
                $gefunden[] = trim(substr($zeile, strlen($name) + 1));
            }
        }

        return $gefunden;
    }
}

$basis = rtrim((string) ($argv[1] ?? ''), '/');

if ($basis === '') {
    fwrite(STDERR, 'Aufruf: php tools/verify-live.php https://einmalpost.de' . PHP_EOL);

    exit(1);
}

$p = new LivePruefung();

printf('Prüfe %s%s', $basis, PHP_EOL);

// ---------------------------------------------------------------------
$p->abschnitt('Startseite');

$kopf = $p->kopfzeilen($basis . '/');
$p->pruefe('erreichbar', $kopf !== [], 'Der Server hat nicht geantwortet.');

$hsts = $p->werte($kopf, 'Strict-Transport-Security');

$p->pruefe(
    sprintf('Strict-Transport-Security kommt genau einmal an (gezählt: %d)', count($hsts)),
    count($hsts) === 1,
    count($hsts) === 0
        ? 'HSTS fehlt. In Plesk aktivieren - nicht im PHP-Code setzen.'
        : 'HSTS kommt mehrfach an. Wahrscheinlich setzen nginx UND PHP die Kopfzeile.'
);

if (count($hsts) === 1) {
    // Schwelle statt exaktem Vergleich: Die Dauer wird schrittweise
    // angehoben, und der Lauf muss das aushalten, ohne rot zu werden.
    $dauer = [];
    $p->pruefe(
        'HSTS mit ausreichender Dauer (mindestens 15.000.000 Sekunden)',
        preg_match('/max-age=(\d+)/', $hsts[0], $dauer) === 1 && (int) $dauer[1] >= 15_000_000,
        'Kürzere Fristen taugen nicht als Schutz und reichen für eine Preload-Eintragung nicht.'
    );
    $p->pruefe('HSTS mit includeSubDomains', str_contains($hsts[0], 'includeSubDomains'));
    // "preload" in der Kopfzeile ist Voraussetzung für die Anmeldung, aber
    // nicht die Anmeldung selbst. Die ist ein bewusster, kaum umkehrbarer
    // Schritt und findet getrennt statt.
    $p->pruefe('HSTS trägt preload (die Anmeldung selbst erfolgt getrennt)', str_contains($hsts[0], 'preload'));
}

$csp = $p->werte($kopf, 'Content-Security-Policy');
$p->pruefe('Content-Security-Policy kommt genau einmal an', count($csp) === 1);

if (count($csp) === 1) {
    $p->pruefe("CSP mit 'strict-dynamic'", str_contains($csp[0], "'strict-dynamic'"));
    $p->pruefe("CSP mit object-src 'none'", str_contains($csp[0], "object-src 'none'"));
    $p->pruefe("CSP mit base-uri 'none'", str_contains($csp[0], "base-uri 'none'"));
    $p->pruefe('CSP mit require-trusted-types-for', str_contains($csp[0], "require-trusted-types-for 'script'"));
    $p->pruefe('CSP mit Nonce', preg_match("/'nonce-[A-Za-z0-9+\/=]{20,}'/", $csp[0]) === 1);
}

$p->pruefe('Referrer-Policy: no-referrer', $p->werte($kopf, 'Referrer-Policy') === ['no-referrer']);
$p->pruefe('X-Content-Type-Options: nosniff', $p->werte($kopf, 'X-Content-Type-Options') === ['nosniff']);
$p->pruefe('Permissions-Policy gesetzt', $p->werte($kopf, 'Permissions-Policy') !== []);

$cache = $p->werte($kopf, 'Cache-Control');
$p->pruefe(
    'Cache-Control: no-store',
    $cache !== [] && str_contains($cache[0], 'no-store'),
    'Ohne no-store könnte eine zwischengespeicherte Seite denselben Nonce erneut ausliefern.'
);

// ---------------------------------------------------------------------
$p->abschnitt('Nonce');

$zweite = $p->werte($p->kopfzeilen($basis . '/'), 'Content-Security-Policy');

$p->pruefe(
    'jede Antwort bekommt einen eigenen Nonce',
    count($csp) === 1 && count($zweite) === 1 && $csp[0] !== $zweite[0],
    'Zwei Antworten trugen denselben Nonce. Ein Nonce, der zweimal gilt, ist kein Nonce.'
);

// ---------------------------------------------------------------------
$p->abschnitt('Anzeigeseite');

$anzeige = $p->kopfzeilen($basis . '/s/AAAAAAAAAAAAAAAAAAAAAA');
$anzeigeCache = $p->werte($anzeige, 'Cache-Control');

$p->pruefe('antwortet', $anzeige !== []);
$p->pruefe('trägt eine CSP', $p->werte($anzeige, 'Content-Security-Policy') !== []);
$p->pruefe('trägt Cache-Control: no-store', $anzeigeCache !== [] && str_contains($anzeigeCache[0], 'no-store'));
$p->pruefe(
    'beantwortet auch HEAD',
    $p->kopfzeilen($basis . '/s/AAAAAAAAAAAAAAAAAAAAAA', 'HEAD') !== [],
    'Vorschau-Bots schicken oft HEAD.'
);

// ---------------------------------------------------------------------
$p->abschnitt('Unverschlüsselter Zugang');

$ort = $p->werte($p->kopfzeilen(str_replace('https://', 'http://', $basis) . '/'), 'Location');

$p->pruefe(
    'http wird auf https umgeleitet',
    $ort !== [] && str_starts_with($ort[0], 'https://'),
    'Ohne Umleitung landet ein Aufruf ohne TLS beim Server.'
);

// ---------------------------------------------------------------------
$p->abschnitt('Was nicht erreichbar sein darf');

$verboten = ['/config/config.php', '/src/Config.php', '/vendor/autoload.php', '/.git/config', '/db/schema.sql', '/composer.json'];

foreach ($verboten as $pfad) {
    $antwort = $p->kopfzeilen($basis . $pfad);
    $status  = $antwort[0] ?? '';

    $p->pruefe(
        $pfad . ' nicht ausgeliefert',
        $status === '' || preg_match('#HTTP/[\d.]+ (30[0-9]|4[0-9][0-9])#', $status) === 1,
        'Antwort: ' . $status
    );
}

exit($p->bericht());
