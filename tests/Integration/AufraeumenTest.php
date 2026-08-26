<?php

declare(strict_types=1);

namespace Einmalpost\Tests\Integration;

use Einmalpost\Base64Url;
use Einmalpost\SecretStore;
use PDO;

/**
 * Zusage 10: Der Aufräum-Cron löscht abgelaufene Zeilen; bei abgeschaltetem
 * Cron übernimmt das MariaDB-Event.
 *
 * Beide Wege werden einzeln geprüft, und zwar so, wie sie im Betrieb laufen:
 * der Cron als echter Prozessaufruf, das Event als echtes Event in der
 * Datenbank.
 */
final class AufraeumenTest extends IntegrationTestCase
{
    // ------------------------------------------------------------------
    // Weg 1: der Cron
    // ------------------------------------------------------------------

    public function testDasAufraeumSkriptLoeschtAbgelaufeneZeilen(): void
    {
        $store = new SecretStore($this->database());

        $frisch = $store->create('bleibt', 3600);
        $alt    = $store->create('geht weg', 3600);
        $this->verfallen($alt);

        $ergebnis = $this->rufeAufraeumSkriptAuf();

        self::assertSame(0, $ergebnis['code'], 'Das Skript endete mit Fehler: ' . $ergebnis['stderr']);
        self::assertStringContainsString('entfernt', $ergebnis['stdout']);

        self::assertSame(1, $this->zeilen('secrets'), 'Nur die abgelaufene Zeile darf weg sein.');
        self::assertSame('bleibt', $store->consume(Base64Url::encode($frisch)));
    }

    public function testDasAufraeumSkriptRaeumtAuchAbgelaufeneRateLimitsWeg(): void
    {
        $this->pdo()->exec(
            'INSERT INTO rate_limits (ip_hmac, hits, expires_at) '
            . "VALUES (UNHEX(REPEAT('AB', 32)), 5, DATE_ADD(UTC_TIMESTAMP(), INTERVAL -1 SECOND))"
        );

        $this->rufeAufraeumSkriptAuf();

        self::assertSame(0, $this->zeilen('rate_limits'));
    }

    public function testDasAufraeumSkriptGibtKeineEinzelheitenAus(): void
    {
        $store = new SecretStore($this->database());
        $id    = $store->create('geheimer inhalt', 3600);
        $this->verfallen($id);

        $ergebnis = $this->rufeAufraeumSkriptAuf();

        // Was aufgeräumt wurde, geht niemanden etwas an - auch kein
        // Protokoll. Die Ausgabe enthält nur eine Anzahl.
        self::assertStringNotContainsString('geheimer inhalt', $ergebnis['stdout']);
        self::assertStringNotContainsString(bin2hex($id), $ergebnis['stdout']);
        self::assertStringNotContainsString(Base64Url::encode($id), $ergebnis['stdout']);
    }

    // ------------------------------------------------------------------
    // Weg 2: das MariaDB-Event
    // ------------------------------------------------------------------

    public function testDasEventIstAngelegt(): void
    {
        $anweisung = $this->prepared(
            'SELECT EVENT_NAME, STATUS, EVENT_TYPE, INTERVAL_VALUE, INTERVAL_FIELD '
            . 'FROM information_schema.EVENTS WHERE EVENT_SCHEMA = DATABASE() AND EVENT_NAME = ?'
        );
        $anweisung->execute(['einmalpost_aufraeumen']);

        /** @var array<string, mixed>|false $event */
        $event = $anweisung->fetch();

        self::assertIsArray($event, 'Das Event aus db/event.sql fehlt in der Datenbank.');
        self::assertSame('ENABLED', self::alsText($event['STATUS']));
        self::assertSame('RECURRING', self::alsText($event['EVENT_TYPE']));
        self::assertSame('MINUTE', self::alsText($event['INTERVAL_FIELD']));
    }

    /**
     * Ein angelegtes Event, das nicht läuft, ist kein zweites Netz.
     */
    public function testDerEventSchedulerLaeuft(): void
    {
        $zustand = self::alsText($this->query('SELECT @@event_scheduler')->fetchColumn());

        self::assertSame(
            'ON',
            $zustand,
            'Der Event-Scheduler steht auf "' . $zustand . '". Solange er nicht ON ist, läuft das '
            . 'Event nie, und es gibt nur ein Netz statt zwei. Einschalten: in der my.cnf '
            . '"event_scheduler = ON" setzen (serverweit, braucht Freigabe), für den Testlauf '
            . 'genügt "SET GLOBAL event_scheduler = ON".'
        );
    }

