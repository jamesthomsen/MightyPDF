<?php

declare(strict_types=1);

namespace MightyPDF\Tests\Assembler\Types;

use MightyPDF\Assembler\Types\PdfInteger;
use PHPUnit\Framework\TestCase;

final class PdfIntegerTest extends TestCase
{
    public function testFormatsPositive(): void
    {
        self::assertSame('5', (new PdfInteger(5))->format());
    }

    public function testFormatsNegative(): void
    {
        self::assertSame('-42', (new PdfInteger(-42))->format());
    }

    public function testFormatsZero(): void
    {
        self::assertSame('0', (new PdfInteger(0))->format());
    }

    public function testValueRoundTrips(): void
    {
        self::assertSame(103, (new PdfInteger(103))->value());
    }
}
