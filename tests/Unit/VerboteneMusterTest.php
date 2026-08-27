<?php

declare(strict_types=1);

namespace Einmalpost\Tests\Unit;

use Einmalpost\Tests\Support\Quelltext;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

/**
 * Zusage 20: Der Code enthält keines der verbotenen Muster.
 *
 * Geprüft wird alles, was ausgeliefert wird: src, public, bin, db.
 *
 * Nicht geprüft wird tests/Support. Dort liegt mit consume-worker-naiv.php
 * bewusst das verbotene Muster "SELECT, dann separates DELETE" - als
 * Gegenprobe, die zeigt, dass der Nebenläufigkeitstest eine
 * Mehrfachauslieferung überhaupt bemerken würde. Diese Datei ist kein Teil
 * des Dienstes und wird nie ausgeliefert. Die Ausnahme steht hier
 * ausdrücklich, damit sie niemand für ein Versehen hält.
 */
final class VerboteneMusterTest extends TestCase
{
    private const GEPRUEFTE_ORDNER = ['src', 'public', 'bin', 'db'];

    private const ENDUNGEN = ['php', 'js', 'sql', 'css', 'html'];

    /**
     * @return list<array{string, string, string}> Muster, Bezeichnung, Begründung
     */
    public static function verboteneMuster(): array
    {
        return [
            // --- Gefährliche DOM-Zugriffe ---
            ['/\binnerHTML\b/', 'innerHTML', 'Der Klartext wird ausschließlich über textContent gesetzt.'],
            ['/\bouterHTML\b/', 'outerHTML', 'Wie innerHTML.'],
            ['/document\s*\.\s*write\b/', 'document.write', 'Schreibt ungeprüftes HTML in die Seite.'],
            ['/(?<![\w$>])eval\s*\(/', 'eval(', 'Führt beliebigen Text als Programm aus.'],
            ['/new\s+Function\s*\(/', 'new Function', 'Wie eval, nur umständlicher.'],

            // --- Schwache Zufallsquellen ---
            ['/\buniqid\s*\(/', 'uniqid()', 'Beruht auf der Uhrzeit und ist vorhersagbar.'],
            ['/\bmt_rand\s*\(/', 'mt_rand()', 'Kein kryptografischer Zufall.'],
            ['/(?<![\w_])rand\s*\(/', 'rand()', 'Kein kryptografischer Zufall.'],
            ['/\bmd5\s*\(\s*time\s*\(/', 'md5(time())', 'Vorhersagbar und obendrein md5.'],
            ['/\bMath\s*\.\s*random\s*\(/', 'Math.random()', 'Kein kryptografischer Zufall im Browser.'],

            // --- Verschlüsselung ---
            ['/AES-CBC/i', 'AES-CBC', 'Ohne Authentifizierung; Schlüsseltext wäre unbemerkt änderbar.'],
            ['/AES-CTR/i', 'AES-CTR', 'Ohne Authentifizierung.'],
            ['/\bECB\b/', 'ECB', 'Verrät Muster im Klartext.'],

            // --- Der atomare Verbrauch ---
            ['/SELECT[^;]*FROM\s+secrets/i', 'SELECT auf secrets', 'Gelesen wird nur über DELETE ... RETURNING.'],
            ['/FOR\s+UPDATE/i', 'SELECT ... FOR UPDATE', 'Der Rückfallpfad wurde ersatzlos entfernt.'],

            // --- Daten, die nicht gespeichert werden dürfen ---
            ['/HTTP_USER_AGENT/', 'User-Agent', 'Wird nicht einmal eingelesen.'],
            ['/HTTP_REFERER/', 'Referrer', 'Wird nicht eingelesen.'],
            ['/created_at/i', 'created_at', 'Erstellungszeitpunkte werden nicht gespeichert.'],
            ['/view_count|hit_count|\bviews\b/i', 'Aufrufzähler', 'Wird nicht gespeichert.'],
            ['/HTTP_X_FORWARDED_FOR/', 'X-Forwarded-For', 'Frei setzbar; das Rate-Limit wäre wirkungslos.'],

            // --- Externe Ressourcen ---
            ['~<script[^>]+src\s*=\s*[\'"]https?://~i', 'Externes Skript', 'Könnte location.href samt Schlüssel auslesen.'],
            ['~<link[^>]+href\s*=\s*[\'"]https?://~i', 'Externe Ressource', 'Wie oben.'],
            ['/fonts\.(googleapis|gstatic)\.com/i', 'Web-Font von fremdem Server', 'Verrät jeden Aufruf an Dritte.'],
            ['/@import\s+url\s*\(\s*[\'"]?https?:/i', 'Externes CSS', 'Wie oben.'],
            ['/googletagmanager|google-analytics|matomo|plausible|piwik/i', 'Statistikdienst', 'Hier wird nichts gemessen.'],
            ['/cdn\.|cdnjs|unpkg\.com|jsdelivr/i', 'CDN', 'Keine fremden Auslieferungswege.'],

            // --- Werbeaussagen ---
            ['/100\s*%\s*sicher/i', 'Werbeaussage', 'Stimmt nie.'],
            ['/unknackbar/i', 'Werbeaussage', 'Stimmt nie.'],
            ['/milit(a|ä)r/i', 'Werbeaussage', '"Militärische Verschlüsselung" ist eine Floskel.'],
            ['/bank(en)?[- ]?(niveau|standard)/i', 'Werbeaussage', 'Wie oben.'],
        ];
    }

