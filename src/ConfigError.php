<?php

declare(strict_types=1);

namespace Einmalpost;

use RuntimeException;

/**
 * Fehler in der Konfiguration.
 *
 * Wird beim Hochfahren geworfen, nie während einer Anfrage beantwortet.
 * Die Meldung enthält niemals einen Wert aus der Konfiguration, nur den
 * Namen des betroffenen Schlüssels.
 */
final class ConfigError extends RuntimeException
{
}
