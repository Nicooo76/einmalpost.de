<?php

/**
 * Schreibt eine Konfigurationsdatei für den Testlauf.
 *
 * Der PHP-Server der Browsertests muss die Testdatenbank treffen und nicht
 * die Entwicklungsdatenbank. Da config/config.php Vorrang vor der Umgebung
 * hat, bekommt er über EINMALPOST_CONFIG eine eigene Datei untergeschoben.
 *
 * Aufruf: php tools/write-test-config.php <zieldatei>
 */

declare(strict_types=1);

$ziel = $argv[1] ?? '';

if ($ziel === '') {
    fwrite(STDERR, 'Aufruf: php tools/write-test-config.php <zieldatei>' . PHP_EOL);

    exit(1);
}

function umgebung(string $name, string $ersatz = ''): string
{
    $wert = getenv($name);

    return is_string($wert) && $wert !== '' ? $wert : $ersatz;
}

$inhalt = sprintf(
    "<?php\n\n// Erzeugt von tools/write-test-config.php. Nur für den Testlauf.\n\n"
    . "return [\n    'dsn' => %s,\n    'db_user' => %s,\n    'db_password' => %s,\n"
    . "    'rate_pepper' => %s,\n    'rate_max' => %d,\n];\n",
    var_export(umgebung('EINMALPOST_TEST_DSN'), true),
    var_export(umgebung('EINMALPOST_TEST_DB_USER', get_current_user()), true),
    var_export(umgebung('EINMALPOST_TEST_DB_PASSWORD'), true),
    var_export(umgebung('EINMALPOST_TEST_RATE_PEPPER', base64_encode(str_repeat('T', 32))), true),
    (int) umgebung('EINMALPOST_TEST_RATE_MAX', '1000'),
);

if (!is_dir(dirname($ziel))) {
    mkdir(dirname($ziel), 0o755, true);
}

file_put_contents($ziel, $inhalt);

printf('Testkonfiguration geschrieben: %s%s', $ziel, PHP_EOL);
