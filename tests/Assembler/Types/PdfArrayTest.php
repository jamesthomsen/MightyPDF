<?php

declare(strict_types=1);

namespace MightyPDF\Tests\Assembler\Types;

use MightyPDF\Assembler\Types\PdfArray;
use MightyPDF\Assembler\Types\PdfInteger;
use MightyPDF\Assembler\Types\PdfName;
use MightyPDF\Assembler\Types\PdfReference;
use PHPUnit\Framework\TestCase;

final class PdfArrayTest extends TestCase
{
    public function testFormatsEmptyArray(): void
    {
        self::assertSame('[]', (new PdfArray())->format());
    }

    public function testFormatsHomogeneousArray(): void
    {
        $array = new PdfArray(new PdfInteger(1), new PdfInteger(2), new PdfInteger(3));

        self::assertSame('[1 2 3]', $array->format());
    }

    public function testFormatsMixedTypeArray(): void
    {
        // Every element must already be a PdfValue -- this is what
        // structurally prevents the 2012 bug where a bare scalar could
        // leak into an array and crash format().
        $array = new PdfArray(new PdfName('Type'), new PdfInteger(2), new PdfReference(5));

        self::assertSame('[/Type 2 5 0 R]', $array->format());
    }

    public function testItemsAccessorPreservesOrder(): void
    {
        $a = new PdfInteger(1);
        $b = new PdfInteger(2);
        $array = new PdfArray($a, $b);

        self::assertSame([$a, $b], $array->items());
    }
}
