<?php

declare(strict_types=1);

namespace Einmalpost\Tests\Unit;

use Einmalpost\Base64Url;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * base64url ohne Auffüllzeichen.
 *
 * Das Dekodieren muss streng sein: Was nicht ins Alphabet gehört, wird
 * abgelehnt und nicht stillschweigend übergangen.
 */
final class Base64UrlTest extends TestCase
{
    public function testHinUndZurueck(): void
    {
        self::assertSame('', Base64Url::encode(''));

        foreach ([1, 2, 3, 15, 16, 17, 284, 1000, 65536] as $laenge) {
            $roh = random_bytes($laenge);

            self::assertSame($roh, Base64Url::decode(Base64Url::encode($roh)), 'Länge ' . $laenge);
        }
    }

    public function testDasAlphabetEnthaeltKeinPlusUndKeinenSchraegstrich(): void
    {
        // 0xFB 0xFF erzeugt in klassischem base64 ein "+" bzw. "/".
        $kodiert = Base64Url::encode("\xFB\xFF\xBF\xFE");

        self::assertMatchesRegularExpression('/\A[A-Za-z0-9_-]+\z/', $kodiert);
        self::assertStringNotContainsString('+', $kodiert);
        self::assertStringNotContainsString('/', $kodiert);
        self::assertStringNotContainsString('=', $kodiert);
    }

    public function testEineIdHatZweiundzwanzigZeichen(): void
    {
        self::assertSame(22, strlen(Base64Url::encode(random_bytes(16))));
    }

    /**
     * @return list<array{string}>
     */
    public static function unbrauchbareEingaben(): array
    {
        return [
            [''],
            ['a'],                         // Länge, die aus keiner Kodierung entsteht
            ['AAAAA'],
            ['AA=='],                      // klassisches base64 mit Auffüllzeichen
            ['AA+A'],
            ['AA/A'],
            ['ÄÖÜ'],
            ['🔑'],
            ["AA\x00AA"],
            ['AA AA'],
            ["AA\nAA"],
            ['<script>'],
            ['../../etc/passwd'],
        ];
    }

    #[DataProvider('unbrauchbareEingaben')]
    public function testUnbrauchbareEingabenGebenNull(string $eingabe): void
    {
        self::assertNull(Base64Url::decode($eingabe));
    }
}
