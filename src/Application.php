<?php

declare(strict_types=1);

namespace Einmalpost;

use Einmalpost\Http\Router;

/**
 * Setzt die Teile zusammen.
 *
 * Die Datenbankverbindung entsteht dabei nicht - sie wird erst beim ersten
 * Zugriff aufgebaut. Das ist Voraussetzung dafür, dass GET /s/{id} ohne
 * Datenbank auskommt.
 */
final class Application
{
    private function __construct(
        public readonly Config $config,
        public readonly Database $database,
        public readonly Router $router,
    ) {
    }

    public static function boot(?Config $config = null): self
    {
        $config   = $config ?? Config::load();
        $database = new Database($config);

        $router = new Router(
            new SecretStore($database),
            new RateLimiter($database, $config->ratePepper, $config->rateMax),
        );

        return new self($config, $database, $router);
    }
}
