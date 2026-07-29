<?php

declare(strict_types=1);

namespace MightyPDF\Tests\Assembler\Types;

use MightyPDF\Assembler\Types\PdfNull;
use PHPUnit\Framework\TestCase;

final class PdfNullTest extends TestCase
{
    public function testFormatsAsLiteralNull(): void
    {
        self::assertSame('null', (new PdfNull())->format());
    }
}
