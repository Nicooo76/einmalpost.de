<?php

declare(strict_types=1);

namespace Einmalpost\Tests\Unit;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

/**
 * Was ausgeliefert wird - und was nicht.
 *
 * Composer und npm sind Entwicklungswerkzeuge. Hinge der Betrieb an
 * vendor/, müsste dieses Verzeichnis mit auf den Server, und aus einem
 * Entwicklungswerkzeug würde eine Laufzeitabhängigkeit.
 */
final class AuslieferungTest extends TestCase
{
    private const WURZEL = __DIR__ . '/../..';

    public function testDerBetriebBrauchtKeinComposerVerzeichnis(): void
    {
        $treffer = [];

        foreach ($this->ausgelieferteDateien() as $datei) {
            $inhalt = (string) file_get_contents($datei);

            if (str_contains($inhalt, 'vendor/autoload') || str_contains($inhalt, 'vendor\\autoload')) {
                $treffer[] = str_replace((string) realpath(self::WURZEL) . '/', '', $datei);
            }
        }

        self::assertSame(
            [],
            $treffer,
            'Diese ausgelieferten Dateien hängen an Composer: ' . implode(', ', $treffer)
        );
    }

    /**
     * Der eigene Autoloader muss alle Klassen finden, die der Dienst nutzt.
     */
    public function testDerEigeneAutoloaderFindetAlleKlassen(): void
    {
        $klassen = [];

        foreach ($this->dateienIn('src') as $datei) {
            if ($datei->getExtension() !== 'php' || str_contains((string) $datei->getRealPath(), '/templates/')) {
                continue;
            }

            if ($datei->getBasename() === 'autoload.php') {
                continue;
            }

            $relativ = str_replace((string) realpath(self::WURZEL . '/src') . '/', '', (string) $datei->getRealPath());
            $klassen[] = 'Einmalpost\\' . str_replace(['/', '.php'], ['\\', ''], $relativ);
        }

        self::assertNotSame([], $klassen);

        foreach ($klassen as $klasse) {
            self::assertTrue(
                class_exists($klasse) || interface_exists($klasse),
                $klasse . ' ist über den Namen nicht auffindbar.'
            );
        }
    }

    /**
     * Der Autoloader läuft auch allein, ohne dass vorher etwas anderes
     * geladen wurde - so, wie es beim ersten Aufruf im Betrieb ist.
     */
    public function testDerAutoloaderLaeuftInEinemFrischenProzess(): void
    {
        $skript = 'require ' . var_export(realpath(self::WURZEL . '/src/autoload.php'), true) . ';'
            . ' echo class_exists("Einmalpost\\\\SecretStore") ? "ja" : "nein";';

        $ausgabe = shell_exec(escapeshellarg(PHP_BINARY) . ' -r ' . escapeshellarg($skript) . ' 2>&1');

        self::assertSame('ja', trim((string) $ausgabe));
    }

    public function testKeineEntwicklungswerkzeugeImWebVerzeichnis(): void
    {
        foreach (['vendor', 'node_modules', 'tests', 'tools'] as $verboten) {
            self::assertDirectoryDoesNotExist(
                self::WURZEL . '/public/' . $verboten,
                $verboten . ' liegt im Web-Verzeichnis.'
            );
        }

        foreach (['composer.json', 'package.json', 'phpunit.xml', 'Makefile', '.env'] as $datei) {
            self::assertFileDoesNotExist(self::WURZEL . '/public/' . $datei);
        }
    }

    public function testDieKonfigurationLiegtNichtImWebVerzeichnis(): void
    {
        self::assertFileDoesNotExist(self::WURZEL . '/public/config.php');
        self::assertDirectoryDoesNotExist(self::WURZEL . '/public/config');
        self::assertFileExists(self::WURZEL . '/config/config.example.php');
    }

    /**
     * Die einzige erlaubte Verknüpfung nach außen ist der Verweis auf den
     * Betreiber im Fußbereich - als gewöhnlicher Link, ohne Zählparameter,
     * ohne Grafik von einem fremden Server.
     */
    private const ERLAUBT_NACH_AUSSEN = [
        // Der Betreiber im Fußbereich.
        'https://pixagentur.com',
        // Der Quellcode. Ein Verweis darauf gehört zum Sicherheitsversprechen:
        // Wer nicht vergleichen kann, muss glauben.
        'https://github.com/Nicooo76/einmalpost.de',
    ];

    public function testDasFrontendLaedtNurEigeneDateien(): void
    {
        $treffer = [];

        foreach ([...$this->dateienIn('src/templates'), ...$this->dateienIn('public/assets')] as $datei) {
            $inhalt = (string) file_get_contents($datei->getPathname());

            $quellen = [];
            preg_match_all('/(?:src|href)\s*=\s*"([^"]+)"/i', $inhalt, $quellen);

            foreach ($quellen[1] as $quelle) {
                if (str_starts_with($quelle, '/') || str_starts_with($quelle, '#')) {
                    continue;
                }

                // mailto: und tel: laden nichts. Sie übergeben an das Mail-
                // oder Telefonprogramm des Geräts und stellen keine
                // Netzwerkverbindung her - im Impressum sind sie Pflicht.
                if (str_starts_with($quelle, 'mailto:') || str_starts_with($quelle, 'tel:')) {
                    continue;
                }

                // Wird erst beim Rendern gefüllt. Das Ergebnis prüft
                // testDieAusgeliefertenSeitenLadenNichtsVonAussen.
                if (str_contains($quelle, '<?')) {
                    continue;
                }

                if (in_array($quelle, self::ERLAUBT_NACH_AUSSEN, true)) {
                    continue;
                }

                $treffer[] = $datei->getBasename() . ': ' . $quelle;
            }
        }

        self::assertSame([], $treffer, 'Verweise nach außen: ' . implode(', ', $treffer));
    }

    /**
     * Der Verweis auf den Betreiber darf keine Zählparameter tragen und
     * kein target="_blank" ohne rel-Absicherung haben.
     */
    public function testDerVerweisAufDenBetreiberIstSchlicht(): void
    {
        $layout = (string) file_get_contents(self::WURZEL . '/src/templates/layout.php');

        $treffer = [];
        preg_match('/<a[^>]*href="https:\/\/pixagentur\.com[^"]*"[^>]*>/i', $layout, $treffer);

        $tag = $treffer[0] ?? null;

        self::assertIsString($tag, 'Der Verweis auf den Betreiber fehlt im Fußbereich.');

        self::assertStringNotContainsString('?', $tag, 'Der Verweis trägt Parameter.');
        self::assertStringNotContainsString('utm_', $tag);

        if (str_contains($tag, 'target=')) {
            self::assertStringContainsString('noopener', $tag);
            self::assertStringContainsString('noreferrer', $tag);
        }
    }

    /**
     * @return list<string>
     */
    private function ausgelieferteDateien(): array
    {
        $dateien = [];

        foreach (['src', 'public', 'bin'] as $ordner) {
            foreach ($this->dateienIn($ordner) as $datei) {
                if (in_array($datei->getExtension(), ['php', 'js'], true)) {
                    $dateien[] = (string) $datei->getRealPath();
                }
            }
        }

        return $dateien;
    }

    /**
     * @return list<SplFileInfo>
     */
    private function dateienIn(string $ordner): array
    {
        $pfad = self::WURZEL . '/' . $ordner;

        if (!is_dir($pfad)) {
            return [];
        }

        $gefunden = [];

        /** @var SplFileInfo $datei */
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($pfad)) as $datei) {
            if ($datei->isFile()) {
                $gefunden[] = $datei;
            }
        }

        return $gefunden;
    }
}
