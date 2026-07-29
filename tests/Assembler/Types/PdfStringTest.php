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
}
