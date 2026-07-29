<?php

declare(strict_types=1);

namespace MightyPDF\Tests\Assembler\Types;

use MightyPDF\Assembler\Types\WinAnsiEncoding;
use PHPUnit\Framework\TestCase;

final class WinAnsiEncodingTest extends TestCase
{
    public function testAsciiPassesThroughUnchanged(): void
    {
        self::assertSame('Hello, world!', WinAnsiEncoding::encode('Hello, world!'));
    }

    public function testLatin1SupplementCharactersEncodeToSingleBytes(): void
    {
        // é is U+00E9, which is byte 0xE9 in both Latin-1 and CP1252/WinAnsi.
        $encoded = WinAnsiEncoding::encode('café');

        self::assertSame(4, strlen($encoded));
        self::assertSame("caf\xE9", $encoded);
    }

    public function testUnmappableCharactersAreTransliteratedRatherThanFailing(): void
    {
        // A CJK character has no WinAnsi representation at all; this
        // should transliterate/substitute rather than throw, since v1
        // has no font embedding to fall back on for real glyph support.
        $encoded = WinAnsiEncoding::encode('日');

        self::assertIsString($encoded);
        self::assertNotSame('', $encoded);
    }
}
