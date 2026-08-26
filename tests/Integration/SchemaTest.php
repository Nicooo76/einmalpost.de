<?php

declare(strict_types=1);

namespace Einmalpost\Tests\Integration;

use PDO;

/**
 * Zusage 15: Das Schema enthält exakt die vorgesehenen Spalten.
 *
 * Geprüft wird auf Mengengleichheit, nicht auf Vorhandensein. Eine zusätzlich
 * eingefügte Spalte - etwa ein created_at, das jemand "nur zur Fehlersuche"
 * ergänzt - macht diese Tests rot. Genau dafür sind sie da.
 *
 * Zusage 18 hängt mit daran: Es darf keine Spalte geben, in der eine
 * IP-Adresse im Klartext landen könnte.
 */
final class SchemaTest extends IntegrationTestCase
{
    /**
     * Spalten, die es in keiner Tabelle dieses Schemas geben darf.
     * Was nicht gespeichert wird, kann niemand herausverlangen.
     */
    private const VERBOTENE_SPALTEN = [
        'created_at', 'created', 'inserted_at', 'timestamp',
        'ip', 'ip_address', 'client_ip', 'remote_addr',
        'user_agent', 'useragent', 'browser',
        'subject', 'title', 'filename', 'file_name', 'name',
        'view_count', 'views', 'hit_count', 'reads', 'read_count',
        'referrer', 'referer',
        'email', 'sender', 'recipient', 'user_id', 'owner',
    ];

    public function testSecretsHatGenauDreiSpalten(): void
    {
        self::assertSame(
            ['id', 'payload', 'expires_at'],
            $this->spaltenNamen('secrets'),
            'secrets hat andere Spalten als vorgesehen. Jede zusätzliche Spalte ist ein Datum, '
            . 'das jemand herausverlangen kann.'
        );
    }

    public function testSecretsHatDieVorgesehenenTypen(): void
    {
        $spalten = $this->spalten('secrets');

        self::assertSame('binary(16)', $spalten['id']['COLUMN_TYPE']);
        self::assertSame('longblob', $spalten['payload']['COLUMN_TYPE']);
        self::assertSame('datetime', $spalten['expires_at']['COLUMN_TYPE']);

        foreach (['id', 'payload', 'expires_at'] as $name) {
            self::assertSame('NO', $spalten[$name]['IS_NULLABLE'], $name . ' darf nicht NULL sein');
        }
    }

    public function testRateLimitsHatGenauDreiSpalten(): void
    {
        self::assertSame(
            ['ip_hmac', 'hits', 'expires_at'],
            $this->spaltenNamen('rate_limits')
        );
    }

    public function testRateLimitsSpeichertEinenHmacUndKeineAdresse(): void
    {
        $spalten = $this->spalten('rate_limits');

        // BINARY(32) ist die Länge eines HMAC-SHA256. Eine IPv4-Adresse als
        // Text bräuchte 15 Zeichen, eine IPv6-Adresse 45 - beides passt in
        // keine BINARY(32)-Spalte, ohne aufzufallen.
        self::assertSame('binary(32)', $spalten['ip_hmac']['COLUMN_TYPE']);
    }

    public function testKeineTabelleHatEineVerraeterischeSpalte(): void
    {
        $gefunden = [];

        foreach ($this->alleSpaltenImSchema() as $zeile) {
            $spalte = strtolower(self::alsText($zeile['COLUMN_NAME']));

            if (in_array($spalte, self::VERBOTENE_SPALTEN, true)) {
                $gefunden[] = self::alsText($zeile['TABLE_NAME']) . '.' . self::alsText($zeile['COLUMN_NAME']);
            }
        }

        self::assertSame([], $gefunden, 'Verbotene Spalten gefunden: ' . implode(', ', $gefunden));
    }

    public function testDasSchemaHatGenauDieseZweiTabellen(): void
    {
        $tabellen = $this->query(
                'SELECT TABLE_NAME FROM information_schema.TABLES '
                . 'WHERE TABLE_SCHEMA = DATABASE() ORDER BY TABLE_NAME'
            )
            ->fetchAll(PDO::FETCH_COLUMN);

        self::assertSame(['rate_limits', 'secrets'], $tabellen);
    }

    public function testPayloadGrenzeStehtAuchInDerDatenbank(): void
    {
        $klausel = $this->query(
                'SELECT CHECK_CLAUSE FROM information_schema.CHECK_CONSTRAINTS '
                . "WHERE CONSTRAINT_SCHEMA = DATABASE() AND CONSTRAINT_NAME = 'payload_hoechstens_16m'"
            )
            ->fetchColumn();

        self::assertIsString($klausel, 'Der CHECK-Constraint auf die Größengrenze fehlt.');
        self::assertStringContainsString(
            (string) \Einmalpost\SecretStore::PAYLOAD_MAX_BYTES,
            $klausel,
            'Der CHECK in der Datenbank nennt eine andere Grenze als der Code.'
        );
    }

    public function testBeideTabellenLaufenAufInnoDb(): void
    {
        $engines = $this->query(
                'SELECT TABLE_NAME, ENGINE FROM information_schema.TABLES '
                . 'WHERE TABLE_SCHEMA = DATABASE() ORDER BY TABLE_NAME'
            )
            ->fetchAll(PDO::FETCH_KEY_PAIR);

        self::assertSame(['rate_limits' => 'InnoDB', 'secrets' => 'InnoDB'], $engines);
    }

    public function testAufExpiresAtLiegtEinIndex(): void
    {
        foreach (['secrets', 'rate_limits'] as $tabelle) {
            $anweisung = $this->prepared(
                'SELECT DISTINCT COLUMN_NAME FROM information_schema.STATISTICS '
                . 'WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?'
            );
            $anweisung->execute([$tabelle]);
            $indizes = $anweisung->fetchAll(PDO::FETCH_COLUMN);

            self::assertIsArray($indizes);
            self::assertContains(
                'expires_at',
                $indizes,
                $tabelle . ' braucht einen Index auf expires_at, sonst wird das Aufräumen teuer.'
            );
        }
    }

    /**
     * @return list<string>
     */
    private function spaltenNamen(string $tabelle): array
    {
        return array_keys($this->spalten($tabelle));
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function spalten(string $tabelle): array
    {
        $anweisung = $this->prepared(
            'SELECT COLUMN_NAME, COLUMN_TYPE, IS_NULLABLE, COLUMN_KEY '
            . 'FROM information_schema.COLUMNS '
            . 'WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? '
            . 'ORDER BY ORDINAL_POSITION'
        );
        $anweisung->execute([$tabelle]);

        $ergebnis = [];

        /** @var array<string, mixed> $zeile */
        foreach ($anweisung->fetchAll() as $zeile) {
            $ergebnis[self::alsText($zeile['COLUMN_NAME'])] = $zeile;
        }

        return $ergebnis;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function alleSpaltenImSchema(): array
    {
        /** @var list<array<string, mixed>> $zeilen */
        $zeilen = $this->query(
                'SELECT TABLE_NAME, COLUMN_NAME FROM information_schema.COLUMNS '
                . 'WHERE TABLE_SCHEMA = DATABASE()'
            )
            ->fetchAll();

        return $zeilen;
    }
}