    /**
     * Der Nachweis, dass Events auf dieser Instanz tatsächlich ausgeführt
     * werden - nicht nur, dass eines eingetragen ist.
     */
    public function testEinEventRaeumtTatsaechlichAuf(): void
    {
        $store = new SecretStore($this->database());
        $alt   = $store->create('wird vom Event geholt', 3600);
        $this->verfallen($alt);

        self::assertGreaterThan(0, $this->zeilen('secrets'), 'Die abgelaufene Zeile liegt noch da.');

        $this->pdo()->exec('DROP EVENT IF EXISTS einmalpost_probe');
        $this->pdo()->exec(
            'CREATE EVENT einmalpost_probe ON SCHEDULE AT CURRENT_TIMESTAMP + INTERVAL 1 SECOND '
            . 'DO DELETE FROM secrets WHERE expires_at <= UTC_TIMESTAMP()'
        );

        $verschwunden = false;

        // Bis zu zehn Sekunden warten. Der Scheduler prüft im Sekundentakt.
        for ($i = 0; $i < 100; $i++) {
            usleep(100_000);

            if ($this->zeilen('secrets') === 0) {
                $verschwunden = true;

                break;
            }
        }

        $this->pdo()->exec('DROP EVENT IF EXISTS einmalpost_probe');

        self::assertTrue(
            $verschwunden,
            'Ein Event mit Ausführungszeitpunkt "jetzt" hat die abgelaufene Zeile nicht entfernt. '
            . 'Damit ist das zweite Netz auf dieser Instanz wirkungslos.'
        );
    }

    /**
     * Auch wenn beide Aufräumwege ausfallen, wird nichts Abgelaufenes
     * ausgeliefert. Das ist das dritte, unabhängige Netz.
     */
    public function testOhneJedesAufraeumenBleibtAbgelaufenesUnlesbar(): void
    {
        $store = new SecretStore($this->database());
        $id    = $store->create('darf niemand mehr sehen', 3600);
        $this->verfallen($id);

        self::assertSame(1, $this->zeilen('secrets'), 'Es wurde bewusst nicht aufgeräumt.');
        self::assertNull($store->consume(Base64Url::encode($id)));
    }

    // ------------------------------------------------------------------
    // Hilfsmittel
    // ------------------------------------------------------------------

    /**
     * @return array{code: int, stdout: string, stderr: string}
     */
    private function rufeAufraeumSkriptAuf(): array
    {
        $skript = dirname(__DIR__, 2) . '/bin/cleanup.php';

        // Das Skript liest seine Konfiguration selbst. Damit es die
        // Testdatenbank trifft und nicht die Entwicklungsdatenbank, wird
        // ihm ein eigener Konfigurationspfad untergeschoben.
        $konfig = $this->schreibeTestKonfiguration();

        $deskriptoren = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];

        $prozess = proc_open(
            [PHP_BINARY, $skript],
            $deskriptoren,
            $rohre,
            null,
            ['EINMALPOST_CONFIG' => $konfig, 'PATH' => (string) getenv('PATH')]
        );

        self::assertIsResource($prozess);

        $stdout = (string) stream_get_contents($rohre[1]);
        $stderr = (string) stream_get_contents($rohre[2]);
        fclose($rohre[1]);
        fclose($rohre[2]);
        $code = proc_close($prozess);

        unlink($konfig);

        return ['code' => $code, 'stdout' => $stdout, 'stderr' => $stderr];
    }

    /**
     * Legt eine Konfigurationsdatei für den Kindprozess an, damit
     * Config::load() die Testdatenbank trifft und nicht die
     * Entwicklungsdatenbank.
     */
    private function schreibeTestKonfiguration(): string
    {
        $config = self::config();

        $datei = tempnam(sys_get_temp_dir(), 'einmalpost-test-') . '.php';

        $inhalt = sprintf(
            "<?php return [ 'dsn' => %s, 'db_user' => %s, 'db_password' => %s, "
            . "'rate_pepper' => %s, 'rate_max' => %d ];",
            var_export($config->dsn, true),
            var_export($config->dbUser, true),
            var_export($config->dbPassword, true),
            var_export(base64_encode($config->ratePepper), true),
            $config->rateMax,
        );

        file_put_contents($datei, $inhalt);

        return $datei;
    }

    private function verfallen(string $id): void
    {
        $anweisung = $this->prepared(
            'UPDATE secrets SET expires_at = DATE_ADD(UTC_TIMESTAMP(), INTERVAL -1 SECOND) WHERE id = ?'
        );
        $anweisung->bindValue(1, $id, PDO::PARAM_LOB);
        $anweisung->execute();
    }

    private function zeilen(string $tabelle): int
    {
        return (int) $this->query('SELECT COUNT(*) FROM ' . $tabelle)->fetchColumn();
    }
}
