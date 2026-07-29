<?php

declare(strict_types=1);

namespace MightyPDF\Tests\Content\Font;

use MightyPDF\Content\Font\FontMetrics;
use PHPUnit\Framework\TestCase;

final class FontMetricsTest extends TestCase
{
    public function testWidthOfCodeFallsBackToDefaultWidthWhenUnknown(): void
    {
        $metrics = new FontMetrics([65 => 667], defaultWidth: 500);

        self::assertSame(667, $metrics->widthOfCode(65));
        self::assertSame(500, $metrics->widthOfCode(999));
    }

    public function testFixedWidthIgnoresTheTableEntirely(): void
    {
        $metrics = FontMetrics::fixedWidth(600);

        self::assertSame(600, $metrics->widthOfCode(65));
        self::assertSame(600, $metrics->widthOfCode(97));
    }

    public function testWidthOfScalesByFontSize(): void
    {
        // 'M' at 667/1000 em, at a 12pt size, is 8.004pt wide.
        $metrics = new FontMetrics([77 => 667]);

        self::assertEqualsWithDelta(8.004, $metrics->widthOf('M', 12.0), 0.0001);
    }

    public function testWidthOfSumsMultipleCharacters(): void
    {
        $metrics = new FontMetrics([72 => 100, 73 => 200]);

        // "HI" at 1000pt (so 1/1000 em == 1pt, easy arithmetic): 100 + 200 = 300.
        self::assertSame(300.0, $metrics->widthOf('HI', 1000.0));
    }
}
