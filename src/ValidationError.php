<?php

declare(strict_types=1);

namespace Einmalpost;

use RuntimeException;

/**
 * Eine Eingabe war nicht brauchbar.
 *
 * Führt zu einer Antwort mit Status 400 und einem festen Text. Nie zu einem
 * Fehler 500, nie zu einer Ausnahme, die nach außen dringt (Zusage 19).
 */
final class ValidationError extends RuntimeException
{
}
