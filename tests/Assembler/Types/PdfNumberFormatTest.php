<?php

declare(strict_types=1);

namespace MightyPDF\Tests\Assembler\Types;

use MightyPDF\Assembler\Types\PdfNumberFormat;
use PHPUnit\Framework\TestCase;

final class PdfNumberFormatTest extends TestCase
{
    public function testTrimsTrailingZerosAndDecimalPoint(): void
    {
        self::assertSame('1', PdfNumberFormat::format(1.0));
        self::assertSame('1.5', PdfNumberFormat::format(1.5));
        self::assertSame('1.25', PdfNumberFormat::format(1.250000));
    }

    public function testRejectsInfinity(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        PdfNumberFormat::format(INF);
    }
}
