<?php

declare(strict_types=1);

namespace MightyPDF\Tests\Assembler\Types;

use MightyPDF\Assembler\Types\PdfName;
use PHPUnit\Framework\TestCase;

final class PdfNameTest extends TestCase
{
    public function testFormatsSimpleName(): void
    {
        self::assertSame('/Catalog', (new PdfName('Catalog'))->format());
    }

    public function testEscapesNumberSign(): void
    {
        // The number sign introduces an escape, so a literal "#" in the
        // name must itself be escaped -- otherwise it would be ambiguous
        // with an escape sequence.
        self::assertSame('/A#23B', (new PdfName('A#B'))->format());
    }

    public function testEscapesSpace(): void
    {
        self::assertSame('/A#20B', (new PdfName('A B'))->format());
    }

    public function testEscapesControlBytes(): void
    {
        // Regression test for the 2012 bug: the old escape table searched
        // for the *literal* 4-character PHP string '\x00' instead of the
        // actual null byte, so this never matched. Assert real bytes are
        // escaped correctly.
        self::assertSame('/A#00B', (new PdfName("A\x00B"))->format());
        self::assertSame('/A#0AB', (new PdfName("A\nB"))->format());
    }

    public function testEscapesDelimiters(): void
    {
        self::assertSame('/A#28B#29', (new PdfName('A(B)'))->format());
        self::assertSame('/A#2FB', (new PdfName('A/B'))->format());
    }

    public function testLeavesRegularCharactersUnescaped(): void
    {
        self::assertSame('/Helvetica-Bold_v2.1', (new PdfName('Helvetica-Bold_v2.1'))->format());
    }
}
