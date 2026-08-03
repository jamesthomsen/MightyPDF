<?php

declare(strict_types=1);

namespace MightyPDF\Content\Svg;

use MightyPDF\Content\PathSink;

/**
 * A path measured as a road rather than drawn as a shape: how long it
 * is, and where you are after travelling a given distance along it.
 *
 * That is what `<textPath>` needs, and it is a different question from
 * the one a content stream answers. It is asked by being drawn into
 * (see PathSink), the same trick PathBounds uses -- the path grammar is
 * parsed once, by SvgPathParser, and what happens to the result is the
 * sink's business.
 *
 * Curves are flattened into short straight steps. Text on a path is
 * placed glyph by glyph, so the error that matters is where one glyph
 * lands, not whether the outline is exact -- a step much shorter than a
 * glyph is indistinguishable from the curve itself.
 */
final class SvgPathWalk implements PathSink
{
    /**
     * How finely a curve is chopped up: one step per this many units of
     * its control polygon, within the bounds below.
     *
     * The control polygon is longer than the curve it describes, so this
     * errs towards more steps, which is the cheap direction to err in.
     */
    private const float UNITS_PER_STEP = 1.5;
    private const int MIN_STEPS = 12;
    private const int MAX_STEPS = 96;

    /** @var list<array{0: float, 1: float, 2: float}> x, y, and the distance travelled to reach it */
    private array $points = [];

    private float $currentX = 0.0;
    private float $currentY = 0.0;
    private float $startX = 0.0;
    private float $startY = 0.0;
    private float $travelled = 0.0;

    public function moveTo(float $x, float $y): static
    {
        // A move is a jump, not a journey: the distance is not counted,
        // and the point starts a run of its own. Text carries on across
        // the gap, which is what SVG asks for -- a path's subpaths are
        // one path as far as text on it is concerned.
        $this->currentX = $this->startX = $x;
        $this->currentY = $this->startY = $y;
        $this->points[] = [$x, $y, $this->travelled];

        return $this;
    }

    public function lineTo(float $x, float $y): static
    {
        $this->travelled += hypot($x - $this->currentX, $y - $this->currentY);
        $this->points[] = [$x, $y, $this->travelled];

        $this->currentX = $x;
        $this->currentY = $y;

        return $this;
    }

    public function curveTo(float $x1, float $y1, float $x2, float $y2, float $x3, float $y3): static
    {
        $x0 = $this->currentX;
        $y0 = $this->currentY;

        $polygon = hypot($x1 - $x0, $y1 - $y0) + hypot($x2 - $x1, $y2 - $y1) + hypot($x3 - $x2, $y3 - $y2);
        $steps = max(self::MIN_STEPS, min(self::MAX_STEPS, (int) ceil($polygon / self::UNITS_PER_STEP)));

        for ($step = 1; $step <= $steps; ++$step) {
            $t = $step / $steps;
            $inverse = 1 - $t;

            $this->lineTo(
                $inverse ** 3 * $x0 + 3 * $inverse ** 2 * $t * $x1 + 3 * $inverse * $t ** 2 * $x2 + $t ** 3 * $x3,
                $inverse ** 3 * $y0 + 3 * $inverse ** 2 * $t * $y1 + 3 * $inverse * $t ** 2 * $y2 + $t ** 3 * $y3,
            );
        }

        return $this;
    }

    public function closePath(): static
    {
        return $this->lineTo($this->startX, $this->startY);
    }

    public function length(): float
    {
        return $this->travelled;
    }

    public function isEmpty(): bool
    {
        return count($this->points) < 2;
    }

    /**
     * Where you stand after travelling $distance along the path, and
     * which way you are facing.
     *
     * Past either end there is nowhere to stand: SVG does not render the
     * glyphs that fall off a path, rather than piling them up at its
     * ends, and null says so.
     *
     * @return array{0: float, 1: float, 2: float}|null x, y, and the
     *         angle of travel in radians
     */
    public function at(float $distance): ?array
    {
        if ($this->isEmpty() || $distance < 0.0 || $distance > $this->travelled) {
            return null;
        }

        $index = $this->segmentAt($distance);
        [$fromX, $fromY, $fromDistance] = $this->points[$index];
        [$toX, $toY, $toDistance] = $this->points[$index + 1];

        $span = $toDistance - $fromDistance;
        $fraction = $span > 0.0 ? ($distance - $fromDistance) / $span : 0.0;

        return [
            $fromX + ($toX - $fromX) * $fraction,
            $fromY + ($toY - $fromY) * $fraction,
            atan2($toY - $fromY, $toX - $fromX),
        ];
    }

    /**
     * The segment $distance falls in, found by bisection.
     *
     * A flattened curve is thousands of points long and every glyph asks
     * this question, so walking the list from the start would make the
     * cost of a line of text quadratic in the length of its path.
     */
    private function segmentAt(float $distance): int
    {
        $low = 0;
        $high = count($this->points) - 2;

        while ($low < $high) {
            $middle = intdiv($low + $high + 1, 2);

            if ($this->points[$middle][2] <= $distance) {
                $low = $middle;
            } else {
                $high = $middle - 1;
            }
        }

        return $low;
    }
}
