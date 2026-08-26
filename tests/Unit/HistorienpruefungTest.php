<?php

declare(strict_types=1);

namespace Einmalpost\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * tools/check-history.sh ist das Werkzeug, das vor der Veröffentlichung
 * zusichert, dass nie Zugangsdaten in der Historie standen. Es entscheidet
 * damit über eine Sache, die sich nicht zurücknehmen lässt: Was einmal
 * öffentlich war, ist öffentlich.
 *
 * Ein solches Werkzeug darf nicht selbst ungeprüft sein. Geprüft wird
 * deshalb an echten Repositorys, nicht an nachgebauten Ausgaben - und vor
 * allem, dass es überhaupt etwas finden kann.
 */
final class HistorienpruefungTest extends TestCase
{
    private string $spielwiese = '';

    protected function setUp(): void
    {
        $this->spielwiese = (string) tempnam(sys_get_temp_dir(), 'histpruef');
        unlink($this->spielwiese);
        mkdir($this->spielwiese, 0o700, true);
    }

    protected function tearDown(): void
    {
        if ($this->spielwiese !== '' && is_dir($this->spielwiese)) {
            exec(sprintf('rm -rf %s', escapeshellarg($this->spielwiese)));
        }
    }

    /**
     * @return array{0: int, 1: string} Rückgabewert und Ausgabe
     */
    private function pruefeIn(string $verzeichnis): array
    {
        $skript = dirname(__DIR__, 2) . '/tools/check-history.sh';
        copy($skript, $verzeichnis . '/pruefer.sh');

        exec(
            sprintf('cd %s && bash pruefer.sh 2>&1', escapeshellarg($verzeichnis)),
            $zeilen,
            $stand
        );

        return [$stand, implode("\n", $zeilen)];
    }

    /**
     * Legt ein Repository an, das die projektspezifischen Erwartungen des
     * Prüfers erfüllt: Die schmucklose Fassung liegt bei, und config.php ist
     * ignoriert. Ohne das beanstandet er zu Recht - er ist für dieses Projekt
     * gebaut und nicht für Repositorys im Allgemeinen.
     */
    private function legeRepoAn(string $pfad): void
    {
        mkdir($pfad, 0o700, true);
        $befehle = [
            'git init -q',
            'git config user.email pruefung@example.invalid',
            'git config user.name Pruefung',
            'git config commit.gpgsign false',
        ];
        exec(sprintf('cd %s && %s', escapeshellarg($pfad), implode(' && ', $befehle)));

        mkdir($pfad . '/public/assets', 0o700, true);
        file_put_contents($pfad . '/public/assets/theme-default.css', ":root { color-scheme: light dark; }\n");
        file_put_contents($pfad . '/.gitignore', "config/config.php\n");
    }

    private function commit(string $pfad, string $meldung): void
    {
        exec(sprintf(
            'cd %s && git add -A && git commit -q -m %s',
            escapeshellarg($pfad),
            escapeshellarg($meldung)
        ));
    }