    /**
     * @param string $muster
     */
    #[DataProvider('verboteneMuster')]
    public function testDasMusterKommtNirgendsVor(string $muster, string $bezeichnung, string $begruendung): void
    {
        $treffer = [];

        foreach (self::dateien() as $datei) {
            // Geprüft wird der wirksame Code. Die Kommentare erklären an
            // mehreren Stellen, warum ein Muster verboten ist - das ist
            // kein Verstoß, sondern die Begründung dafür.
            $inhalt = Quelltext::ohneKommentare(
                (string) file_get_contents($datei),
                pathinfo($datei, PATHINFO_EXTENSION)
            );
            $zeilen = explode("\n", $inhalt);

            foreach ($zeilen as $nummer => $zeile) {
                if (preg_match($muster, $zeile) === 1) {
                    $treffer[] = sprintf(
                        '%s:%d  %s',
                        str_replace(dirname(__DIR__, 2) . '/', '', $datei),
                        $nummer + 1,
                        trim($zeile)
                    );
                }
            }
        }

        self::assertSame(
            [],
            $treffer,
            sprintf("Verbotenes Muster \"%s\" gefunden.\n%s\n\n%s", $bezeichnung, $begruendung, implode("\n", $treffer))
        );
    }

    /**
     * Der Prüfer muss überhaupt etwas finden können. Ohne diesen Test wäre
     * ein grüner Lauf auch dann grün, wenn der Scanner gar keine Dateien
     * liest.
     */
    public function testDerPrueferFindetEingeschmuggelteMuster(): void
    {
        self::assertGreaterThan(10, count(self::dateien()), 'Der Prüfer liest zu wenige Dateien.');

        // Echter Code wird gefunden ...
        $bosartig = Quelltext::ohneKommentare("var x = a;\nx.innerHTML = boese;\n", 'js');
        self::assertSame(1, preg_match('/\binnerHTML\b/', $bosartig));

        // ... und auch dann, wenn er hinter einem Kommentar in derselben
        // Zeile steht.
        $getarnt = Quelltext::ohneKommentare("x.innerHTML = boese; // sieht harmlos aus\n", 'js');
        self::assertSame(1, preg_match('/\binnerHTML\b/', $getarnt));

        // Eine reine Erklärzeile dagegen nicht.
        $erklaerung = Quelltext::ohneKommentare("// niemals innerHTML benutzen\nvar x = 1;\n", 'js');
        self::assertSame(0, preg_match('/\binnerHTML\b/', $erklaerung));

        // Zeilennummern bleiben stehen.
        self::assertSame(3, substr_count(Quelltext::ohneKommentare("// a\n// b\nvar x;\n", 'js'), "\n"));
    }

