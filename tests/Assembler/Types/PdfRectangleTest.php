<?php

declare(strict_types=1);

namespace MightyPDF\Tests\Assembler\Types;

use MightyPDF\Assembler\Types\PdfRectangle;
use PHPUnit\Framework\TestCase;

final class PdfRectangleTest extends TestCase
{
    public function testFormatsIntegerAlignedCoordinates(): void
    {
        self::assertSame('[0 0 612 792]', (new PdfRectangle(0, 0, 612, 792))->format());
    }

    public function testFormatsFractionalCoordinates(): void
    {
        // Unlike the 2012 implementation (which forced everything through
        // an Integer type), fractional coordinates must round-trip
        // exactly -- needed for precise text/form-field placement.
        self::assertSame('[10.5 20.25 100.5 200.75]', (new PdfRectangle(10.5, 20.25, 100.5, 200.75))->format());
    }
}
