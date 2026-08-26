<?php

declare(strict_types=1);

namespace Einmalpost\Tests\Unit;

use Einmalpost\Config;
use Einmalpost\ConfigError;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Die Konfiguration wird beim Hochfahren geprüft, nicht im Betrieb.
 *
 * Ein zu kurzer oder vergessener Pepper macht das Rate-Limit angreifbar:
 * Wer ihn erraten kann, kann die gespeicherten HMACs auf IP-Adressen
 * zurückrechnen. Deshalb wird beides aktiv abgelehnt.
 */
final class ConfigTest extends TestCase
{
    /**
     * @return array<string, mixed>
     */
    private static function gueltig(): array
    {
        return [
            'dsn'         => 'mysql:host=127.0.0.1;dbname=x;charset=utf8mb4',
            'db_user'     => 'jemand',
            'db_password' => 'egal',
            'rate_pepper' => base64_encode(str_repeat('P', 32)),
            'rate_max'    => 20,
        ];
    }

    public function testEineVollstaendigeKonfigurationWirdAngenommen(): void
    {
        $config = Config::fromArray(self::gueltig());

        self::assertSame('jemand', $config->dbUser);
        self::assertSame(20, $config->rateMax);
        // Der Pepper liegt als Rohbytes vor, nicht als base64.
        self::assertSame(str_repeat('P', 32), $config->ratePepper);
    }

    public function testDerPlatzhalterAusDerVorlageWirdAbgelehnt(): void
    {
        $this->expectException(ConfigError::class);
        $this->expectExceptionMessageMatches('/Platzhalter/');

        Config::fromArray(['rate_pepper' => Config::PEPPER_PLACEHOLDER] + self::gueltig());
    }

    public function testEinZuKurzerPepperWirdAbgelehnt(): void
    {
        $this->expectException(ConfigError::class);
        $this->expectExceptionMessageMatches('/zu kurz/');

        Config::fromArray(['rate_pepper' => base64_encode(str_repeat('P', 31))] + self::gueltig());
    }

    public function testEinPepperMitGenauDreissigZweiByteWirdAngenommen(): void
    {
        $config = Config::fromArray(['rate_pepper' => base64_encode(str_repeat('P', 32))] + self::gueltig());

        self::assertSame(32, strlen($config->ratePepper));
    }

    public function testEinPepperDerKeinBase64IstWirdAbgelehnt(): void
    {
        $this->expectException(ConfigError::class);

        Config::fromArray(['rate_pepper' => '!!! kein base64 !!!'] + self::gueltig());
    }

    /**
     * @return list<array{string}>
     */
    public static function pflichtfelder(): array
    {
        return [['dsn'], ['db_user'], ['rate_pepper']];
    }

    #[DataProvider('pflichtfelder')]
    public function testEinFehlendesPflichtfeldWirdAbgelehnt(string $feld): void
    {
        $werte = self::gueltig();
        unset($werte[$feld]);

        $this->expectException(ConfigError::class);
        $this->expectExceptionMessageMatches('/' . preg_quote($feld, '/') . '/');

        Config::fromArray($werte);
    }

    #[DataProvider('pflichtfelder')]
    public function testEinLeeresPflichtfeldWirdAbgelehnt(string $feld): void
    {
        $this->expectException(ConfigError::class);

        Config::fromArray([$feld => ''] + self::gueltig());
    }

    public function testEinLeeresPasswortIstErlaubt(): void
    {
        $werte = self::gueltig();
        unset($werte['db_password']);

        self::assertSame('', Config::fromArray($werte)->dbPassword);
    }

    /**
     * @return list<array{mixed}>
     */
    public static function unbrauchbareGrenzen(): array
    {
        return [[0], [-1], ['keine Zahl'], [''], [null], [1.5], [[]], [true]];
    }

    #[DataProvider('unbrauchbareGrenzen')]
    public function testEineUnbrauchbareGrenzeWirdAbgelehnt(mixed $wert): void
    {
        $this->expectException(ConfigError::class);

        Config::fromArray(['rate_max' => $wert] + self::gueltig());
    }

    public function testEineGrenzeAlsZeichenketteWirdAngenommen(): void
    {
        // Aus der Umgebung kommen Werte immer als Zeichenkette.
        self::assertSame(50, Config::fromArray(['rate_max' => '50'] + self::gueltig())->rateMax);
    }

    public function testEineFehlermeldungNenntNiemalsDenWert(): void
    {
        try {
            Config::fromArray(['rate_pepper' => base64_encode('zu kurz aber geheim')] + self::gueltig());
            self::fail('Erwartet wurde eine Ausnahme.');
        } catch (ConfigError $fehler) {
            self::assertStringNotContainsString('zu kurz aber geheim', $fehler->getMessage());
            self::assertStringNotContainsString(base64_encode('zu kurz aber geheim'), $fehler->getMessage());
        }
    }

    public function testEineDateiDieKeinArrayZurueckgibtWirdAbgelehnt(): void
    {
        $datei = tempnam(sys_get_temp_dir(), 'einmalpost-config-') . '.php';
        file_put_contents($datei, '<?php return "kein Array";');

        try {
            $this->expectException(ConfigError::class);
            Config::load($datei);
        } finally {
            unlink($datei);
        }
    }

    public function testEineDateiWirdGelesen(): void
    {
        $datei = tempnam(sys_get_temp_dir(), 'einmalpost-config-') . '.php';
        file_put_contents($datei, '<?php return ' . var_export(self::gueltig(), true) . ';');

        try {
            self::assertSame('jemand', Config::load($datei)->dbUser);
        } finally {
            unlink($datei);
        }
    }
}
