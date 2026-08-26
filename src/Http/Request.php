<?php

declare(strict_types=1);

namespace Einmalpost\Http;

/**
 * Eine eingehende Anfrage, reduziert auf das, was dieser Dienst braucht.
 *
 * Der User-Agent wird bewusst nicht eingelesen. Was nicht im Programm
 * ankommt, kann auch nicht versehentlich gespeichert werden.
 */
final class Request
{
    public function __construct(
        public readonly string $method,
        public readonly string $path,
        public readonly string $body,
        public readonly string $clientIp,
    ) {
    }

    /**
     * @param array<string, mixed> $server
     */
    public static function fromGlobals(array $server, string $body): self
    {
        $method = is_string($server['REQUEST_METHOD'] ?? null)
            ? strtoupper($server['REQUEST_METHOD'])
            : 'GET';

        $uri  = is_string($server['REQUEST_URI'] ?? null) ? $server['REQUEST_URI'] : '/';
        $path = parse_url($uri, PHP_URL_PATH);

        // X-Forwarded-For wird absichtlich nicht gelesen: Die Kopfzeile kann
        // jeder frei setzen, und das Rate-Limit wäre damit wirkungslos.
        $ip = is_string($server['REMOTE_ADDR'] ?? null) ? $server['REMOTE_ADDR'] : '';

        return new self($method, is_string($path) ? $path : '/', $body, $ip);
    }
}
