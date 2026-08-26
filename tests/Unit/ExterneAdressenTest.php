<?php

declare(strict_types=1);

namespace Einmalpost\Tests\Unit;

use Einmalpost\Http\Request;
use Einmalpost\Http\Router;
use Einmalpost\RateLimiter;
use Einmalpost\SecretStore;
use Einmalpost\Tests\Support\ExplodierenderZugang;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

/**
 * Keine externe Ressource — in keiner Datei.
 *
 * Jede fremde Adresse könnte den Aufruf mitzählen, und ein fremdes Skript
 * könnte location.href samt Schlüssel auslesen. Die CSP mit
 * default-src 'none' würde es blockieren; der Punkt ist, dass es gar nicht
 * erst versucht wird.
 *
 * Geprüft wird der Quelltext UND das tatsächlich ausgelieferte HTML - eine
 * Vorlage kann harmlos aussehen und trotzdem etwas Fremdes erzeugen.
 */
final class ExterneAdressenTest extends TestCase
{
    /**
     * Die erlaubten Adressen, und nur als href - nie als geladene Ressource.
     *
     * Der Verweis auf den Quellcode gehört zum Sicherheitsversprechen: Wer
     * nicht vergleichen kann, was ihm ausgeliefert wird, muss glauben.
     */
    private const ERLAUBT = ['https://pixagentur.com', 'https://github.com/Nicooo76/einmalpost.de'];

    /**
     * Adressen in Kommentaren und in schema.org-Auszeichnung sind keine
     * geladenen Ressourcen: schema.org wird nie abgerufen, es ist eine
     * Kennung.
     */
    private const HARMLOS = ['https://schema.org', 'https://hstspreload.org'];


    /**
     * @return list<array{string}>
     */
    public static function ordner(): array
    {
        return [['public'], ['src/templates']];
    }

    #[DataProvider('ordner')]
    public function testKeineDateiLaedtEtwasVonAussen(string $ordner): void
    {
        $treffer = [];

        foreach ($this->dateien($ordner) as $datei) {
            $inhalt = (string) file_get_contents($datei);
            $zeilen = explode("\n", $inhalt);

            foreach ($zeilen as $nummer => $zeile) {
                foreach ($this->fremdeAdressen($zeile) as $adresse) {
                    $treffer[] = sprintf('%s:%d %s', basename($datei), $nummer + 1, $adresse);
                }
            }
        }

        self::assertSame([], $treffer, 'Fremde Adressen gefunden: ' . implode(' | ', $treffer));
    }

    /**
     * Auch die Gestaltung, die nicht im Repository liegt. Sie wird vor dem
     * Hochspielen geprüft - fehlt sie, prüft dieser Test die schmucklose
     * Fassung, und das steht dann auch so da.
     */
    public function testAuchDieGestaltungLaedtNichtsVonAussen(): void
    {
        $theme = dirname(__DIR__, 2) . '/public/assets/theme.css';

        if (!is_file($theme)) {
            self::assertFileExists(dirname(__DIR__, 2) . '/public/assets/theme-default.css');

            return;
        }

        $treffer = [];

        foreach (explode("\n", (string) file_get_contents($theme)) as $nummer => $zeile) {
            foreach ($this->fremdeAdressen($zeile) as $adresse) {
                $treffer[] = sprintf('theme.css:%d %s', $nummer + 1, $adresse);
            }
        }

        self::assertSame([], $treffer, implode(' | ', $treffer));

        // url() darf nur auf eigene Pfade zeigen.
        $urls = [];
        preg_match_all('/url\(\s*[\'"]?([^)\'"]+)/i', (string) file_get_contents($theme), $urls);

        foreach ($urls[1] as $url) {
            self::assertStringStartsWith('/', $url, 'theme.css lädt: ' . $url);
        }
    }

    /**
     * Das gerenderte HTML jeder Seite.
     */
    public function testDieAusgeliefertenSeitenLadenNichtsVonAussen(): void
    {
        $zugang = new ExplodierenderZugang();
        $router = new Router(
            new SecretStore($zugang),
            new RateLimiter($zugang, str_repeat('T', 32), 20),
        );

        $treffer = [];

        foreach (['/', '/s/AAAAAAAAAAAAAAAAAAAAAA', '/impressum', '/datenschutz', '/sicherheit'] as $pfad) {
            $html = $router->dispatch(new Request('GET', $pfad, '', '203.0.113.7'))->body;

            foreach ($this->fremdeAdressen($html) as $adresse) {
                $treffer[] = $pfad . ': ' . $adresse;
            }
        }

        self::assertSame([], $treffer, 'Ausgelieferte Seiten laden Fremdes: ' . implode(' | ', $treffer));
    }

    /**
     * @return list<string>
     */
    private function fremdeAdressen(string $text): array
    {
        $muster = [
            '~https?://[^\s"\'()<>]+~i',
            '~(?<![a-z:])//(?:cdn|fonts|ajax|unpkg|cdnjs)[^\s"\'()<>]*~i',
        ];

        $gefunden = [];

        foreach ($muster as $ausdruck) {
            $alle = [];
            preg_match_all($ausdruck, $text, $alle);

            foreach ($alle[0] as $adresse) {
                $adresse = rtrim($adresse, '.,;');

                foreach (self::ERLAUBT as $erlaubt) {
                    if (str_starts_with($adresse, $erlaubt)) {
                        continue 3;
                    }
                }

                foreach (self::HARMLOS as $harmlos) {
                    if (str_starts_with($adresse, $harmlos)) {
                        continue 2;
                    }
                }

                $gefunden[] = $adresse;
            }
        }

        return $gefunden;
    }

    /**
     * @return list<string>
     */
    private function dateien(string $ordner): array
    {
        $pfad = dirname(__DIR__, 2) . '/' . $ordner;
        $treffer = [];

        /** @var SplFileInfo $datei */
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($pfad)) as $datei) {
            if (!$datei->isFile()) {
                continue;
            }

            // theme.css hat einen eigenen Test, Schriften und Bilder sind binär.
            if (in_array($datei->getExtension(), ['php', 'js', 'css', 'html'], true)
                && $datei->getBasename() !== 'theme.css') {
                $treffer[] = (string) $datei->getRealPath();
            }
        }

        sort($treffer);

        return $treffer;
    }
}
