<?php

declare(strict_types=1);

namespace MightyPDF\Tests\Content;

use MightyPDF\Content\PathSink;
use MightyPDF\Content\Shapes;
use PHPUnit\Framework\TestCase;

final class ShapesTest extends TestCase
{
    public function testEllipseIsFourCurvesFromTheRightmostPoint(): void
    {
        $path = new RecordingPathSink();
        Shapes::ellipse($path, 100.0, 200.0, 50.0, 25.0);

        self::assertSame(
            ['moveTo', 'curveTo', 'curveTo', 'curveTo', 'curveTo', 'closePath'],
            $path->operations(),
        );
        self::assertSame([150.0, 200.0], $path->calls[0][1], 'starts at the rightmost point');
    }

    /**
     * Every point of the outline should sit on the ellipse, within the
     * error a cubic Bezier makes approximating a quarter-circle -- which
     * is about a part in a thousand, i.e. well under a printer's dot at
     * any size a page carries.
     */
    public function testEllipseControlPointsPutTheCurveOnTheEllipse(): void
    {
        $path = new RecordingPathSink();
        Shapes::ellipse($path, 0.0, 0.0, 40.0, 40.0);

        foreach ($path->points() as [$x, $y]) {
            // Endpoints lie exactly on the circle; control points lie
            // outside it by construction, so check the endpoints only.
            if (abs(hypot($x, $y) - 40.0) > 1e-9) {
                self::assertGreaterThan(40.0, hypot($x, $y), 'a control point should pull outwards');

                continue;
            }

            self::assertEqualsWithDelta(40.0, hypot($x, $y), 1e-9);
        }

        // The midpoint of the first quadrant, evaluated on the Bezier,
        // should be within a part in a thousand of the true arc.
        [, $start] = $path->calls[0];
        [, $curve] = $path->calls[1];

        $mid = self::cubicAt(
            $start,
            [$curve[0], $curve[1]],
            [$curve[2], $curve[3]],
            [$curve[4], $curve[5]],
            0.5,
        );

        self::assertEqualsWithDelta(40.0, hypot($mid[0], $mid[1]), 40.0 * 0.0003);
    }

    public function testEllipseRefusesNonPositiveRadii(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        Shapes::ellipse(new RecordingPathSink(), 0.0, 0.0, 10.0, 0.0);
    }

    public function testRoundedRectangleAlternatesLinesAndCorners(): void
    {
        $path = new RecordingPathSink();
        Shapes::roundedRectangle($path, 10.0, 20.0, 100.0, 50.0, 8.0);

        self::assertSame(
            [
                'moveTo',
                'lineTo', 'curveTo',
                'lineTo', 'curveTo',
                'lineTo', 'curveTo',
                'lineTo', 'curveTo',
                'closePath',
            ],
            $path->operations(),
        );

        foreach ($path->points() as [$x, $y]) {
            self::assertGreaterThanOrEqual(10.0 - 1e-9, $x);
            self::assertLessThanOrEqual(110.0 + 1e-9, $x);
            self::assertGreaterThanOrEqual(20.0 - 1e-9, $y);
            self::assertLessThanOrEqual(70.0 + 1e-9, $y);
        }
    }

    /**
     * A radius past half the shorter side would fold the outline through
     * itself. Capping there gives the stadium shape a caller asking for a
     * huge radius means, which is what CSS does with the same input.
     */
    public function testAnOverLargeCornerRadiusIsCappedRatherThanInvertingTheOutline(): void
    {
        $capped = new RecordingPathSink();
        Shapes::roundedRectangle($capped, 0.0, 0.0, 100.0, 40.0, 500.0);

        $exact = new RecordingPathSink();
        Shapes::roundedRectangle($exact, 0.0, 0.0, 100.0, 40.0, 20.0);

        self::assertEquals($exact->calls, $capped->calls);
    }

    public function testRegularPolygonPutsAVertexAtTheTopWhenRotatedNinety(): void
    {
        $points = Shapes::regularPolygonPoints(0.0, 0.0, 10.0, 3, 90.0);

        self::assertCount(3, $points);
        self::assertEqualsWithDelta(0.0, $points[0][0], 1e-9);
        self::assertEqualsWithDelta(10.0, $points[0][1], 1e-9);

        foreach ($points as [$x, $y]) {
            self::assertEqualsWithDelta(10.0, hypot($x, $y), 1e-9);
        }
    }

