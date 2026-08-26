<?php

declare(strict_types=1);

namespace Einmalpost\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * make verify beweist, dass es bei einem roten Test und einem PHPStan-Fehler
 * abbricht. Es bricht aber nicht von selbst ab, wenn jemand eine ganze
 * Testdatei löscht - dann liefe es grün mit weniger Tests, und eine
 * gelöschte Absicherung fiele niemandem auf.
 *
 * Dieser Test hält eine Untergrenze je Ebene fest. Die Zahlen sind ein
 * Boden, kein Ziel: Sie dürfen bei neuer Absicherung angehoben werden, aber
 * nie stillschweigend gesenkt. Fällt eine Ebene darunter, wurde vermutlich
 * etwas gelöscht.
 */
final class MindestumfangTest extends TestCase
{
    private const MIN_UNIT_DATEIEN         = 8;
    private const MIN_UNIT_METHODEN        = 54;
    private const MIN_INTEGRATION_DATEIEN  = 8;
    private const MIN_INTEGRATION_METHODEN = 53;
    private const MIN_E2E_SPECS            = 8;
    private const MIN_E2E_FAELLE           = 39;

    private const WURZEL = __DIR__ . '/../..';

    public function testGenugEinheitstests(): void
    {
        [$dateien, $methoden] = $this->zaehlePhp(self::WURZEL . '/tests/Unit');

        // Diese Datei selbst zählt mit; sie ist eine echte Absicherung.
        self::assertGreaterThanOrEqual(
            self::MIN_UNIT_DATEIEN,
            $dateien,
            "Es gibt nur $dateien Einheitstest-Dateien, erwartet mindestens " . self::MIN_UNIT_DATEIEN
            . '. Wurde eine gelöscht?'
        );
        self::assertGreaterThanOrEqual(
            self::MIN_UNIT_METHODEN,
            $methoden,
            "Nur $methoden Einheitstest-Methoden, erwartet mindestens " . self::MIN_UNIT_METHODEN . '.'
        );
    }

    public function testGenugIntegrationstests(): void
    {
        [$dateien, $methoden] = $this->zaehlePhp(self::WURZEL . '/tests/Integration');

        self::assertGreaterThanOrEqual(
            self::MIN_INTEGRATION_DATEIEN,
            $dateien,
            "Nur $dateien Integrationstest-Dateien, erwartet mindestens " . self::MIN_INTEGRATION_DATEIEN . '.'
        );
        self::assertGreaterThanOrEqual(
            self::MIN_INTEGRATION_METHODEN,
            $methoden,
            "Nur $methoden Integrationstest-Methoden, erwartet mindestens " . self::MIN_INTEGRATION_METHODEN . '.'
        );
    }

    public function testGenugBrowsertests(): void
    {
        $specs = glob(self::WURZEL . '/tests/e2e/*.spec.js') ?: [];
        $faelle = 0;

        foreach ($specs as $datei) {
            $inhalt = (string) file_get_contents($datei);
            // test('...') und test(`...`), aber nicht test.describe(...).
            $faelle += preg_match_all('/(?<![.\w])test\s*\(\s*[\'"`]/', $inhalt);
        }

        self::assertGreaterThanOrEqual(
            self::MIN_E2E_SPECS,
            count($specs),
            'Nur ' . count($specs) . ' Browsertest-Dateien, erwartet mindestens ' . self::MIN_E2E_SPECS . '.'
        );
        self::assertGreaterThanOrEqual(
            self::MIN_E2E_FAELLE,
            $faelle,
            "Nur $faelle test()-Fälle in den Browsertests, erwartet mindestens " . self::MIN_E2E_FAELLE . '.'
        );
    }

    /**
     * @return array{int, int} Anzahl *Test.php-Dateien, Anzahl test-Methoden
     */
    private function zaehlePhp(string $ordner): array
    {
        $dateien  = glob($ordner . '/*Test.php') ?: [];
        $methoden = 0;

        foreach ($dateien as $datei) {
            $inhalt = (string) file_get_contents($datei);
            $methoden += preg_match_all('/function\s+test[A-Z]/', $inhalt);
        }

        return [count($dateien), $methoden];
    }
}