    /**
     * Der Shell-Prüfer lässt den XML-Namensraum für SVG durch. Diese Ausnahme
     * ist berechtigt - ein Namensraum ist eine Kennung, keine Adresse, und
     * wird nie abgerufen. Sie darf aber kein Scheunentor werden.
     *
     * Geprüft wird deshalb an einer echten Datei im ausgelieferten Ordner,
     * nicht an einer nachgebauten: Eine wirkliche http-Adresse muss dort
     * weiterhin auffallen, auch wenn der Namensraum daneben steht.
     */
    public function testDieAusnahmeFuerDenSvgNamensraumBleibtEng(): void
    {
        $wurzel = dirname(__DIR__, 2);
        $probe  = $wurzel . '/public/assets/.pruefprobe.js';

        $laufen = static function () use ($wurzel): int {
            exec(sprintf('cd %s && bash tools/verbotene-muster.sh 2>&1', escapeshellarg($wurzel)), $_, $stand);

            return $stand;
        };

        self::assertSame(0, $laufen(), 'Der Prüfer beanstandet schon den unveränderten Stand.');

        try {
            // Der Namensraum allein: erlaubt.
            file_put_contents($probe, "var NS = 'http://www.w3.org/2000/svg';\n");
            self::assertSame(0, $laufen(), 'Der SVG-Namensraum sollte durchgehen.');

            // Eine echte Adresse daneben: muss auffallen.
            file_put_contents(
                $probe,
                "var NS = 'http://www.w3.org/2000/svg';\nvar weg = 'http://beispiel.test/sammeln';\n"
            );
            self::assertSame(
                1,
                $laufen(),
                'Eine echte http-Adresse muss auffallen, auch neben dem Namensraum.'
            );

            // Und auch eine, die dem Namensraum ähnelt.
            file_put_contents($probe, "var x = 'http://www.w3.org.beispiel.test/2000/svg';\n");
            self::assertSame(1, $laufen(), 'Eine nachgeahmte Adresse darf nicht durchrutschen.');

            // Der Fall, der die Ausnahme fast zum Scheunentor gemacht hätte:
            // beides in **derselben** Zeile. Wird die Ausnahme als
            // Zeilenfilter angewandt, verschwindet die fremde Adresse
            // zusammen mit dem Namensraum aus der Prüfung.
            file_put_contents(
                $probe,
                "var NS = 'http://www.w3.org/2000/svg'; var weg = 'http://beispiel.test/sammeln';\n"
            );
            self::assertSame(
                1,
                $laufen(),
                'Eine fremde Adresse in derselben Zeile wie der Namensraum muss auffallen.'
            );
        } finally {
            @unlink($probe);
        }

        self::assertFileDoesNotExist($probe);
        self::assertSame(0, $laufen(), 'Nach dem Aufräumen muss der Prüfer wieder schweigen.');
    }

    public function testDerKlartextWirdNurUeberTextContentGesetzt(): void
    {
        $roh    = (string) file_get_contents(dirname(__DIR__, 2) . '/public/assets/reveal.js');
        $code   = Quelltext::ohneKommentare($roh, 'js');

        // Der Klartext geht ausschließlich über textContent in das <pre>.
        self::assertStringContainsString('inhalt.textContent = ergebnis.text;', $code);
        self::assertStringNotContainsString('innerHTML', $code);

        // Und der Dateiname ebenfalls - er kommt aus demselben
        // entschlüsselten Block und ist genauso wenig vertrauenswürdig.
        self::assertStringContainsString('dateiName.textContent =', $code);
    }

    public function testDasFrontendHatKeineLaufzeitabhaengigkeiten(): void
    {
        foreach (glob(dirname(__DIR__, 2) . '/public/assets/*.js') ?: [] as $datei) {
            $inhalt = (string) file_get_contents($datei);

            self::assertSame(
                0,
                preg_match('/\b(require|import)\s*\(/', $inhalt),
                basename($datei) . ' lädt etwas nach. Das Frontend hat keine Abhängigkeiten.'
            );
            self::assertSame(0, preg_match('/^\s*import\s+/m', $inhalt), basename($datei));
        }
    }

    /**
     * Die Vorlagen tragen Struktur, kein Aussehen.
     *
     * Farben, Schriften und Abstände stehen in den Stilvorlagen. Eine Farbe
     * in einer Vorlage wäre eine Gestaltung, die sich nicht überschreiben
     * lässt - und sie läge im Repository, wo das Aussehen gerade nicht
     * liegen soll.
     */
    public function testDieVorlagenEnthaltenKeineGestaltung(): void
    {
        // Rekursiv, nicht über feste Globs: Die englischen Vorlagen liegen in
        // einem Unterverzeichnis und fielen sonst heraus - der Prüfer bliebe
        // grün, während dort ein Farbwert stünde. Genau so ist es beim Bauen
        // der englischen Fassung beinahe passiert.
        $vorlagen = [];

        $lauf = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator(dirname(__DIR__, 2) . '/src/templates')
        );