    /**
     * Der wichtigste Test: Ohne ihn wäre jedes "sauber" bedeutungslos.
     */
    public function testEineZugangsdateiInDerHistorieFaelltAuf(): void
    {
        $repo = $this->spielwiese . '/mit-fund';
        $this->legeRepoAn($repo);

        file_put_contents($repo . '/harmlos.txt', "nichts besonderes\n");
        $this->commit($repo, 'erster Stand');

        // Eine Zugangsdatei, die später wieder verschwindet - genau der Fall,
        // den ein Blick auf den aktuellen Stand nicht bemerken würde.
        //
        // Mit -f, weil .gitignore sie sonst abfängt. Genau so passiert es in
        // Wirklichkeit auch: erst mitgenommen, dann die Regel nachgetragen.
        mkdir($repo . '/config', 0o700);
        file_put_contents(
            $repo . '/config/config.php',
            "<?php return ['db_pass' => 'geheim-und-echt-1234'];\n"
        );
        exec(sprintf(
            'cd %s && git add -f config/config.php && git commit -q -m %s',
            escapeshellarg($repo),
            escapeshellarg('aus Versehen mitgenommen')
        ));

        unlink($repo . '/config/config.php');
        $this->commit($repo, 'wieder entfernt - aber die Historie bleibt');

        // Vorbedingung: Ohne sie prüfte der Test etwas anderes als gedacht -
        // ein grüner Lauf wäre dann kein Nachweis, sondern ein Versehen.
        exec(
            sprintf('cd %s && git log --all --oneline -- config/config.php', escapeshellarg($repo)),
            $inHistorie
        );
        self::assertNotEmpty($inHistorie, 'Der Testaufbau hat die Datei gar nicht erst eingeschmuggelt.');

        // Und im aktuellen Stand liegt sie nicht mehr - darum geht es ja.
        self::assertFileDoesNotExist($repo . '/config/config.php');

        [$stand, $ausgabe] = $this->pruefeIn($repo);

        self::assertSame(
            1,
            $stand,
            "Eine config.php in der Historie muss auffallen. Ausgabe:\n" . $ausgabe
        );
    }

    /**
     * Ein flacher Klon hat keine Historie. Die Prüfung darf dann nicht
     * schweigend durchlaufen - das sähe aus wie eine bestandene Prüfung und
     * wäre keine.
     */
    public function testEinFlacherKlonWirdAbgewiesen(): void
    {
        $repo = $this->spielwiese . '/voll';
        $this->legeRepoAn($repo);
        file_put_contents($repo . '/datei.txt', "eins\n");
        $this->commit($repo, 'eins');
        file_put_contents($repo . '/datei.txt', "zwei\n");
        $this->commit($repo, 'zwei');

        $flach = $this->spielwiese . '/flach';
        exec(sprintf(
            'git clone --quiet --depth 1 file://%s %s 2>&1',
            $repo,
            escapeshellarg($flach)
        ));

        self::assertDirectoryExists($flach, 'Der flache Klon ließ sich nicht anlegen.');

        [$stand, $ausgabe] = $this->pruefeIn($flach);

        self::assertSame(1, $stand, "Ein flacher Klon muss abgewiesen werden. Ausgabe:\n" . $ausgabe);
        self::assertStringContainsString('flacher Klon', $ausgabe);
    }

    /**
     * Und die Gegenrichtung: Ein sauberes, vollständiges Repository muss
     * durchgehen. Sonst wäre der Prüfer nur ein Werkzeug, das immer nein sagt.
     */
    public function testEinSauberesRepositoryGehtDurch(): void
    {
        $repo = $this->spielwiese . '/sauber';
        $this->legeRepoAn($repo);
        mkdir($repo . '/src', 0o700);
        file_put_contents($repo . '/src/Ding.php', "<?php\n\nfinal class Ding {}\n");
        $this->commit($repo, 'erster Stand');
        file_put_contents($repo . '/src/Ding.php', '<?php' . "\n\n" . 'final class Ding { public int $zahl = 1; }' . "\n");
        $this->commit($repo, 'erweitert');

        [$stand, $ausgabe] = $this->pruefeIn($repo);

        self::assertSame(0, $stand, "Ein sauberes Repository muss durchgehen. Ausgabe:\n" . $ausgabe);
    }

    /**
     * Zuletzt das echte Repository dieses Projekts - das ist der Fall, auf den
     * es ankommt.
     */
    public function testDasEigeneRepositoryIstSauber(): void
    {
        $wurzel = dirname(__DIR__, 2);

        exec(sprintf('cd %s && bash tools/check-history.sh 2>&1', escapeshellarg($wurzel)), $zeilen, $stand);

        self::assertSame(0, $stand, "Die eigene Historie ist nicht sauber:\n" . implode("\n", $zeilen));
    }
}
