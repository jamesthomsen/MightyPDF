<?php

declare(strict_types=1);

namespace MightyPDF\Tests\Content\Svg;

use MightyPDF\Content\Svg\SvgAspectRatio;
use PHPUnit\Framework\TestCase;

final class SvgAspectRatioTest extends TestCase
{
    /**
     * The default: the image fits inside its rectangle and is centred in
     * whichever direction is left over. A 100x50 image in a 100x100 box
     * keeps its shape and sits in the middle.
     */
    public function testTheDefaultFitsTheImageInsideAndCentresIt(): void
    {
        $placement = SvgAspectRatio::place(null, 0.0, 0.0, 100.0, 100.0, 100, 50);

        self::assertSame([100.0, 0.0, 0.0, -50.0, 0.0, 75.0], $placement['matrix']);
        self::assertFalse($placement['clip']);
    }

    public function testNoneStretchesTheImageToTheRectangle(): void
    {
        $placement = SvgAspectRatio::place('none', 10.0, 20.0, 100.0, 100.0, 100, 50);

        self::assertSame([100.0, 0.0, 0.0, -100.0, 10.0, 120.0], $placement['matrix']);
    }

    public function testSliceCoversTheRectangleAndClipsTheOverflow(): void
    {
        $placement = SvgAspectRatio::place('xMidYMid slice', 0.0, 0.0, 100.0, 100.0, 100, 50);

        // Scaled to cover: twice the size, so half of it hangs outside.
        self::assertSame(200.0, $placement['matrix'][0]);
        self::assertTrue($placement['clip']);
    }

    public function testAlignmentPutsTheImageAtOneEdgeOrTheOther(): void
    {
        $atMin = SvgAspectRatio::place('xMinYMin meet', 0.0, 0.0, 100.0, 100.0, 100, 50);
        $atMax = SvgAspectRatio::place('xMinYMax meet', 0.0, 0.0, 100.0, 100.0, 100, 50);

        // The image is 50 tall in a 100 box; its top edge sits at 0
        // against Min and at 50 against Max.
        self::assertSame(50.0, $atMin['matrix'][5], 'top of the box');
        self::assertSame(100.0, $atMax['matrix'][5], 'bottom of the box');
    }

    public function testHorizontalAlignmentToo(): void
    {
        $atMax = SvgAspectRatio::place('xMaxYMid meet', 0.0, 0.0, 100.0, 100.0, 50, 100);

        self::assertSame(50.0, $atMax['matrix'][4]);
    }

    /**
     * The matrix flips the image: PDF draws one from the bottom up, and
     * the whole drawing is already under a flip from being placed on the
     * page, so the image's first row has to be sent to the top edge of
     * its rectangle in SVG coordinates.
     */
    public function testTheImageIsPlacedTopRowFirst(): void
    {
        $placement = SvgAspectRatio::place('none', 5.0, 7.0, 20.0, 30.0, 10, 10);

        [, , , $d, , $f] = $placement['matrix'];

        self::assertSame(-30.0, $d, 'the vertical axis is inverted');
        self::assertSame(37.0, $f, 'and starts from the far edge');
    }

    public function testAnImageWithNoSizeIsSimplyStretched(): void
    {
        $placement = SvgAspectRatio::place('xMidYMid meet', 0.0, 0.0, 100.0, 40.0, 0, 0);

        self::assertSame([100.0, 0.0, 0.0, -40.0, 0.0, 40.0], $placement['matrix']);
        self::assertFalse($placement['clip']);
    }

    /** An image that already matches its rectangle needs no clip, slice or not. */
    public function testSliceOnAnExactFitDoesNotClip(): void
    {
        $placement = SvgAspectRatio::place('xMidYMid slice', 0.0, 0.0, 100.0, 50.0, 200, 100);

        self::assertFalse($placement['clip']);
    }
}
