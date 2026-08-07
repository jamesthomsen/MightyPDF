<?php

declare(strict_types=1);

namespace MightyPDF\Layout;

use MightyPDF\Content\PathSink;

/**
 * A PathSink in a Flow's coordinates: millimetres from the top-left,
 * converted to PDF points on the way through to the real one.
 *
 * This is what lets Flow::path() take the same numbers as every other
 * call on that class. Without it a curve would be the one thing in the
 * layout layer specified in points from the bottom-left, which is exactly
 * the mixed-convention arithmetic the layer exists to remove.
 *
 * A decorator rather than a second implementation of the path grammar,
 * for the reason PathSink is an interface at all: there is one set of
 * path operations and everything that touches a path goes through it.
 */
final class UnitPathSink implements PathSink
{
    public function __construct(
        private readonly PathSink $target,
        private readonly Flow $flow,
    ) {
    }

    public function moveTo(float $x, float $y): static
    {
        $this->target->moveTo($this->flow->toPointsX($x), $this->flow->toPointsY($y));

        return $this;
    }

    public function lineTo(float $x, float $y): static
    {
        $this->target->lineTo($this->flow->toPointsX($x), $this->flow->toPointsY($y));

        return $this;
    }

    public function curveTo(float $x1, float $y1, float $x2, float $y2, float $x3, float $y3): static
    {
        $this->target->curveTo(
            $this->flow->toPointsX($x1),
            $this->flow->toPointsY($y1),
            $this->flow->toPointsX($x2),
            $this->flow->toPointsY($y2),
            $this->flow->toPointsX($x3),
            $this->flow->toPointsY($y3),
        );

        return $this;
    }

    public function closePath(): static
    {
        $this->target->closePath();

        return $this;
    }
}
