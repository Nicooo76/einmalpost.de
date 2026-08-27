<?php

declare(strict_types=1);

namespace Einmalpost\Tests\Unit;

use Einmalpost\Http\Request;
use Einmalpost\Http\Router;
use Einmalpost\RateLimiter;
use Einmalpost\SecretStore;
use Einmalpost\Tests\Support\ExplodierenderZugang;
use Einmalpost\View;
use PHPUnit\Framework\TestCase;

/**
 * Das Aussehen liegt nicht im Repository - theme.css, Schriften und
 * Bildmaterial werden getrennt aufgespielt. Ein fremder Klon hat sie also
 * nicht.
 *
 * Dann darf die Seite sie auch nicht anfordern. Ein `<link>` auf eine Datei,
 * die es dort nie geben wird, erzeugt bei jedem Seitenaufruf einen 404 - in
 * der Konsole jedes Besuchers, in jedem Protokoll, bei jedem Klon.
 *
 * Aufgefallen ist das nicht beim Lesen, sondern in der fortlaufenden Prüfung:
 * Die läuft ohne die Gestaltung, und der Konsolentest wurde rot.
 *
 * Geprüft wird über View::$gestaltungsdatei, nicht durch Verschieben der
 * wirklichen Datei. Die ist nirgends versioniert - ginge ein Testlauf
 * mittendrin zu Ende, wäre sie nur noch auf dem Server vorhanden, und der
 * nächste Abgleich mit --delete löschte sie auch dort.
 */
final class GestaltungOptionalTest extends TestCase
{
    private string $spielwiese = '';

    protected function setUp(): void
    {
        $this->spielwiese = (string) tempnam(sys_get_temp_dir(), 'gestaltung');
        unlink($this->spielwiese);
        mkdir($this->spielwiese, 0o700, true);
    }

    protected function tearDown(): void
    {
        View::$gestaltungsdatei = null;
        View::$bildmarkendatei  = null;

        if ($this->spielwiese !== '' && is_dir($this->spielwiese)) {
            exec(sprintf('rm -rf %s', escapeshellarg($this->spielwiese)));
        }
    }

    private function startseite(): string
    {
        $zugang = new ExplodierenderZugang();

        $router = new Router(
            new SecretStore($zugang),
            new RateLimiter($zugang, str_repeat('T', 32), 20),
        );

        return $router->dispatch(new Request('GET', '/', '', '203.0.113.7'))->body;
    }

    public function testOhneGestaltungStehtKeinVerweisDarauf(): void
    {
        View::$gestaltungsdatei = $this->spielwiese . '/gibt-es-nicht.css';

        $html = $this->startseite();

        self::assertStringNotContainsString(
            '/assets/theme.css',
            $html,
            'Fehlt die Datei, darf die Seite sie auch nicht anfordern - sonst bekommt '
            . 'jeder Klon bei jedem Aufruf einen 404.'
        );

        // Die schmucklose Fassung bleibt dagegen immer drin. Ohne sie wäre die
        // Seite unbedienbar statt nur schmucklos.
        self::assertStringContainsString('/assets/theme-default.css', $html);
    }

    public function testMitGestaltungStehtDerVerweisDa(): void
    {
        $datei = $this->spielwiese . '/theme.css';
        file_put_contents($datei, ":root { --probe: 1; }\n");

        View::$gestaltungsdatei = $datei;

        $html = $this->startseite();

        self::assertStringContainsString('/assets/theme.css', $html);
        self::assertStringContainsString('/assets/theme-default.css', $html);
    }

    /**
     * Die wirkliche Datei wird von diesem Test nie angefasst. Ohne diese
     * Zusicherung wäre der Umbau von oben eine Behauptung.
     */
    public function testDieWirklicheGestaltungBleibtUnberuehrt(): void
    {
        $echt = dirname(__DIR__, 2) . '/public/assets/theme.css';
        $vorher = is_file($echt) ? (string) md5_file($echt) : 'nicht vorhanden';

        View::$gestaltungsdatei = $this->spielwiese . '/gibt-es-nicht.css';
        $this->startseite();

        $nachher = is_file($echt) ? (string) md5_file($echt) : 'nicht vorhanden';

        self::assertSame($vorher, $nachher, 'Der Test hat die wirkliche theme.css verändert.');
    }

    /**
     * Und ohne gesetzte Eigenschaft gilt der wirkliche Pfad - sonst hinge der
     * Betrieb an einer Testeinstellung.
     */
    public function testOhneEinstellungGiltDerWirklichePfad(): void
    {
        View::$gestaltungsdatei = null;

        self::assertSame(
            is_file(dirname(__DIR__, 2) . '/public/assets/theme.css'),
            View::hatGestaltung()
        );
    }

    /**
     * Für die Bildmarke gilt dieselbe Regel wie für die Gestaltung: Sie ist
     * Bildmaterial, liegt nicht im Repository, und ein Klon darf sie deshalb
     * auch nicht anfordern - sonst drei 404 je Seitenaufruf.
     */
    public function testOhneBildmarkeStehtKeinVerweisDarauf(): void
    {
        View::$bildmarkendatei = $this->spielwiese . '/gibt-es-nicht.svg';

        $html = $this->startseite();

        self::assertStringNotContainsString('/assets/img/favicon', $html);
        self::assertStringNotContainsString('apple-touch-icon', $html);
    }

    public function testMitBildmarkeStehenDieDreiVerweise(): void
    {
        $datei = $this->spielwiese . '/favicon.svg';
        file_put_contents($datei, "<svg xmlns=\"http://www.w3.org/2000/svg\"/>\n");

        View::$bildmarkendatei = $datei;

        $html = $this->startseite();

        self::assertStringContainsString('/assets/img/favicon.svg', $html);
        self::assertStringContainsString('/assets/img/favicon.ico', $html);
        self::assertStringContainsString('/assets/img/apple-touch-icon.png', $html);
    }

    public function testOhneEinstellungGiltDerWirklichePfadDerBildmarke(): void
    {
        View::$bildmarkendatei = null;

        self::assertSame(
            is_file(dirname(__DIR__, 2) . '/public/assets/img/favicon.svg'),
            View::hatBildmarke()
        );
    }
}
