<?php

declare(strict_types=1);

namespace Einmalpost;

/**
 * Zugangsdaten und Betriebsgrößen.
 *
 * Quelle ist config/config.php außerhalb des Web-Roots. Fehlt die Datei,
 * werden Umgebungsvariablen gelesen. Beides ist gleichwertig; Werte aus der
 * Datei haben Vorrang.
 *
 * Kein Wert aus dieser Klasse wird jemals in eine Antwort geschrieben.
 */
final class Config
{
    /** Der Platzhalter aus der Vorlage wird aktiv abgelehnt. */
    public const PEPPER_PLACEHOLDER = 'BITTE-ERSETZEN';

    /** Kürzere Pepper ergeben schwächere HMACs, als der Zweck verlangt. */
    public const PEPPER_MIN_LENGTH = 32;

    /**
     * @param string $ratePepper Rohbytes, nicht base64.
     */
    private function __construct(
        public readonly string $dsn,
        public readonly string $dbUser,
        public readonly string $dbPassword,
        public readonly string $ratePepper,
        public readonly int $rateMax,
    ) {
    }

    /**
     * Lädt aus config/config.php, sonst aus der Umgebung.
     *
     * @param string|null $file Abweichender Pfad, für Tests.
     */
    public static function load(?string $file = null): self
    {
        if ($file === null) {
            // Erlaubt es, die Konfiguration an einen anderen Ort zu legen -
            // etwa außerhalb des vhosts oder, im Test, auf eine eigene
            // Datenbank.
            $ausUmgebung = getenv('EINMALPOST_CONFIG');

            $file = is_string($ausUmgebung) && $ausUmgebung !== ''
                ? $ausUmgebung
                : dirname(__DIR__) . '/config/config.php';
        }

        if (is_file($file)) {
            /** @var mixed $contents */
            $contents = require $file;

            if (!is_array($contents)) {
                throw new ConfigError(sprintf('%s muss ein Array zurückgeben.', basename($file)));
            }

            /** @var array<string, mixed> $contents */
            return self::fromArray($contents);
        }

        return self::fromArray([
            'dsn'         => getenv('EINMALPOST_DSN'),
            'db_user'     => getenv('EINMALPOST_DB_USER'),
            'db_password' => getenv('EINMALPOST_DB_PASSWORD'),
            'rate_pepper' => getenv('EINMALPOST_RATE_PEPPER'),
            'rate_max'    => getenv('EINMALPOST_RATE_MAX'),
        ]);
    }

    /**
     * @param array<string, mixed> $values
     */
    public static function fromArray(array $values): self
    {
        $dsn        = self::requiredString($values, 'dsn');
        $dbUser     = self::requiredString($values, 'db_user');
        $dbPassword = self::optionalString($values, 'db_password');
        $pepperEncoded  = self::requiredString($values, 'rate_pepper');
        $rateMax    = self::requiredInt($values, 'rate_max');

        if ($pepperEncoded === self::PEPPER_PLACEHOLDER) {
            throw new ConfigError(
                'rate_pepper steht noch auf dem Platzhalter aus der Vorlage. '
                . "Erzeugen mit: php -r 'echo base64_encode(random_bytes(32)), PHP_EOL;'"
            );
        }

        $pepper = base64_decode($pepperEncoded, true);

        if ($pepper === false) {
            throw new ConfigError('rate_pepper ist kein gültiges base64.');
        }

        if (strlen($pepper) < self::PEPPER_MIN_LENGTH) {
            throw new ConfigError(sprintf(
                'rate_pepper ist zu kurz: %d Byte, mindestens %d erforderlich.',
                strlen($pepper),
                self::PEPPER_MIN_LENGTH
            ));
        }

        if ($rateMax < 1) {
            throw new ConfigError('rate_max muss mindestens 1 sein.');
        }

        return new self($dsn, $dbUser, $dbPassword, $pepper, $rateMax);
    }

    /**
     * @param array<string, mixed> $values
     */
    private static function requiredString(array $values, string $key): string
    {
        $value = $values[$key] ?? null;

        if (!is_string($value) || $value === '') {
            throw new ConfigError(sprintf('%s fehlt oder ist leer.', $key));
        }

        return $value;
    }

    /**
     * @param array<string, mixed> $values
     */
    private static function optionalString(array $values, string $key): string
    {
        $value = $values[$key] ?? null;

        return is_string($value) ? $value : '';
    }

    /**
     * @param array<string, mixed> $values
     */
    private static function requiredInt(array $values, string $key): int
    {
        $value = $values[$key] ?? null;

        if (is_int($value)) {
            return $value;
        }

        if (is_string($value) && $value !== '' && ctype_digit($value)) {
            return (int) $value;
        }

        throw new ConfigError(sprintf('%s fehlt oder ist keine ganze Zahl.', $key));
    }
}
