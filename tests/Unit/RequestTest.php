<?php

declare(strict_types=1);

namespace Einmalpost\Tests\Unit;

use Einmalpost\Http\Request;
use PHPUnit\Framework\TestCase;

/**
 * Was aus der Umgebung des Servers übernommen wird - und was nicht.
 */
final class RequestTest extends TestCase
{
    public function testMethodeUndPfadWerdenUebernommen(): void
    {
        $anfrage = Request::fromGlobals(
            ['REQUEST_METHOD' => 'post', 'REQUEST_URI' => '/api/create', 'REMOTE_ADDR' => '203.0.113.1'],
            '{"a":1}'
        );

        self::assertSame('POST', $anfrage->method, 'Die Methode wird großgeschrieben.');
        self::assertSame('/api/create', $anfrage->path);
        self::assertSame('{"a":1}', $anfrage->body);
        self::assertSame('203.0.113.1', $anfrage->clientIp);
    }

    public function testDerAbfrageteilGehoertNichtZumPfad(): void
    {
        $anfrage = Request::fromGlobals(
            ['REQUEST_METHOD' => 'GET', 'REQUEST_URI' => '/s/abc?utm_source=slack&x=1', 'REMOTE_ADDR' => '::1'],
            ''
        );

        self::assertSame('/s/abc', $anfrage->path);
    }

    /**
     * X-Forwarded-For kann jeder frei setzen. Würde das Rate-Limit darauf
     * hören, ließe es sich mit einer erfundenen Kopfzeile umgehen.
     */
    public function testEineWeitergereichteAdresseWirdNichtUebernommen(): void
    {
        $anfrage = Request::fromGlobals(
            [
                'REQUEST_METHOD'        => 'POST',
                'REQUEST_URI'           => '/api/create',
                'REMOTE_ADDR'           => '203.0.113.1',
                'HTTP_X_FORWARDED_FOR'  => '198.51.100.99',
                'HTTP_X_REAL_IP'        => '198.51.100.98',
                'HTTP_CLIENT_IP'        => '198.51.100.97',
                'HTTP_FORWARDED'        => 'for=198.51.100.96',
            ],
            ''
        );

        self::assertSame('203.0.113.1', $anfrage->clientIp);
    }

    public function testDerUserAgentWirdNichtEingelesen(): void
    {
        $anfrage = Request::fromGlobals(
            [
                'REQUEST_METHOD'  => 'GET',
                'REQUEST_URI'     => '/',
                'REMOTE_ADDR'     => '203.0.113.1',
                'HTTP_USER_AGENT' => 'Slackbot-LinkExpanding 1.0',
                'HTTP_REFERER'    => 'https://beispiel.invalid/woher',
            ],
            ''
        );

        // Die Anfrage hat gar kein Feld dafür. Was nicht im Programm
        // ankommt, kann auch nicht versehentlich gespeichert werden.
        //
        // angekuendigteGroesse ist die Content-Length, also eine Zahl. Sie
        // wird gebraucht, um einen von PHP wegen post_max_size verworfenen
        // Rumpf zu erkennen, und sagt über die absendende Person nichts aus.
        $felder = array_keys(get_object_vars($anfrage));

        self::assertSame(['method', 'path', 'body', 'clientIp', 'angekuendigteGroesse'], $felder);
    }

    public function testFehlendeAngabenErgebenBrauchbareVorgaben(): void
    {
        $anfrage = Request::fromGlobals([], '');

        self::assertSame('GET', $anfrage->method);
        self::assertSame('/', $anfrage->path);
        self::assertSame('', $anfrage->clientIp);
    }

    public function testUnbrauchbareAngabenErgebenKeineAusnahme(): void
    {
        $anfrage = Request::fromGlobals(
            ['REQUEST_METHOD' => ['keine Zeichenkette'], 'REQUEST_URI' => 42, 'REMOTE_ADDR' => null],
            ''
        );

        self::assertSame('GET', $anfrage->method);
        self::assertSame('/', $anfrage->path);
        self::assertSame('', $anfrage->clientIp);
    }
}
