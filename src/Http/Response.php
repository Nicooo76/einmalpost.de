<?php

declare(strict_types=1);

namespace Einmalpost\Http;

/**
 * Eine Antwort als Wert.
 *
 * Antworten werden gebaut und zurückgegeben, nicht unterwegs ausgegeben. Nur
 * so lassen sich Kopfzeilen und Rumpf im Test byteweise vergleichen, was für
 * Zusage 8 nötig ist.
 */
final class Response
{
    /**
     * @param array<string, string> $headers
     */
    public function __construct(
        public readonly int $status,
        public readonly string $body,
        public readonly array $headers = [],
    ) {
    }

    /**
     * @param array<string, mixed> $data
     * @param array<string, string> $headers
     */
    public static function json(int $status, array $data, array $headers = []): self
    {
        $body = json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);

        return new self($status, $body, ['Content-Type' => 'application/json; charset=utf-8'] + $headers);
    }

    /**
     * @param array<string, string> $headers
     */
    public static function html(int $status, string $html, array $headers = []): self
    {
        return new self($status, $html, ['Content-Type' => 'text/html; charset=utf-8'] + $headers);
    }

    /**
     * @param array<string, string> $weitere
     */
    public function withHeaders(array $weitere): self
    {
        return new self($this->status, $this->body, $weitere + $this->headers);
    }

    public function send(): void
    {
        http_response_code($this->status);

        foreach ($this->headers as $name => $wert) {
            header($name . ': ' . $wert, true);
        }

        echo $this->body;
    }
}
