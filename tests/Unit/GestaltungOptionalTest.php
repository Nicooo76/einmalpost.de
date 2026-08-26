<?php

declare(strict_types=1);

namespace Einmalpost\Tests\Unit;

use Einmalpost\Http\Request;
use Einmalpost\Http\Router;
use Einmalpost\RateLimiter;
use Einmalpost\SecretStore;
use Einmalpost\Tests\Support\ExplodierenderZugang;
use PHPUnit\Framework\TestCase;

/**
 * Das Aussehen liegt nicht im Repository - theme.css, Schriften und
 * Bildmaterial werden getrennt aufgespielt. Ein fremder Klon hat sie also
 * nicht.
 *
 * Dann darf die Seite sie auch nicht anfordern. Ein `<link>` auf eine Datei,
 * die es dort nie geben wird, erzeugt bei jedem Seitenaufruf einen 404 - in
 * der Konsole jedes Besuchers, in jedem Protokoll, bei jedem Klon. Das ist
 * kein Schönheitsfehler: Es ist eine Anfrage, die niemand beantworten kann,
 * und sie steht in jeder Fassung, die wir veröffentlichen.
 *
 * Aufgefallen ist das nicht beim Lesen, sondern in der fortlaufenden Prüfung -
 * die läuft ohne die Gestaltung, und der Konsolentest wurde rot.
 */
final class GestaltungOptionalTest extends TestCase
{
    private string $vorlage = '';

    private string $beiseite = '';

    protected function setUp(): void
    {
        $this->vorlage = dirname(__DIR__, 2) . '/public/assets/theme.css';
        $this->beiseite = $this->vorlage . '.beiseite-fuer-den-test';
    }

    protected function tearDown(): void
    {
        if (is_file($this->beiseite)) {
            rename($this->beiseite, $this->vorlage);
        }
    }

    /**
     * Die Startseite, gerendert wie im Betrieb - über den Router, nicht an
     * der Anwendung vorbei. Der Datenbankzugang wirft bei jeder Berührung,
     * denn eine Inhaltsseite darf ihn gar nicht erst anfassen.
     */
    private function kopfbereich(): string
    {
        $zugang = new ExplodierenderZugang();

        $router = new Router(
            new SecretStore($zugang),
            new RateLimiter($zugang, str_repeat('T', 32), 20),
        );

        return $router->dispatch(new Request('GET', '/', '', '203.0.113.7'))->body;
    }

    public function testOhneTheseCssStehtKeinVerweisDarauf(): void
    {
        self::assertFileExists($this->vorlage, 'Für diesen Test muss theme.css lokal da sein.');

        rename($this->vorlage, $this->beiseite);

        $html = $this->kopfbereich();

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

    public function testMitThemeCssStehtDerVerweisDa(): void
    {
        self::assertFileExists($this->vorlage);

        $html = $this->kopfbereich();

        self::assertStringContainsString('/assets/theme.css', $html);
        self::assertStringContainsString('/assets/theme-default.css', $html);
    }
}
