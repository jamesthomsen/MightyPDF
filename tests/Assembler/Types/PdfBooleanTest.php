<?php

declare(strict_types=1);

namespace MightyPDF\Tests\Assembler\Types;

use MightyPDF\Assembler\Types\PdfBoolean;
use PHPUnit\Framework\TestCase;

final class PdfBooleanTest extends TestCase
{
    public function testFormatsTrue(): void
    {
        self::assertSame('true', (new PdfBoolean(true))->format());
    }

    public function testFormatsFalse(): void
    {
        self::assertSame('false', (new PdfBoolean(false))->format());
    }
}
