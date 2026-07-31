<?php

declare(strict_types=1);

namespace MightyPDF\Tests\Crypt;

use MightyPDF\Crypt\Rc4;
use PHPUnit\Framework\TestCase;

/**
 * Checked against the published RC4 test vectors rather than against
 * itself. RC4 has to be hand-rolled (OpenSSL 3 dropped it and ext-mcrypt
 * is long gone), so a round-trip test would prove only that the same
 * mistake was made twice -- which is exactly the failure mode that
 * matters here, since a wrong keystream still round-trips perfectly.
 */
final class Rc4Test extends TestCase
{
    public function testMatchesThePublishedTestVectors(): void
    {
        $vectors = [
            ['Key', 'Plaintext', 'bbf316e8d940af0ad3'],
            ['Wiki', 'pedia', '1021bf0420'],
            ['Secret', 'Attack at dawn', '45a01f645fc35b383552544b9bf5'],
        ];

        foreach ($vectors as [$key, $plaintext, $expected]) {
            self::assertSame($expected, bin2hex(Rc4::apply($key, $plaintext)), "key \"$key\"");
        }
    }

    public function testIsItsOwnInverse(): void
    {
        $plaintext = random_bytes(1024);

        self::assertSame($plaintext, Rc4::apply('a key', Rc4::apply('a key', $plaintext)));
    }

    public function testHandlesAnEmptyMessage(): void
    {
        self::assertSame('', Rc4::apply('key', ''));
    }

    public function testAnEmptyKeyLeavesTheDataAlone(): void
    {
        // Degenerate rather than meaningful, but it must not divide by the
        // key length and blow up.
        self::assertSame('data', Rc4::apply('', 'data'));
    }
}
