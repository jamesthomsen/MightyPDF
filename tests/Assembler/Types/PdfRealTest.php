<?php

declare(strict_types=1);

namespace MightyPDF\Tests\Assembler\Types;

use MightyPDF\Assembler\Types\PdfReal;
use PHPUnit\Framework\TestCase;

final class PdfRealTest extends TestCase
{
    public function testFormatsWholeNumberWithoutTrailingZeros(): void
    {
        self::assertSame('12', (new PdfReal(12.0))->format());
    }

    public function testFormatsFractional(): void
    {
        self::assertSame('612.792', (new PdfReal(612.792))->format());
    }

    public function testFormatsNegative(): void
    {
        self::assertSame('-0.5', (new PdfReal(-0.5))->format());
    }

    public function testNormalizesNegativeZero(): void
    {
        self::assertSame('0', (new PdfReal(-0.0))->format());
    }

    public function testNeverUsesScientificNotation(): void
    {
        self::assertSame('0.0001', (new PdfReal(0.0001))->format());
        self::assertStringNotContainsString('E', strtoupper((new PdfReal(1_000_000_000_000.0))->format()));
        self::assertSame('1000000000000', (new PdfReal(1_000_000_000_000.0))->format());
    }

    public function testRejectsNonFiniteValues(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new PdfReal(NAN))->format();
    }
}
