<?php

declare(strict_types=1);

namespace MightyPDF\Tests\Assembler\Types;

use MightyPDF\Assembler\Types\PdfHexString;
use PHPUnit\Framework\TestCase;

final class PdfHexStringTest extends TestCase
{
    public function testFormatsBytesAsHex(): void
    {
        self::assertSame('<48656c6c6f>', (new PdfHexString('Hello'))->format());
    }

    public function testFormatsEmptyString(): void
    {
        self::assertSame('<>', (new PdfHexString(''))->format());
    }
}
