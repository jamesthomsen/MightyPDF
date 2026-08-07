<?php

declare(strict_types=1);

namespace MightyPDF\Content;

/**
 * The geometry of the shapes PDF has no operator for, written into a
 * PathSink.
 *
 * PDF draws exactly two things: straight lines and cubic Beziers. There is
 * a rectangle operator and nothing else, so a circle, an ellipse, a
 * rounded corner and a regular polygon are all "some arithmetic, then
 * moveTo/curveTo". Putting that arithmetic here rather than in PageBuilder
 * keeps it testable without a document, and keeps the one non-obvious
 * constant in one place.
 *
 * That constant is kappa. A quarter-circle cannot be drawn exactly with a
 * cubic Bezier, but pulling the control points 0.5522847498 of the radius
 * along the tangents gets within about a part in a thousand of the true
 * arc -- far below a printer's dot and below what any reader renders. It
 * is what every drawing program uses for the same reason.
 */
final class Shapes
{
    private function __construct()
    {
    }

    /**
     * (4/3)(sqrt(2) - 1): the control-point offset, as a fraction of the
     * radius, that makes a cubic Bezier hug a quarter-circle.
     */
    public const float KAPPA = 0.5522847498307936;

    /**
     * An ellipse centred on ($cx, $cy) with the given semi-axes, as four
     * Bezier quadrants, closed.
     *
     * Starts at the rightmost point and runs counter-clockwise, which is
     * PDF's positive direction and matters for the nonzero winding rule:
     * a shape drawn inside another one in the *same* direction fills the
     * hole rather than leaving it (see drawPath()'s $evenOdd).
     */
    public static function ellipse(PathSink $path, float $cx, float $cy, float $rx, float $ry): void
    {
        if ($rx <= 0.0 || $ry <= 0.0) {
            throw new \InvalidArgumentException("An ellipse needs positive radii, got $rx x $ry.");
        }

        $ox = $rx * self::KAPPA;
        $oy = $ry * self::KAPPA;

        $path->moveTo($cx + $rx, $cy)
            ->curveTo($cx + $rx, $cy + $oy, $cx + $ox, $cy + $ry, $cx, $cy + $ry)
            ->curveTo($cx - $ox, $cy + $ry, $cx - $rx, $cy + $oy, $cx - $rx, $cy)
            ->curveTo($cx - $rx, $cy - $oy, $cx - $ox, $cy - $ry, $cx, $cy - $ry)
            ->curveTo($cx + $ox, $cy - $ry, $cx + $rx, $cy - $oy, $cx + $rx, $cy)
            ->closePath();
    }

    /**
     * A rectangle with all four corners rounded to $radius, as four lines
     * and four quarter-arcs.
     *
     * A radius past half the shorter side would make opposite corners
     * overlap and turn the outline inside out, so it is capped there --
     * which is what CSS does with an over-large border-radius, and gives
     * the stadium shape a caller passing a deliberately huge radius is
     * asking for.
     */
    public static function roundedRectangle(
        PathSink $path,
        float $x,
        float $y,
        float $width,
        float $height,
        float $radius,
    ): void {
        if ($width <= 0.0 || $height <= 0.0) {
            throw new \InvalidArgumentException("A rectangle needs a positive size, got $width x $height.");
        }

        if ($radius < 0.0) {
            throw new \InvalidArgumentException("A corner radius cannot be negative, got $radius.");
        }

        $radius = min($radius, $width / 2.0, $height / 2.0);
        $offset = $radius * self::KAPPA;

        $right = $x + $width;
        $top = $y + $height;

        $path->moveTo($x + $radius, $y)
            ->lineTo($right - $radius, $y)
            ->curveTo($right - $radius + $offset, $y, $right, $y + $radius - $offset, $right, $y + $radius)
            ->lineTo($right, $top - $radius)
            ->curveTo($right, $top - $radius + $offset, $right - $radius + $offset, $top, $right - $radius, $top)
            ->lineTo($x + $radius, $top)
            ->curveTo($x + $radius - $offset, $top, $x, $top - $radius + $offset, $x, $top - $radius)
            ->lineTo($x, $y + $radius)
            ->curveTo($x, $y + $radius - $offset, $x + $radius - $offset, $y, $x + $radius, $y)
            ->closePath();
    }

    /**
     * A run of straight segments through $points, optionally closed.
     *
     * @param list<array{float, float}> $points
     */
    public static function polygon(PathSink $path, array $points, bool $close = true): void
    {
        if (count($points) < 2) {
            throw new \InvalidArgumentException('A polyline needs at least two points.');
        }

        foreach ($points as $index => [$x, $y]) {
            if ($index === 0) {
                $path->moveTo($x, $y);
            } else {
                $path->lineTo($x, $y);
            }
        }

        if ($close) {
            $path->closePath();
        }
    }

    /**
     * A regular $sides-gon inscribed in a circle of radius $radius,
     * rotated by $rotationDegrees.
     *
     * With no rotation the first vertex is at the right, matching
     * ellipse(); a triangle or a pentagon usually wants 90 degrees so a
     * vertex points up.
     *
     * @return list<array{float, float}> the vertices, for polygon()
     */
    public static function regularPolygonPoints(
        float $cx,
        float $cy,
        float $radius,
        int $sides,
        float $rotationDegrees = 0.0,
    ): array {
        if ($sides < 3) {
            throw new \InvalidArgumentException("A polygon needs at least three sides, got $sides.");
        }

        if ($radius <= 0.0) {
            throw new \InvalidArgumentException("A polygon needs a positive radius, got $radius.");
        }

        $start = deg2rad($rotationDegrees);
        $step = 2 * M_PI / $sides;

        $points = [];
        for ($i = 0; $i < $sides; ++$i) {
            $angle = $start + $i * $step;
            $points[] = [$cx + $radius * cos($angle), $cy + $radius * sin($angle)];
        }

        return $points;
    }

    /**
     * A rotation about ($originX, $originY) as a PDF transformation
     * matrix, which is the composition of "move the origin there, rotate,
     * move it back" already multiplied out.
     *
     * Positive degrees turn counter-clockwise, matching PDF's Y-up
     * convention -- so text rotated 90 degrees reads bottom-to-top, which
     * is what a chart's Y-axis label wants.
     *
     * @return array{float, float, float, float, float, float}
     */
    public static function rotationMatrix(float $degrees, float $originX = 0.0, float $originY = 0.0): array
    {
        $radians = deg2rad($degrees);
        $cos = cos($radians);
        $sin = sin($radians);

        return [
            $cos,
            $sin,
            -$sin,
            $cos,
            $originX - $originX * $cos + $originY * $sin,
            $originY - $originX * $sin - $originY * $cos,
        ];
    }

    /**
     * A scale about ($originX, $originY), multiplied out the same way.
     *
     * @return array{float, float, float, float, float, float}
     */
    public static function scaleMatrix(
        float $scaleX,
        float $scaleY,
        float $originX = 0.0,
        float $originY = 0.0,
    ): array {
        return [
            $scaleX,
            0.0,
            0.0,
            $scaleY,
            $originX * (1.0 - $scaleX),
            $originY * (1.0 - $scaleY),
        ];
    }
}