    public function testRegularPolygonNeedsThreeSides(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        Shapes::regularPolygonPoints(0.0, 0.0, 10.0, 2);
    }

    public function testPolygonNeedsTwoPoints(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        Shapes::polygon(new RecordingPathSink(), [[0.0, 0.0]]);
    }

    /**
     * A rotation about a point should leave that point where it is --
     * which is the property the whole "rotate about an origin" matrix
     * exists to have, and the one that is wrong if the translations are
     * composed in the other order.
     */
    public function testRotationAboutAPointLeavesThatPointFixed(): void
    {
        $matrix = Shapes::rotationMatrix(37.0, 120.0, 400.0);

        [$x, $y] = self::apply($matrix, 120.0, 400.0);

        self::assertEqualsWithDelta(120.0, $x, 1e-9);
        self::assertEqualsWithDelta(400.0, $y, 1e-9);
    }

    /** Positive degrees turn counter-clockwise, following PDF's Y-up axes. */
    public function testPositiveRotationIsCounterClockwise(): void
    {
        [$x, $y] = self::apply(Shapes::rotationMatrix(90.0), 10.0, 0.0);

        self::assertEqualsWithDelta(0.0, $x, 1e-9);
        self::assertEqualsWithDelta(10.0, $y, 1e-9);
    }

    public function testScaleAboutAPointLeavesThatPointFixed(): void
    {
        $matrix = Shapes::scaleMatrix(3.0, 0.5, 50.0, 60.0);

        [$x, $y] = self::apply($matrix, 50.0, 60.0);

        self::assertEqualsWithDelta(50.0, $x, 1e-9);
        self::assertEqualsWithDelta(60.0, $y, 1e-9);

        [$x, $y] = self::apply($matrix, 60.0, 80.0);

        self::assertEqualsWithDelta(80.0, $x, 1e-9);
        self::assertEqualsWithDelta(70.0, $y, 1e-9);
    }

    /**
     * @param array{float, float, float, float, float, float} $matrix
     *
     * @return array{float, float}
     */
    private static function apply(array $matrix, float $x, float $y): array
    {
        [$a, $b, $c, $d, $e, $f] = $matrix;

        return [$a * $x + $c * $y + $e, $b * $x + $d * $y + $f];
    }

    /**
     * @param array{float, float} $p0
     * @param array{float, float} $p1
     * @param array{float, float} $p2
     * @param array{float, float} $p3
     *
     * @return array{float, float}
     */
    private static function cubicAt(array $p0, array $p1, array $p2, array $p3, float $t): array
    {
        $u = 1.0 - $t;

        $at = static fn (int $i): float => $u ** 3 * $p0[$i]
            + 3 * $u ** 2 * $t * $p1[$i]
            + 3 * $u * $t ** 2 * $p2[$i]
            + $t ** 3 * $p3[$i];

        return [$at(0), $at(1)];
    }
}

/**
 * Records what was drawn into it rather than writing operators -- the
 * same trick Svg\PathBounds uses, and the reason PathSink is an interface
 * at all.
 */
final class RecordingPathSink implements PathSink
{
    /** @var list<array{string, list<float>}> */
    public array $calls = [];

    public function moveTo(float $x, float $y): static
    {
        $this->calls[] = ['moveTo', [$x, $y]];

        return $this;
    }

    public function lineTo(float $x, float $y): static
    {
        $this->calls[] = ['lineTo', [$x, $y]];

        return $this;
    }

    public function curveTo(float $x1, float $y1, float $x2, float $y2, float $x3, float $y3): static
    {
        $this->calls[] = ['curveTo', [$x1, $y1, $x2, $y2, $x3, $y3]];

        return $this;
    }

    public function closePath(): static
    {
        $this->calls[] = ['closePath', []];

        return $this;
    }

    /** @return list<string> */
    public function operations(): array
    {
        return array_map(static fn (array $call): string => $call[0], $this->calls);
    }

    /** @return list<array{float, float}> every coordinate pair mentioned */
    public function points(): array
    {
        $points = [];

        foreach ($this->calls as [, $arguments]) {
            for ($i = 0; $i < count($arguments); $i += 2) {
                $points[] = [$arguments[$i], $arguments[$i + 1]];
            }
        }

        return $points;
    }
}
