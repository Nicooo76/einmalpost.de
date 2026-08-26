<?php

declare(strict_types=1);

namespace Einmalpost\Tests\Support;

use Einmalpost\DatabaseAccess;
use PDO;
use RuntimeException;

/**
 * Ein Datenbankzugang, der bei jeder Berührung explodiert.
 *
 * Damit lässt sich beweisen, dass ein Codepfad die Datenbank nicht anfasst,
 * statt es zu behaupten: Wer sie doch anfasst, bekommt eine Ausnahme, und
 * der Test wird rot.
 *
 * Das ist kein Ersatz für die Datenbank, sondern ein Messgerät. Die echten
 * Abläufe werden weiterhin gegen eine echte MariaDB geprüft.
 */
final class ExplodierenderZugang implements DatabaseAccess
{
    public int $versuche = 0;

    public function pdo(): PDO
    {
        ++$this->versuche;

        throw new RuntimeException(
            'Die Datenbank wurde angefasst, obwohl dieser Weg ohne sie auskommen muss.'
        );
    }
}
