<?php

declare(strict_types=1);

namespace MightyPDF\Content;

/**
 * Somewhere a path can be drawn into: the four operations every path in
 * PDF is built from.
 *
 * ContentStream is the obvious implementation -- it writes the
 * operators. The reason this is an interface at all is that a path
 * sometimes has to be measured rather than drawn: a gradient in the
 * default "objectBoundingBox" units is positioned relative to the box
 * its shape occupies, which for a path is not written anywhere in the
 * file and has to be computed from the curves themselves (see
 * Svg\PathBounds).
 *
 * Measuring by parsing the path a second time, into something that
 * collects points instead of operators, keeps one implementation of the
 * path grammar -- the alternative, a second parser that only computes
 * bounds, is the kind of duplication that drifts.
 */
interface PathSink
{
    public function moveTo(float $x, float $y): static;

    public function lineTo(float $x, float $y): static;

    /** Cubic Bezier curve from the current point, via two control points, to (x3, y3). */
    public function curveTo(float $x1, float $y1, float $x2, float $y2, float $x3, float $y3): static;

    public function closePath(): static;
}
