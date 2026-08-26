<?php

declare(strict_types=1);

namespace Einmalpost\Tests\Integration;

use Einmalpost\Config;
use Einmalpost\Database;
use PDO;
use PDOStatement;
use PHPUnit\Framework\TestCase;

/**
 * Basis für alle Integrationstests.
 *
 * Läuft gegen eine echte MariaDB. Kein SQLite, keine Attrappe: geprüft wird
 * unter anderem das Verhalten von DELETE ... RETURNING und die Wirkung des
 * Strict-Modus, und beides gibt es nirgendwo sonst.
 *
 * Die Testdatenbank legt `make testdb` vor jedem Lauf frisch an.
 */
abstract class IntegrationTestCase extends TestCase
{
    private static ?Database $shared = null;

    protected static function config(): Config
    {
        $dsn = getenv('EINMALPOST_TEST_DSN');

        if (!is_string($dsn) || $dsn === '') {
            self::fail(
                'EINMALPOST_TEST_DSN ist nicht gesetzt. Integrationstests laufen über '
                . '"make integration", das die Testdatenbank frisch anlegt und die Umgebung setzt.'
            );
        }

        $user     = getenv('EINMALPOST_TEST_DB_USER');
        $password = getenv('EINMALPOST_TEST_DB_PASSWORD');
        $pepper   = getenv('EINMALPOST_TEST_RATE_PEPPER');
        $rateMax  = getenv('EINMALPOST_TEST_RATE_MAX');

        return Config::fromArray([
            'dsn'         => $dsn,
            'db_user'     => is_string($user) && $user !== '' ? $user : get_current_user(),
            'db_password' => is_string($password) ? $password : '',
            // Fester Testwert: Die Tests sollen bei jedem Lauf dieselben
            // HMACs erzeugen. Ein Geheimnis ist das nicht - es steht hier.
            'rate_pepper' => is_string($pepper) && $pepper !== ''
                ? $pepper
                : base64_encode(str_repeat('T', 32)),
            'rate_max'    => is_string($rateMax) && $rateMax !== '' ? $rateMax : '1000',
        ]);
    }

    protected function database(): Database
    {
        return self::$shared ??= new Database(self::config());
    }

    protected function pdo(): PDO
    {
        return $this->database()->pdo();
    }

    /**
     * query() gibt laut Signatur auch false zurück. Mit ERRMODE_EXCEPTION
     * passiert das nicht - der Helfer hält das für die statische Analyse fest,
     * statt es an zwanzig Stellen zu behaupten.
     */
    protected function query(string $sql): PDOStatement
    {
        $anweisung = $this->pdo()->query($sql);

        self::assertInstanceOf(PDOStatement::class, $anweisung);

        return $anweisung;
    }

    /**
     * Wie query(), für Anweisungen mit Werten.
     */
    protected function prepared(string $sql): PDOStatement
    {
        $anweisung = $this->pdo()->prepare($sql);

        self::assertInstanceOf(PDOStatement::class, $anweisung);

        return $anweisung;
    }

    /**
     * Wert aus information_schema als Text.
     */
    protected static function alsText(mixed $wert): string
    {
        self::assertTrue(
            is_scalar($wert),
            'Erwartet wurde ein einfacher Wert, bekommen: ' . get_debug_type($wert)
        );

        /** @var scalar $wert */
        return (string) $wert;
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->pdo()->exec('DELETE FROM secrets');
        $this->pdo()->exec('DELETE FROM rate_limits');
    }
}
