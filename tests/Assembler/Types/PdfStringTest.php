<?php

declare(strict_types=1);

namespace MightyPDF\Tests\Assembler\Types;

use MightyPDF\Assembler\Types\PdfString;
use PHPUnit\Framework\TestCase;

final class PdfStringTest extends TestCase
{
    public function testFormatsPlainAscii(): void
    {
        self::assertSame('(Hello)', PdfString::latin1('Hello')->format());
    }

    public function testEscapesParensAndBackslash(): void
    {
        self::assertSame('(a\\(b\\)c\\\\d)', PdfString::latin1('a(b)c\\d')->format());
    }

    public function testEscapesControlBytes(): void
    {
        // Regression test for the 2012 bug: the old escape table searched
        // for literal PHP strings like '\x08' instead of the actual
        // control byte, so it never matched real bytes.
        self::assertSame('(a\\nb\\rc\\td)', PdfString::latin1("a\nb\rc\td")->format());
        self::assertSame('(a\\bb\\fc)', PdfString::latin1("a\x08b\x0Cc")->format());
    }

    public function testBackslashIsEscapedOnlyOnceEvenAdjacentToOtherEscapes(): void
    {
        // Input bytes: backslash, "(", backslash. If backslash weren't
        // handled first/exactly-once, this could double-escape or
        // mis-nest with the paren escaping.
        $backslash = '\\';
        $input = $backslash . '(' . $backslash;
        $expected = '(' . $backslash . $backslash . $backslash . '(' . $backslash . $backslash . ')';

        self::assertSame($expected, PdfString::latin1($input)->format());
    }

    public function testUtf16beIncludesByteOrderMark(): void
    {
        $formatted = PdfString::utf16be('A')->format();
        self::assertSame('(' . "\xFE\xFF" . "\x00A" . ')', $formatted);
    }

    public function testTextKeepsAsciiAsAPlainLiteral(): void
    {
        self::assertSame('(FirstName)', PdfString::text('FirstName')->format());
        self::assertSame('()', PdfString::text('')->format());
    }

    public function testTextPromotesNonAsciiToUtf16be(): void
    {
        self::assertSame(
            '(' . "\xFE\xFF" . "\x00c\x00a\x00f\x00\xE9" . ')',
            PdfString::text('café')->format(),
        );
    }

    /**
     * U+2810 encodes as 0x28 0x10 in UTF-16BE, and 0x28 is "(" -- so the
     * byte-level escaping has to fire inside a code unit and still
     * round-trip, or the string terminates early and corrupts the file.
     */
    public function testTextEscapesDelimiterBytesInsideUtf16beCodeUnits(): void
    {
        $formatted = PdfString::text("\u{2810}")->format();

        self::assertSame('(' . "\xFE\xFF" . '\\(' . "\x10" . ')', $formatted);

        // Unescaping byte-for-byte gets the original code unit back.
        $inner = substr($formatted, 1, -1);
        self::assertSame("\xFE\xFF\x28\x10", str_replace('\\(', '(', $inner));
    }
}
