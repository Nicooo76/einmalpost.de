<?php

declare(strict_types=1);

namespace Einmalpost\Tests\Integration;

use Einmalpost\Base64Url;
use Einmalpost\Http\Request;
use Einmalpost\Http\Response;
use Einmalpost\Http\Router;
use Einmalpost\RateLimiter;
use Einmalpost\SecretStore;
use PDO;

/**
 * Zusage 8: "gibt es nicht", "abgelaufen" und "bereits abgerufen" sind
 * byteweise und zeitlich ununterscheidbar.
 *
 * Geprüft wird auf drei Ebenen:
 *   1. Die Antworten sind Byte für Byte gleich, samt Kopfzeilen.
 *   2. Alle drei führen dieselbe Datenbankabfrage aus - nachgezählt über
 *      den Sitzungszähler Com_delete. Ein früher Ausstieg ohne Abfrage
 *      fällt damit auf, auch wenn die Antwort gleich aussieht.
 *   3. Die Laufzeiten liegen beieinander.
 *
 * Ebene 2 ist der eigentliche Nachweis: Sie ist deterministisch, während
 * Zeitmessungen immer verrauscht sind.
 */
final class FehlerfaelleTest extends IntegrationTestCase
{
    private Router $router;

    private SecretStore $store;

    protected function setUp(): void
    {
        parent::setUp();

        $this->store  = new SecretStore($this->database());
        $this->router = new Router(
            $this->store,
            new RateLimiter($this->database(), str_repeat('T', 32), 100_000),
        );
    }

    // ------------------------------------------------------------------
    // Die drei Fälle
    // ------------------------------------------------------------------

    /**
     * @return array{unbekannt: string, abgelaufen: string, verbraucht: string}
     */
    private function dreiFaelle(): array
    {
        // 1. Gibt es nicht: gültiges Format, nie vergeben.
        $unbekannt = Base64Url::encode(random_bytes(16));

        // 2. Abgelaufen: liegt noch da, ist aber über die Zeit.
        $abgelaufenRoh = $this->store->create(random_bytes(284), 3600);
        $anweisung     = $this->prepared(
            'UPDATE secrets SET expires_at = DATE_ADD(UTC_TIMESTAMP(), INTERVAL -1 SECOND) WHERE id = ?'
        );
        $anweisung->bindValue(1, $abgelaufenRoh, PDO::PARAM_LOB);
        $anweisung->execute();

        // 3. Bereits abgerufen: war da, ist verbraucht.
        $verbrauchtRoh = $this->store->create(random_bytes(284), 3600);
        $this->store->consume(Base64Url::encode($verbrauchtRoh));

        return [
            'unbekannt'  => $unbekannt,
            'abgelaufen' => Base64Url::encode($abgelaufenRoh),
            'verbraucht' => Base64Url::encode($verbrauchtRoh),
        ];
    }

    public function testAlleDreiFaelleAntwortenByteweiseGleich(): void
    {
        $antworten = [];

        foreach ($this->dreiFaelle() as $fall => $id) {
            $antworten[$fall] = $this->abrufen($id);
        }

        $erste = null;

        foreach ($antworten as $fall => $antwort) {
            self::assertSame(404, $antwort->status, $fall . ': Status');

            $vergleich = [
                'status'  => $antwort->status,
                'body'    => $antwort->body,
                'headers' => $antwort->headers,
            ];

            if ($erste === null) {
                $erste = $vergleich;

                continue;
            }

            self::assertSame(
                $erste,
                $vergleich,
                $fall . ' antwortet anders als der erste Fall. Damit verrät die Antwort, '
                . 'ob eine ID jemals existiert hat.'
            );
        }

        self::assertSame(Router::NICHT_GEFUNDEN_BODY, $antworten['unbekannt']->body);
    }

    public function testAlleDreiFaelleFuehrenDieselbeAbfrageAus(): void
    {
        foreach ($this->dreiFaelle() as $fall => $id) {
            $vorher = $this->zaehler('Com_delete');
            $this->abrufen($id);
            $nachher = $this->zaehler('Com_delete');

            self::assertSame(
                1,
                $nachher - $vorher,
                $fall . ': Es muss genau eine DELETE-Anweisung laufen - dieselbe wie in den '
                . 'anderen Fällen. Kein früher Ausstieg, kein zusätzlicher Zweig.'
            );
        }
    }

    public function testAuchEineFormalUnbrauchbareIdLaeuftDurchDieselbeAbfrage(): void
    {
        // Sonst wäre "unbrauchbares Format" zeitlich von "unbekannte ID" zu
        // unterscheiden - und damit ein Hinweis darauf, wie IDs aussehen.
        foreach (['', 'kurz', '!!!!', str_repeat('A', 500)] as $id) {
            $vorher = $this->zaehler('Com_delete');
            $antwort = $this->abrufen($id);
            $nachher = $this->zaehler('Com_delete');

            self::assertSame(404, $antwort->status);
            self::assertSame(Router::NICHT_GEFUNDEN_BODY, $antwort->body);
            self::assertSame(1, $nachher - $vorher, 'ID ' . var_export($id, true));
        }
    }

    public function testDieLaufzeitenLiegenBeieinander(): void
    {
        $messungen = [];
        $runden    = 25;

        for ($i = 0; $i < $runden; $i++) {
            foreach ($this->dreiFaelle() as $fall => $id) {
                $start = hrtime(true);
                $this->abrufen($id);
                $messungen[$fall][] = hrtime(true) - $start;
            }
        }

        $mediane = [];

        foreach ($messungen as $fall => $werte) {
            sort($werte);
            $mediane[$fall] = $werte[intdiv(count($werte), 2)];
        }

        $kleinster = min($mediane);
        $groesster = max($mediane);

        // Großzügige Schranke: Zeitmessungen auf einem Arbeitsplatzrechner
        // rauschen. Ein früher Ausstieg ohne Datenbankabfrage wäre um ein
        // Vielfaches schneller und fiele hier auf. Der genaue Nachweis
        // steht in testAlleDreiFaelleFuehrenDieselbeAbfrageAus.
        self::assertLessThan(
            3.0,
            $groesster / max($kleinster, 1),
            sprintf(
                'Die Laufzeiten unterscheiden sich zu stark: %s',
                json_encode(array_map(static fn (int $n): string => round($n / 1000) . ' µs', $mediane))
            )
        );
    }

    // ------------------------------------------------------------------
    // Hilfsmittel
    // ------------------------------------------------------------------

    private function abrufen(string $id): Response
    {
        return $this->router->dispatch(new Request(
            'POST',
            '/api/reveal',
            json_encode(['id' => $id], JSON_THROW_ON_ERROR),
            '203.0.113.7'
        ));
    }

    private function zaehler(string $name): int
    {
        // SHOW nimmt keine Platzhalter. Der Name stammt aus diesem Test und
        // wird zusätzlich geprüft, damit hier nichts anderes landen kann.
        self::assertMatchesRegularExpression('/\A[A-Za-z_]+\z/', $name);

        /** @var array<string, mixed>|false $zeile */
        $zeile = $this->query("SHOW SESSION STATUS LIKE '" . $name . "'")->fetch();

        self::assertIsArray($zeile, 'Sitzungszähler ' . $name . ' nicht lesbar.');

        return (int) self::alsText($zeile['Value']);
    }
}
