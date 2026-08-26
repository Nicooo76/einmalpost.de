<?php

/**
 * Gemeinsamer Einstieg für Einheits- und Integrationstests.
 *
 * Lädt ausschließlich den Composer-Autoloader. Es wird bewusst keine
 * Konfiguration und keine Datenbankverbindung aufgebaut: Tests, die eine
 * Datenbank brauchen, holen sie sich selbst und sagen das damit auch.
 */

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';