        /** @var SplFileInfo $datei */
        foreach ($lauf as $datei) {
            if ($datei->isFile() && $datei->getExtension() === 'php') {
                $vorlagen[] = $datei->getPathname();
            }
        }

        sort($vorlagen);

        // Die Schranke liegt dicht unter der tatsächlichen Zahl. Fällt später
        // ein Verzeichnis wieder heraus, wird das hier rot - eine großzügige
        // Schranke würde es durchgehen lassen.
        self::assertGreaterThan(8, count($vorlagen), 'Es werden zu wenige Vorlagen geprüft.');

        foreach ($vorlagen as $datei) {
            $inhalt = (string) file_get_contents($datei);

            self::assertSame(
                0,
                preg_match('/#[0-9a-fA-F]{6}\b/', $inhalt),
                basename($datei) . ' enthält einen Farbwert.'
            );
            self::assertSame(0, preg_match('/<style/i', $inhalt), basename($datei) . ' enthält einen Stilblock.');
            self::assertSame(0, preg_match('/\sstyle="/i', $inhalt), basename($datei) . ' enthält ein style-Attribut.');
        }
    }

    /**
     * Die schmucklose Fassung bleibt schmucklos: Wer klont, bekommt einen
     * bedienbaren Dienst in Grau - keine halbe Gestaltung.
     */
    public function testDieSchmuckloseFassungBleibtSchmucklos(): void
    {
        $erlaubteFarben = [
            '#000', '#fff', '#000000', '#ffffff',
            // Der Platzhaltertext im Eingabefeld. Ohne eigene Angabe wählt
            // jeder Browser seine eigene Farbe, und WebKits Standardgrau
            // liegt unter dem Mindestkontrast. 7:1 gegen Weiß, und noch
            // erkennbar blasser als eingegebener Text.
            '#595959',
        ];

        foreach ([dirname(__DIR__, 2) . '/public/assets/theme-default.css'] as $datei) {
            $inhalt = (string) file_get_contents($datei);

            $farben = [];
            preg_match_all('/#[0-9a-fA-F]{3,8}\b/', $inhalt, $farben);

            foreach ($farben[0] as $farbe) {
                self::assertContains(
                    strtolower($farbe),
                    $erlaubteFarben,
                    basename($datei) . ' verwendet die Farbe ' . $farbe . '.'
                );
            }

            self::assertSame(0, preg_match('/@font-face/i', $inhalt), 'Eigene Schriftdatei in ' . basename($datei));

            // Positiv geprüft statt negativ: Ein verneinender Ausdruck mit
            // \s* davor lässt sich durch Zurücksetzen der Suche aushebeln -
            // die Engine probiert dann eine Position, an der die Verneinung
            // zutrifft. Hier wird stattdessen jeder tatsächlich verwendete
            // Wert gegen eine Liste gehalten.
            $erlaubteSchriften = [
                'system-ui', '-apple-system', 'ui-monospace', 'sfmono-regular',
                'menlo', 'sans-serif', 'monospace', 'inherit',
            ];
            $angaben = [];
            preg_match_all('/font-family\s*:\s*([^;}]+)/i', $inhalt, $angaben);

            foreach ($angaben[1] as $angabe) {
                foreach (explode(',', $angabe) as $schrift) {
                    self::assertContains(
                        strtolower(trim($schrift)),
                        $erlaubteSchriften,
                        'Andere Schrift als die des Systems in ' . basename($datei) . ': ' . trim($schrift)
                    );
                }
            }
        }
    }

    /**
     * @return list<string>
     */
    private static function dateien(): array
    {
        $wurzel  = dirname(__DIR__, 2);
        $dateien = [];

        foreach (self::GEPRUEFTE_ORDNER as $ordner) {
            $pfad = $wurzel . '/' . $ordner;

            if (!is_dir($pfad)) {
                continue;
            }

            /** @var SplFileInfo $datei */
            foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($pfad)) as $datei) {
                if ($datei->isFile() && in_array($datei->getExtension(), self::ENDUNGEN, true)) {
                    $dateien[] = $datei->getPathname();
                }
            }
        }

        sort($dateien);

        return $dateien;
    }
}
