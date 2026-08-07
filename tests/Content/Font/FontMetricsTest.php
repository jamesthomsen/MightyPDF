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

    /**
     * The codes WinAnsiEncoding assigns no glyph to measure zero, not
     * the default width.
     *
     * They are encodable -- CP1252 maps a tab to itself, so a tab in a
     * name out of a database column reaches the content stream intact --
     * and a reader draws and advances nothing for them. Measuring them
     * at the 500-unit default adds half an em of ink that will not be
     * there, which moves every centred, right-aligned, wrapped and
     * justified line containing one.
     */
    public function testWinAnsiCodesWithNoGlyphMeasureNothing(): void
    {
        $metrics = FontMetrics::forWinAnsi([65 => 667]);

        self::assertSame(0, $metrics->widthOfCode(0x09), 'tab');
        self::assertSame(0, $metrics->widthOfCode(0x00), 'NUL');
        self::assertSame(0, $metrics->widthOfCode(0x1F), 'the last C0 control');
        self::assertSame(0, $metrics->widthOfCode(0x7F), 'DEL');
    }

    public function testForWinAnsiLeavesEveryOtherCodeAlone(): void
    {
        $metrics = FontMetrics::forWinAnsi([65 => 667], defaultWidth: 500);

        self::assertSame(667, $metrics->widthOfCode(65), 'a stated width');
        self::assertSame(500, $metrics->widthOfCode(0x20), 'space, which has a glyph');
        self::assertSame(500, $metrics->widthOfCode(0xE9), 'e-acute');
    }

    /**
     * A fixed-width font is still read through WinAnsiEncoding, so
     * Courier does not advance 600 units for a character it draws
     * nothing for either.
     */
    public function testFixedWidthStillMeasuresGlyphlessCodesAsZero(): void
    {
        $metrics = FontMetrics::fixedWidth(600);

        self::assertSame(0, $metrics->widthOfCode(0x09));
        // Two glyphs at 600 apiece; the tab between them adds nothing.
        self::assertSame(1200.0, $metrics->widthOf('ab', 1000.0));
        self::assertSame(1200.0, $metrics->widthOf("a\tb", 1000.0));
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
