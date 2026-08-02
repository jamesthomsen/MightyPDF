<?php

declare(strict_types=1);

namespace MightyPDF\Content\Svg;

use MightyPDF\Content\PathSink;

/**
 * Measures the box a path occupies, by being drawn into instead of a
 * content stream (see PathSink).
 *
 * Curves are measured exactly rather than by their control points. The
 * control points of a Bezier are a box the curve is guaranteed to fit
 * inside, which is the easy answer and is wrong here: a gradient placed
 * against a box larger than the shape starts and ends outside the
 * shape, so its colours are visibly compressed towards the middle. The
 * extra work is one quadratic per curve per axis.
 */
final class PathBounds implements PathSink
{
    private ?float $minX = null;
    private ?float $minY = null;
    private ?float $maxX = null;
    private ?float $maxY = null;

    private float $currentX = 0.0;
    private float $currentY = 0.0;

    public function moveTo(float $x, float $y): static
    {
        $this->include($x, $y);
        $this->currentX = $x;
        $this->currentY = $y;

        return $this;
    }

    public function lineTo(float $x, float $y): static
    {
        return $this->moveTo($x, $y);
    }

    public function curveTo(float $x1, float $y1, float $x2, float $y2, float $x3, float $y3): static
    {
        $this->include($x3, $y3);

        foreach (self::extrema($this->currentX, $x1, $x2, $x3) as $x) {
            $this->include($x, $this->currentY);
        }

        foreach (self::extrema($this->currentY, $y1, $y2, $y3) as $y) {
            $this->include($this->currentX, $y);
        }

        $this->currentX = $x3;
        $this->currentY = $y3;

        return $this;
    }

    /** A close returns to the start of the subpath, which was already included when it began. */
    public function closePath(): static
    {
        return $this;
    }

    public function isEmpty(): bool
    {
        return $this->minX === null;
    }

    /**
     * @return array{0: float, 1: float, 2: float, 3: float} x, y, width, height
     */
    public function box(): array
    {
        if ($this->minX === null || $this->minY === null || $this->maxX === null || $this->maxY === null) {
            return [0.0, 0.0, 0.0, 0.0];
        }

        return [$this->minX, $this->minY, $this->maxX - $this->minX, $this->maxY - $this->minY];
    }

    private function include(float $x, float $y): void
    {
        $this->minX = $this->minX === null ? $x : min($this->minX, $x);
        $this->minY = $this->minY === null ? $y : min($this->minY, $y);
        $this->maxX = $this->maxX === null ? $x : max($this->maxX, $x);
        $this->maxY = $this->maxY === null ? $y : max($this->maxY, $y);
    }

    /**
     * Where a cubic Bezier turns back on itself along one axis: the
     * roots in (0, 1) of its derivative, evaluated on the curve.
     *
     * @return list<float>
     */
    private static function extrema(float $p0, float $p1, float $p2, float $p3): array
    {
        $a = 3 * (-$p0 + 3 * $p1 - 3 * $p2 + $p3);
        $b = 6 * ($p0 - 2 * $p1 + $p2);
        $c = 3 * ($p1 - $p0);

        $values = [];

        foreach (self::rootsInUnitInterval($a, $b, $c) as $t) {
            $inverse = 1 - $t;
            $values[] = $inverse ** 3 * $p0
                + 3 * $inverse ** 2 * $t * $p1
                + 3 * $inverse * $t ** 2 * $p2
                + $t ** 3 * $p3;
        }

        return $values;
    }

    /**
     * The roots of $a t^2 + $b t + $c between 0 and 1, exclusive.
     *
     * A straight segment of a curve makes $a zero -- three collinear
     * control points are ordinary, not degenerate -- so the linear case
     * is a real one, not a guard against division by zero.
     *
     * @return list<float>
     */
    private static function rootsInUnitInterval(float $a, float $b, float $c): array
    {
        if (abs($a) < 1e-12) {
            if (abs($b) < 1e-12) {
                return [];
            }

            $t = -$c / $b;

            return ($t > 0 && $t < 1) ? [$t] : [];
        }

        $discriminant = $b ** 2 - 4 * $a * $c;

        if ($discriminant < 0) {
            return [];
        }

        $root = sqrt($discriminant);
        $roots = [];

        foreach ([(-$b + $root) / (2 * $a), (-$b - $root) / (2 * $a)] as $t) {
            if ($t > 0 && $t < 1) {
                $roots[] = $t;
            }
        }

        return $roots;
    }
}
