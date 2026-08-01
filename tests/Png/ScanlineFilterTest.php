<?php

declare(strict_types=1);

namespace MightyPDF\Tests\Png;

use MightyPDF\Png\ScanlineFilter;
use PHPUnit\Framework\TestCase;

/**
 * The five filters in isolation. Their two callers -- PngImage and the
 * reader's Predictor -- have their own tests for how they frame rows;
 * these cover the arithmetic itself, including the cases the callers
 * cannot easily reach (a row above with no row of its own above it, and
 * Paeth's tie-breaking).
 */
final class ScanlineFilterTest extends TestCase
{
    private const string NO_ROW_ABOVE = "\x00\x00\x00";

    public function testNoneReturnsTheRowUnchanged(): void
    {
        self::assertSame("\x0A\x14\x1E", ScanlineFilter::reconstructRow(0, "\x0A\x14\x1E", self::NO_ROW_ABOVE, 1));
    }

    public function testSubAddsTheByteToItsLeft(): void
    {
        self::assertSame("\x0A\x1E\x3C", ScanlineFilter::reconstructRow(1, "\x0A\x14\x1E", self::NO_ROW_ABOVE, 1));
    }

    public function testUpAddsTheByteAbove(): void
    {
        self::assertSame("\x0F\x19\x23", ScanlineFilter::reconstructRow(2, "\x05\x05\x05", "\x0A\x14\x1E", 1));
    }

    public function testAverageAddsTheFlooredMeanOfLeftAndAbove(): void
    {
        // 5 + floor((0 + 10) / 2), 7 + floor((10 + 20) / 2), 9 + floor((22 + 30) / 2)
        self::assertSame("\x0A\x16\x23", ScanlineFilter::reconstructRow(3, "\x05\x07\x09", "\x0A\x14\x1E", 1));
    }

    public function testPaethPicksTheNeighbourNearestTheirLinearEstimate(): void
    {
        // Left 0/10/22 up 10/20/30 up-left 0/10/20: the estimate lands on
        // the byte above each time, so this adds 10, 20, 30.
        self::assertSame("\x0F\x1B\x27", ScanlineFilter::reconstructRow(4, "\x05\x07\x09", "\x0A\x14\x1E", 1));
    }

    public function testPaethBreaksTiesTowardsTheLeftNeighbour(): void
    {
        // All three neighbours are 0 for the first byte, so any tie-break
        // yields 9; the second byte has left 9, up 9, up-left 9 -- equally
        // distant -- and must resolve to the left neighbour, 9.
        self::assertSame("\x09\x12", ScanlineFilter::reconstructRow(4, "\x09\x09", "\x00\x09", 1));
    }

    public function testValuesWrapAtAByte(): void
    {
        self::assertSame("\xFF\x01", ScanlineFilter::reconstructRow(1, "\xFF\x02", "\x00\x00", 1));
    }

    public function testThePredictingNeighbourIsAWholePixelBack(): void
    {
        // Three colours at 8 bits: a red byte predicts the next red byte,
        // not the green one beside it.
        self::assertSame(
            "\x0A\x14\x1E\x0B\x16\x21",
            ScanlineFilter::reconstructRow(1, "\x0A\x14\x1E\x01\x02\x03", str_repeat("\x00", 6), 3),
        );
    }

    public function testAnEmptyRowReconstructsToNothing(): void
    {
        self::assertSame('', ScanlineFilter::reconstructRow(4, '', '', 1));
    }

    /**
     * An unknown filter type is reported by returning null, so that each
     * caller can raise the failure its own callers expect -- a broken PNG
     * or a broken PDF stream.
     */
    public function testReturnsNullForAnUnknownFilterType(): void
    {
        self::assertNull(ScanlineFilter::reconstructRow(5, "\x00\x00\x00", self::NO_ROW_ABOVE, 1));
        self::assertNull(ScanlineFilter::reconstructRow(-1, "\x00\x00\x00", self::NO_ROW_ABOVE, 1));
    }
}
