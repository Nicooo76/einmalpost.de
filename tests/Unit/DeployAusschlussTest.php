<?php

declare(strict_types=1);

namespace Einmalpost\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Was nicht ins Repository gehört, gehört auch nicht auf den Server.
 *
 * Der Deploy gleicht das Arbeitsverzeichnis ab - und das enthält mehr als das
 * Repository: die Betriebsanleitung mit Serverpfaden, die Deploy-Einstellungen
 * mit Host und Benutzernamen, den Prüfbericht und die Behauptungssammlung mit
 * ihren benannten Schwachstellen.
 *
 * Über HTTP erreichbar wäre davon nichts, weil der Dokumentenstamm eine Ebene
 * tiefer liegt. Aber wer den Webserver-Benutzer erlangt, fände dort eine
 * Landkarte - und dafür gibt es keinen Grund, denn der Dienst braucht keine
 * dieser Dateien.
 *
 * Aufgefallen ist das in einer Durchsicht, nicht durch einen Test. Deshalb
 * dieser hier.
 */
final class DeployAusschlussTest extends TestCase
{
    /**
     * @return list<array{string, string}> Dateimuster und Begründung
     */
    public static function privateDateien(): array
    {
        return [
            ['BETRIEB.local.md', 'Serverpfade, Zugänge, Betriebsschritte'],
            ['deploy.local.mk', 'Host, Benutzername und Zielverzeichnis'],
            ['PRUEFBERICHT.md', 'Sicherheitsanalyse mit benannten Schwachstellen'],
            ['UEBERGABE.md', 'Behauptungssammlung, nennt offene Punkte'],
            ['config/config.php', 'Zugangsdaten zur Datenbank'],
            ['.env', 'Zugangsdaten'],
            ['deploy.local.mk.example', 'Vorlage ohne echte Werte - der Dienst braucht sie nicht'],
        ];
    }

    private function ausschlussliste(): string
    {
        $makefile = (string) file_get_contents(dirname(__DIR__, 2) . '/Makefile');

        $anfang = strpos($makefile, 'DEPLOY_AUSSCHLUSS');
        self::assertNotFalse($anfang, 'DEPLOY_AUSSCHLUSS steht nicht im Makefile.');

        // Die Zuweisung endet an der ersten Zeile ohne Fortsetzungszeichen.
        $rest = substr($makefile, $anfang);
        $zeilen = explode("\n", $rest);
        $liste = '';

        foreach ($zeilen as $zeile) {
            $liste .= $zeile . "\n";

            if (!str_ends_with(rtrim($zeile), '\\')) {
                break;
            }
        }

        return $liste;
    }

    #[DataProvider('privateDateien')]
    public function testDerDeploySpieltNichtsPrivatesAuf(string $datei, string $warum): void
    {
        $liste = $this->ausschlussliste();

        // Entweder die Datei selbst oder ein Muster, das sie erfasst.
        $erfasst = str_contains($liste, "'" . $datei . "'")
            || str_contains($liste, "'*.local.md'") && str_ends_with($datei, '.local.md')
            || str_contains($liste, "'*.local.mk'") && str_ends_with($datei, '.local.mk')
            || str_contains($liste, "'*.example'") && str_ends_with($datei, '.example');

        self::assertTrue(
            $erfasst,
            sprintf(
                "%s wird vom Deploy nicht ausgeschlossen und landet damit auf dem Server.\n"
                . "Grund, warum sie dort nicht hingehört: %s\n\nAusschlussliste:\n%s",
                $datei,
                $warum,
                $liste
            )
        );
    }

    /**
     * Gegenprobe: Was ausgeliefert werden MUSS, darf nicht ausgeschlossen
     * sein. Eine Liste, die alles ausschließt, bestünde diesen Test sonst.
     */
    public function testDerDeploySpieltDasNoetigeAuf(): void
    {
        $liste = $this->ausschlussliste();

        foreach (['public', 'src', 'bin', 'db'] as $noetig) {
            self::assertStringNotContainsString(
                "--exclude '" . $noetig . "'",
                $liste,
                $noetig . ' wird gebraucht und darf nicht ausgeschlossen sein.'
            );
        }
    }
}
