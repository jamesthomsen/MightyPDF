<?php

declare(strict_types=1);

namespace MightyPDF\Tests\Content\Svg;

use MightyPDF\Content\Svg\PathBounds;
use MightyPDF\Content\Svg\SvgPathParser;
use PHPUnit\Framework\TestCase;

final class PathBoundsTest extends TestCase
{
    public function testMeasuresAStraightSidedPath(): void
    {
        self::assertSame([10.0, 20.0, 40.0, 30.0], self::boundsOf('M10 20 L50 20 L50 50 Z'));
    }

    /**
     * A Bezier stays inside the box of its control points but does not
     * reach it. Measuring the control points instead would place a
     * gradient against a box larger than the shape, which shows up as
     * colours compressed towards the middle of it.
     */
    public function testCurvesAreMeasuredWhereTheyGoNotWhereTheirControlPointsAre(): void
    {
        // A curve from (0,0) to (100,0) pulled upward by control points
        // at y = 90: it peaks at three quarters of that.
        [, $y, , $height] = self::boundsOf('M0 0 C0 90 100 90 100 0');

        self::assertSame(0.0, $y);
        self::assertEqualsWithDelta(67.5, $height, 0.001);
    }

    public function testACurveThatDoublesBackIsMeasuredAtItsTurningPoint(): void
    {
        [$x, , $width] = self::boundsOf('M0 0 C-40 10 -40 20 0 30');

        self::assertEqualsWithDelta(-30.0, $x, 0.001);
        self::assertEqualsWithDelta(30.0, $width, 0.001);
    }

    /** Three collinear control points make the derivative linear, not degenerate. */
    public function testAStraightCurveIsMeasuredAtItsEnds(): void
    {
        self::assertSame([0.0, 0.0, 30.0, 0.0], self::boundsOf('M0 0 C10 0 20 0 30 0'));
    }

    public function testAnEmptyPathHasNoBox(): void
    {
        $bounds = new PathBounds();

        self::assertTrue($bounds->isEmpty());
        self::assertSame([0.0, 0.0, 0.0, 0.0], $bounds->box());
    }

    public function testArcsAreMeasuredThroughTheCurvesTheyBecome(): void
    {
        // A half circle of radius 25 from (0,0) to (50,0). The sweep
        // flag sends it through negative y, SVG's y running downwards.
        [$x, $y, $width, $height] = self::boundsOf('M0 0 A25 25 0 0 1 50 0');

        self::assertEqualsWithDelta(0.0, $x, 0.001);
        self::assertEqualsWithDelta(50.0, $width, 0.001);
        self::assertEqualsWithDelta(-25.0, $y, 0.01);
        self::assertEqualsWithDelta(25.0, $height, 0.01);
    }

    /** @return array{0: float, 1: float, 2: float, 3: float} */
    private static function boundsOf(string $d): array
    {
        $bounds = new PathBounds();
        SvgPathParser::apply($d, $bounds);

        return $bounds->box();
    }
}
