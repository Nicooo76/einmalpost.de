<?php

declare(strict_types=1);

namespace Einmalpost\Tests\Integration;

/**
 * Zusage 16, nebenläufig: Das Rate-Limit lässt auch bei gleichzeitigen
 * Anfragen niemals mehr als die Grenze durch.
 *
 * Hintergrund: UEBERGABE.md, Abschnitt 5.4, hält für möglich, dass zwei
 * gleichzeitige Anfragen denselben Zählerstand lesen und dadurch "eine
 * Anfrage mehr durchgeht als vorgesehen". Die Prüfsitzung hat das mit echten
 * gleichzeitigen Prozessen gemessen: Es tritt nicht ein. allow() zählt zuerst
 * atomar hoch (INSERT ... ON DUPLICATE KEY UPDATE) und liest erst danach - ein
 * Anfragender liest also nie weniger als seinen eigenen Stand. Über-Zulassung
 * ist damit ausgeschlossen; unter Last wird eher zu wenig zugelassen.
 *
 * Dieser Test hält die sichere Eigenschaft fest: Würde jemand die Reihenfolge
 * auf "erst lesen, dann zählen" umbauen (die tatsächlich anfällige Variante),
 * ließe die Grenze mehrere gleichzeitige Anfrage durch - und dieser Test würde
 * rot.
 */
final class RateLimitNebenlaeufigTest extends IntegrationTestCase
{
    private const MAX       = 5;
    private const PROZESSE  = 12;
    private const DURCHGANGE = 5;

    public function testGleichzeitigeAnfragenLassenNiemalsMehrAlsDieGrenzeDurch(): void
    {
        for ($durchgang = 1; $durchgang <= self::DURCHGANGE; $durchgang++) {
            $this->pdo()->exec('DELETE FROM rate_limits');

            $ergebnisse = $this->starteGleichzeitig('203.0.113.99');

            $fehler = array_values(array_filter(
                $ergebnisse,
                static fn (string $z): bool => str_starts_with($z, 'ERROR')
            ));
            self::assertSame([], $fehler, 'Durchgang ' . $durchgang . ': ' . implode(' | ', $fehler));

            $erlaubt = count(array_filter($ergebnisse, static fn (string $z): bool => $z === 'ALLOW'));

            self::assertLessThanOrEqual(
                self::MAX,
                $erlaubt,
                sprintf(
                    'Durchgang %d: %d von %d gleichzeitigen Anfragen wurden erlaubt, die Grenze ist %d. '
                    . 'Mehr als die Grenze bedeutet: Der Zähler wird vor dem Hochzählen gelesen - der Race '
                    . 'aus UEBERGABE 5.4 wäre dann real.',
                    $durchgang,
                    $erlaubt,
                    self::PROZESSE,
                    self::MAX
                )
            );

            // Gegenprobe, dass die Prozesse wirklich alle zugegriffen haben:
            // jeder Aufruf zählt hoch, der Endstand muss die Prozesszahl sein.
            $hits = (int) $this->query('SELECT hits FROM rate_limits')->fetchColumn();
            self::assertSame(
                self::PROZESSE,
                $hits,
                'Nicht alle Prozesse haben hochgezählt - dann liefen sie nicht wirklich gleichzeitig.'
            );
        }
    }

    /**
     * @return list<string>
     */
    private function starteGleichzeitig(string $ip): array
    {
        $pfad    = dirname(__DIR__) . '/Support/rate-worker.php';
        $startAt = microtime(true) + 0.6;

        $prozesse = [];
        $rohre    = [];

        for ($i = 0; $i < self::PROZESSE; $i++) {
            $deskriptoren = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];

            $prozess = proc_open(
                [PHP_BINARY, $pfad, (string) self::MAX, $ip, sprintf('%.6F', $startAt)],
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
}
