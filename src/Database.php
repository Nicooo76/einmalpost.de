<?php

declare(strict_types=1);

namespace Einmalpost;

use PDO;

/**
 * Datenbankverbindung.
 *
 * Die Verbindung entsteht erst beim ersten Zugriff. Das ist keine
 * Bequemlichkeit, sondern Voraussetzung für Zusage 4: GET /s/{id} darf die
 * Datenbank nicht anfassen, und wasUsed() macht das nachprüfbar.
 */
final class Database implements DatabaseAccess
{
    public const SQL_MODE_ANWEISUNG = "SET SESSION sql_mode='STRICT_ALL_TABLES'";

    private ?PDO $pdo = null;

    private int $connectCount = 0;

    public function __construct(private readonly Config $config)
    {
    }

    public function pdo(): PDO
    {
        if ($this->pdo instanceof PDO) {
            return $this->pdo;
        }

        ++$this->connectCount;

        $pdo = new PDO(
            $this->config->dsn,
            $this->config->dbUser,
            $this->config->dbPassword,
            [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                // Echte Prepared Statements. Emulierte würden die Werte in
                // PHP in den SQL-Text einsetzen - genau das soll hier nie
                // passieren.
                PDO::ATTR_EMULATE_PREPARES   => false,
                PDO::ATTR_STRINGIFY_FETCHES  => false,
            ]
        );

        // Der Zielserver läuft ohne Strict-Modus und würde zu lange Werte
        // stillschweigend kürzen, statt sie abzulehnen. Ein stillschweigend
        // gekürzter payload ist ein zerstörtes Geheimnis, das erst beim
        // Empfänger auffällt. Die Einstellung gilt nur für diese Verbindung
        // und ändert nichts am Server.
        //
        // Bewusst als Anweisung und nicht über PDO::MYSQL_ATTR_INIT_COMMAND:
        // Diese Konstante ist ab PHP 8.5 veraltet, und ihr Nachfolger
        // Pdo\Mysql::ATTR_INIT_COMMAND gibt es auf dem Zielserver (PHP 8.3)
        // noch nicht. Eine Anweisung läuft auf beiden.
        $pdo->exec(self::SQL_MODE_ANWEISUNG);

        $this->pdo = $pdo;

        return $pdo;
    }

    /**
     * Wurde überhaupt eine Verbindung aufgebaut?
     */
    public function wasUsed(): bool
    {
        return $this->pdo instanceof PDO;
    }

    public function connectCount(): int
    {
        return $this->connectCount;
    }
}
