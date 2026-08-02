<?php

declare(strict_types=1);

namespace MightyPDF\Content\Svg;

/**
 * A gradient paint server defined in an SVG document -- what
 * `fill="url(#sunset)"` points at.
 *
 * Gradients are collected in a pass of their own before anything is
 * drawn (see SvgDocument::collectGradients()), for two reasons. A
 * document may reference a gradient defined later in the file, which is
 * ordinary and legal; and a gradient may inherit from another by
 * href/xlink:href -- a common way to reuse one set of stops with a
 * different geometry -- which cannot be resolved while walking.
 *
 * Coordinates are kept exactly as authored, in whichever space
 * $userSpace says. Turning them into a PDF pattern matrix needs the
 * shape's bounding box and the transform in effect, neither of which is
 * known here; that is SvgShadingPattern's job.
 */
final class SvgGradient
{
    public const string LINEAR = 'linear';
    public const string RADIAL = 'radial';

    /**
     * @param array{0: float, 1: float, 2: float, 3: float}|array{0: float, 1: float, 2: float, 3: float, 4: float} $coordinates
     *        x1, y1, x2, y2 for a linear gradient; cx, cy, r, fx, fy for a radial one
     * @param list<SvgGradientStop> $stops in ascending offset order
     * @param array{0: float, 1: float, 2: float, 3: float, 4: float, 5: float}|null $transform
     */
    public function __construct(
        public readonly string $type,
        public readonly array $coordinates,
        public readonly array $stops,
        public readonly bool $userSpace,
        public readonly ?array $transform,
    ) {
    }

    public function isLinear(): bool
    {
        return $this->type === self::LINEAR;
    }

    /**
     * Whether this gradient can be drawn at all.
     *
     * A gradient with no stops paints nothing -- the SVG spec says as
     * much, and it is the shape a broken href inheritance takes.
     */
    public function isPaintable(): bool
    {
        return $this->stops !== [];
    }

    /**
     * The single colour this gradient amounts to, if it amounts to one.
     *
     * One stop is not a gradient, it is a fill; a shading with a
     * one-entry function is either rejected or rendered as nothing
     * depending on the reader. Two stops of the same colour are the same
     * situation written differently.
     *
     * @return array{0: float, 1: float, 2: float}|null
     */
    public function solidColor(): ?array
    {
        $first = $this->stops[0] ?? null;

        if ($first === null) {
            return null;
        }

        foreach ($this->stops as $stop) {
            if ($stop->color !== $first->color) {
                return null;
            }
        }

        return $first->color;
    }

    /**
     * The stops as PDF wants them: covering the whole of 0 to 1.
     *
     * SVG stops need not start at 0 or end at 1, and the colour outside
     * that range is the nearest stop's, held flat. PDF says the same
     * thing with a shading's /Extend, but only outside the shading's own
     * domain -- inside it, the function has to be defined everywhere. So
     * a gradient whose stops run 0.3 to 0.8 gets flat segments added at
     * each end rather than a function with holes in it.
     *
     * @return list<SvgGradientStop>
     */
    public function paddedStops(): array
    {
        $stops = $this->stops;

        if ($stops === []) {
            return [];
        }

        $first = $stops[0];
        $last = $stops[count($stops) - 1];

        if ($first->offset > 0.0) {
            array_unshift($stops, new SvgGradientStop(0.0, $first->color));
        }

        if ($last->offset < 1.0) {
            $stops[] = new SvgGradientStop(1.0, $last->color);
        }

        return $stops;
    }
}
