<?php

declare(strict_types=1);

namespace Einmalpost\Tests\Integration;

use Einmalpost\Base64Url;
use Einmalpost\SecretStore;

/**
 * Zusage 3: Ein Geheimnis wird höchstens einmal ausgeliefert, auch bei
 * gleichzeitigen Anfragen.
 *
 * Geprüft wird mit echten, gleichzeitig laufenden Prozessen. Mehrere
 * Verbindungen in einem einzigen PHP-Prozess würden nacheinander abgefragt
 * und wären damit kein Beweis.
 *
 * Der Anlass ist nicht theoretisch: Mail-Gateways prüfen Links regelmäßig
 * mehrfach und parallel.
 */
final class NebenlaeufigkeitTest extends IntegrationTestCase
{
    private const PROZESSE   = 8;
    private const DURCHGANGE = 10;

    public function testGleichzeitigeAbrufeLiefernGenauEinmalAus(): void
    {
        $store = new SecretStore($this->database());

        for ($durchgang = 1; $durchgang <= self::DURCHGANGE; $durchgang++) {
            $payload = random_bytes(284);
            $id      = Base64Url::encode($store->create($payload, 3600));

            $ergebnisse = $this->starteGleichzeitig('consume-worker.php', $id);

            $treffer = array_values(array_filter($ergebnisse, static fn (string $z): bool => str_starts_with($z, 'HIT')));
            $fehler  = array_values(array_filter($ergebnisse, static fn (string $z): bool => str_starts_with($z, 'ERROR')));

            self::assertSame([], $fehler, 'Durchgang ' . $durchgang . ': ' . implode(' | ', $fehler));

            self::assertCount(
                1,
                $treffer,
                sprintf(
                    'Durchgang %d: %d von %d gleichzeitigen Abrufen bekamen das Geheimnis. '
                    . 'Erlaubt ist genau einer.',
                    $durchgang,
                    count($treffer),
                    self::PROZESSE
                )
            );

            self::assertSame(
                'HIT ' . hash('sha256', $payload),
                $treffer[0],
                'Der eine Treffer muss auch der richtige payload sein.'
            );

            self::assertSame(0, $this->zeilen(), 'Nach dem Durchgang ist die Zeile weg.');
        }
    }

    /**
     * Gegenprobe: Würde dieser Aufbau eine Mehrfachauslieferung überhaupt
     * bemerken?
     *
     * Dieselben gleichzeitigen Prozesse, aber mit einem absichtlich nicht
     * atomaren Arbeiter - SELECT, Pause, DELETE. Wenn hier nicht mehrere
     * Prozesse gleichzeitig zum Zug kommen, wäre der Test darüber wertlos,
     * weil er auch bei kaputtem Code grün bliebe.
     */
    public function testDerAufbauWuerdeMehrfachauslieferungBemerken(): void
    {
        $store   = new SecretStore($this->database());
        $payload = random_bytes(284);
        $id      = Base64Url::encode($store->create($payload, 3600));

        $ergebnisse = $this->starteGleichzeitig('consume-worker-naiv.php', $id);

        $treffer = array_filter($ergebnisse, static fn (string $z): bool => str_starts_with($z, 'HIT'));

        self::assertGreaterThan(
            1,
            count($treffer),
            'Der nicht atomare Arbeiter müsste das Geheimnis mehrfach ausliefern. '
            . 'Tut er das nicht, laufen die Prozesse nicht wirklich gleichzeitig - '
            . 'und dann beweist der Nebenläufigkeitstest nichts.'
        );
    }

    /**
     * Startet mehrere Prozesse, die sich auf denselben Zeitpunkt verabreden.
     *
     * @return list<string>
     */
    private function starteGleichzeitig(string $skript, string $id): array
    {
        $pfad = dirname(__DIR__) . '/Support/' . $skript;

        // Vorlauf, damit alle Prozesse hochgefahren sind, bevor der
        // verabredete Zeitpunkt erreicht ist.
        $startAt = microtime(true) + 0.6;

        $prozesse = [];
        $rohre    = [];

        for ($i = 0; $i < self::PROZESSE; $i++) {
            $deskriptoren = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];

            $prozess = proc_open(
                [PHP_BINARY, $pfad, $id, sprintf('%.6F', $startAt)],
                $deskriptoren,
                $rohrePaar
            );

            self::assertIsResource($prozess, 'Prozess ließ sich nicht starten.');

            $prozesse[$i] = $prozess;
            $rohre[$i]    = $rohrePaar;
        }

        $ergebnisse = [];

        foreach ($prozesse as $i => $prozess) {
            $ausgabe = stream_get_contents($rohre[$i][1]);
            $fehler  = stream_get_contents($rohre[$i][2]);
            fclose($rohre[$i][1]);
            fclose($rohre[$i][2]);
            proc_close($prozess);

            self::assertSame('', trim((string) $fehler), 'Der Arbeiter meldete auf stderr: ' . $fehler);

            $ergebnisse[] = trim((string) $ausgabe);
        }

        return $ergebnisse;
    }

    private function zeilen(): int
    {
        return (int) $this->query('SELECT COUNT(*) FROM secrets')->fetchColumn();
    }
}
