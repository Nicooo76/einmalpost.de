<?php

declare(strict_types=1);

namespace Einmalpost\Tests\Integration;

use Einmalpost\RateLimiter;
use PDO;

/**
 * Zusage 16: Das Rate-Limit greift und läuft von selbst ab.
 * Zusage 18: Keine IP-Adresse im Klartext in der Datenbank.
 */
final class RateLimitTest extends IntegrationTestCase
{
    private const PEPPER = 'streng geheimer Testpepper, mindestens 32 Byte lang';

    private const IP = '203.0.113.42';

    private function limiter(int $max): RateLimiter
    {
        return new RateLimiter($this->database(), self::PEPPER, $max);
    }

    public function testBisZurGrenzeErlaubtDanachNicht(): void
    {
        $limiter = $this->limiter(3);

        self::assertTrue($limiter->allow(self::IP), 'Versuch 1');
        self::assertTrue($limiter->allow(self::IP), 'Versuch 2');
        self::assertTrue($limiter->allow(self::IP), 'Versuch 3');
        self::assertFalse($limiter->allow(self::IP), 'Versuch 4 muss abgelehnt werden.');
        self::assertFalse($limiter->allow(self::IP), 'und der fünfte auch.');
    }

    public function testVerschiedeneAdressenZaehlenGetrennt(): void
    {
        $limiter = $this->limiter(1);

        self::assertTrue($limiter->allow('203.0.113.1'));
        self::assertFalse($limiter->allow('203.0.113.1'));
        self::assertTrue($limiter->allow('203.0.113.2'), 'Eine andere Adresse hat ihr eigenes Kontingent.');
        self::assertTrue($limiter->allow('2001:db8::1'));
    }

    public function testDasFensterLaeuftVonSelbstAb(): void
    {
        $limiter = $this->limiter(2);

        $limiter->allow(self::IP);
        $limiter->allow(self::IP);
        self::assertFalse($limiter->allow(self::IP), 'Grenze erreicht.');

        // Fenster in die Vergangenheit schieben - das tut sonst die Zeit.
        $this->pdo()->exec(
            'UPDATE rate_limits SET expires_at = DATE_ADD(UTC_TIMESTAMP(), INTERVAL -1 SECOND)'
        );

        self::assertTrue(
            $limiter->allow(self::IP),
            'Nach Ablauf des Fensters beginnt die Zählung von vorn - ohne dass jemand aufräumen muss.'
        );
    }

    public function testNachAblaufBeginntDieZaehlungBeiEins(): void
    {
        $limiter = $this->limiter(2);
        $limiter->allow(self::IP);
        $limiter->allow(self::IP);

        $this->pdo()->exec(
            'UPDATE rate_limits SET expires_at = DATE_ADD(UTC_TIMESTAMP(), INTERVAL -1 SECOND)'
        );
        $limiter->allow(self::IP);

        self::assertSame(1, (int) $this->query('SELECT hits FROM rate_limits')->fetchColumn());
    }

    // ------------------------------------------------------------------
    // Zusage 18
    // ------------------------------------------------------------------

    public function testDieAdresseStehtNirgendsInDerDatenbank(): void
    {
        $this->limiter(10)->allow(self::IP);
        $this->limiter(10)->allow('2001:db8::dead:beef');

        $zeilen = $this->query('SELECT ip_hmac, hits, expires_at FROM rate_limits')->fetchAll(PDO::FETCH_NUM);

        self::assertNotSame([], $zeilen);

        $alles = '';

        foreach ($zeilen as $zeile) {
            foreach ($zeile as $wert) {
                $alles .= is_scalar($wert) ? (string) $wert : '';
            }
        }

        self::assertStringNotContainsString(self::IP, $alles, 'Die IPv4-Adresse steht im Klartext in der Tabelle.');
        self::assertStringNotContainsString('2001:db8', $alles, 'Die IPv6-Adresse steht im Klartext in der Tabelle.');
        self::assertStringNotContainsString('203.0.113', $alles);
    }

    public function testDerGespeicherteWertIstEinHmacUndKeineVerschluesselung(): void
    {
        $limiter = $this->limiter(10);
        $limiter->allow(self::IP);

        $gespeichert = $this->query('SELECT ip_hmac FROM rate_limits')->fetchColumn();

        self::assertIsString($gespeichert);
        self::assertSame(32, strlen($gespeichert), 'HMAC-SHA256 ist 32 Byte lang.');
        self::assertSame($limiter->fingerprint(self::IP), $gespeichert);
    }

    public function testDerFingerabdruckWechseltTaeglich(): void
    {
        $limiter = $this->limiter(10);

        $heute  = $limiter->fingerprint(self::IP, '2026-08-26');
        $morgen = $limiter->fingerprint(self::IP, '2026-08-27');

        self::assertNotSame($heute, $morgen, 'Derselbe Wert an zwei Tagen wäre ein dauerhaftes Merkmal.');
        self::assertSame($heute, $limiter->fingerprint(self::IP, '2026-08-26'), 'Am selben Tag gleich.');
    }

    public function testEinAndererPepperErgibtEinenAnderenFingerabdruck(): void
    {
        $einer   = new RateLimiter($this->database(), self::PEPPER, 10);
        $anderer = new RateLimiter($this->database(), 'ein völlig anderer Pepper mit genug Länge', 10);

        self::assertNotSame(
            $einer->fingerprint(self::IP, '2026-08-26'),
            $anderer->fingerprint(self::IP, '2026-08-26'),
            'Ohne den Pepper lässt sich der Fingerabdruck nicht nachrechnen.'
        );
    }

    public function testDieZeilenLaufenNachEinerStundeAb(): void
    {
        $this->limiter(10)->allow(self::IP);

        $sekunden = (int) $this->query(
            'SELECT TIMESTAMPDIFF(SECOND, UTC_TIMESTAMP(), expires_at) FROM rate_limits'
        )->fetchColumn();

        self::assertGreaterThan(3500, $sekunden);
        self::assertLessThanOrEqual(3600, $sekunden);
    }
}
