<?php

declare(strict_types=1);

namespace Einmalpost;

use PDO;

/**
 * Zugang zur Datenbank.
 *
 * Als Schnittstelle geschnitten, damit im Test eine Fassung eingesetzt werden
 * kann, die beim ersten Zugriff explodiert. Nur so lässt sich beweisen, dass
 * GET /s/{id} die Datenbank wirklich nicht anfasst, statt es zu behaupten.
 */
interface DatabaseAccess
{
    public function pdo(): PDO;
}
