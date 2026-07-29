<?php

declare(strict_types=1);

namespace MightyPDF\Tests\Assembler\Types;

use MightyPDF\Assembler\Types\PdfReference;
use PHPUnit\Framework\TestCase;

final class PdfReferenceTest extends TestCase
{
    public function testFormatsWithDefaultGeneration(): void
    {
        self::assertSame('3 0 R', (new PdfReference(3))->format());
    }

    public function testFormatsWithExplicitGeneration(): void
    {
        self::assertSame('3 2 R', (new PdfReference(3, 2))->format());
    }

    public function testAccessors(): void
    {
        $ref = new PdfReference(7, 1);
        self::assertSame(7, $ref->objectId());
        self::assertSame(1, $ref->generation());
    }
}
