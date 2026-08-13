<?php

declare(strict_types=1);

namespace MightyPDF\Content\Svg;

use MightyPDF\Content\ContentStream;

/**
 * Decides what a shape is painted with, and emits the operators that set
 * it up: colour, opacity, line width, and the pattern behind a gradient
 * or a <pattern> fill.
 *
 * Split out of SvgRenderer, which is about walking an element tree. Every
 * shape there is drawn the same way -- work out the paint, emit the
 * geometry, close the path -- and it is only the middle step that differs
 * between a rect and a polyline. This owns the two ends.
 *
 * Every false these methods return is a deliberate degradation rather
 * than an error: an unresolvable paint server, a gradient with no stops,
 * a shape with no area to measure a gradient against, or a caller that
 * cannot supply the resource. A broken decorative fill should not take
 * the whole document down with it -- the same call SvgColor already makes
 * for url() references it cannot follow.
 */
final class SvgPaintServers
{
    /**
     * @param array<string, SvgGradient> $gradients
     * @param array<string, SvgPattern> $patterns
     */
    public function __construct(
        private readonly ContentStream $stream,
        private readonly SvgResources $resources,
        private readonly SvgTileSource $tiles,
        private readonly array $gradients = [],
        private readonly array $patterns = [],
    ) {
    }

    /**
     * Sets up colour, opacity and line width for the shape about to be
     * drawn, and reports what will actually be painted.
     *
     * The report matters because a paint is not always the one the
     * style asked for: a gradient reference that leads nowhere paints
     * nothing, and a shape that paints nothing has to end its path with
     * "n" rather than with a fill of the last colour that happened to
     * be set.
     *
     * $bounds is a closure rather than a value because working out a
     * path's box means parsing it a second time, and only a gradient in
     * objectBoundingBox units ever asks. It is measured at most once
     * per shape however many of the three things below ask -- see
     * measuredOnce().
     *
     * A shape needing partial opacity is wrapped in its own graphics
     * state, and finishPainting() closes it. Otherwise the state outlives
     * the shape that asked for it: PDF has no "back to opaque" operator,
     * so one half-transparent shape would leave every shape drawn after
     * it half-transparent too -- and only where the drawing happened to
     * be written in that order.
     *
     * @param array{0: float, 1: float, 2: float, 3: float, 4: float, 5: float} $matrix
     * @param \Closure(): array{0: float, 1: float, 2: float, 3: float} $bounds
     * @return array{fill: bool, stroke: bool, state: bool}
     */
    public function applyStyle(SvgStyle $style, array $matrix, \Closure $bounds): array
    {
        $bounds = self::measuredOnce($bounds);

        $translucent = $style->fillOpacity < 1.0 || $style->strokeOpacity < 1.0;
        $fading = $this->fadingGradient($style);

        // The mask's name is asked for before anything is emitted,
        // because it may come back null -- a caller that cannot resource
        // soft masks leaves stop-opacity unhonoured -- and a graphics
        // state is only worth pushing for a state that will be set.
        $alpha = $translucent
            ? $this->resources->extGStateResourceName($style->fillOpacity, $style->strokeOpacity)
            : null;
        $mask = $fading === null
            ? null
            : $this->resources->softMaskResourceName($fading, $bounds(), $style->strokeWidth);

        $state = $alpha !== null || $mask !== null;

        if ($state) {
            $this->stream->pushGraphicsState();
        }

        if ($alpha !== null) {
            $this->stream->setExtGState($alpha);
        }

        if ($mask !== null) {
            $this->stream->setExtGState($mask);
        }

        return [
            'fill' => $this->applyFill($style, $matrix, $bounds),
            'stroke' => $this->applyStroke($style, $matrix, $bounds),
            'state' => $state,
        ];
    }

    /**
     * Closes the path the caller has just emitted, painting it with
     * whatever applyStyle() reported, and closes the graphics state it
     * opened.
     *
     * @param array{fill: bool, stroke: bool, state: bool} $painted
     */
    public function finishPainting(array $painted, SvgStyle $style, bool $fillable = true): void
    {
        $hasFill = $fillable && $painted['fill'];

        if ($hasFill && $painted['stroke']) {
            $this->stream->fillAndStroke($style->evenOdd);
        } elseif ($hasFill) {
            $this->stream->fill($style->evenOdd);
        } elseif ($painted['stroke']) {
            $this->stream->stroke();
        } else {
            $this->stream->endPathNoOp();
        }

        if ($painted['state']) {
            $this->stream->popGraphicsState();
        }
    }

    /**
     * The same box, measured once however often it is asked for.
     *
     * A shape's bounding box is not something it carries but something
     * parsed out of it again: for a path, a second walk of its "d". Up
     * to three things here want it -- the soft mask, a gradient or
     * pattern fill, and a gradient or pattern stroke -- and nothing
     * between them can change the answer, so the second and third asks
     * were paying a full parse for a number already worked out. On a
     * drawing of long dual-gradient paths that was a third of the
     * rendering time.
     *
     * @param \Closure(): array{0: float, 1: float, 2: float, 3: float} $bounds
     * @return \Closure(): array{0: float, 1: float, 2: float, 3: float}
     */
    private static function measuredOnce(\Closure $bounds): \Closure
    {
        $box = null;

        // By reference, and so a long closure rather than an arrow one:
        // an arrow function captures by value, which would hand every
        // call its own $box and memoize nothing at all.
        return static function () use ($bounds, &$box): array {
            return $box ??= $bounds();
        };
    }

    /**
     * The gradient this shape is painted with that fades, if there is
     * one -- a gradient with a transparent stop needs a soft mask, and
     * the mask belongs to the whole shape rather than to one of its
     * paints.
     *
     * A shape whose fill and stroke *both* fade is painted under the
     * fill's mask: the graphics state has room for one soft mask, and
     * drawing the two paints separately so each could have its own is a
     * different rendering model than the one here. Rare enough to be
     * worth naming rather than working around.
     */
    private function fadingGradient(SvgStyle $style): ?SvgGradient
    {
        foreach ([$style->fillReference, $style->strokeReference] as $reference) {
            $gradient = $reference === null ? null : ($this->gradients[$reference] ?? null);

            if ($gradient !== null && $gradient->hasTransparency() && $gradient->isPaintable()) {
                return $gradient;
            }
        }

        return null;
    }

    /**
     * @param array{0: float, 1: float, 2: float, 3: float, 4: float, 5: float} $matrix
     * @param \Closure(): array{0: float, 1: float, 2: float, 3: float} $bounds
     */
    private function applyFill(SvgStyle $style, array $matrix, \Closure $bounds): bool
    {
        if ($style->fillReference !== null) {
            return $this->applyPaintServer(
                $style->fillReference,
                $matrix,
                $bounds,
                $this->stream->setFillColorRgb(...),
                $this->stream->setFillPattern(...),
            );
        }

        if ($style->fill === null) {
            return false;
        }

        $this->stream->setFillColorRgb(...$style->fill);

        return true;
    }

    /**
     * @param array{0: float, 1: float, 2: float, 3: float, 4: float, 5: float} $matrix
     * @param \Closure(): array{0: float, 1: float, 2: float, 3: float} $bounds
     */
    private function applyStroke(SvgStyle $style, array $matrix, \Closure $bounds): bool
    {
        if ($style->strokeReference !== null) {
            $painted = $this->applyPaintServer(
                $style->strokeReference,
                $matrix,
                $bounds,
                $this->stream->setStrokeColorRgb(...),
                $this->stream->setStrokePattern(...),
            );

            if ($painted) {
                $this->stream->setLineWidth($style->strokeWidth);
            }

            return $painted;
        }

        if ($style->stroke === null) {
            return false;
        }

        $this->stream->setStrokeColorRgb(...$style->stroke)->setLineWidth($style->strokeWidth);

        return true;
    }

    /**
     * Paints with a url(#id) reference, whichever kind of paint server
     * it names, reporting whether anything was painted at all.
     *
     * @param array{0: float, 1: float, 2: float, 3: float, 4: float, 5: float} $matrix
     * @param \Closure(): array{0: float, 1: float, 2: float, 3: float} $bounds
     * @param \Closure(float, float, float): mixed $setColor
     * @param \Closure(string): mixed $setPattern
     */
    private function applyPaintServer(
        string $reference,
        array $matrix,
        \Closure $bounds,
        \Closure $setColor,
        \Closure $setPattern,
    ): bool {
        if (isset($this->patterns[$reference])) {
            return $this->applyPattern($reference, $matrix, $bounds, $setPattern);
        }

        return $this->applyGradient($reference, $matrix, $bounds, $setColor, $setPattern);
    }

    /**
     * Paints with a <pattern>, asking the renderer for one tile's worth
     * of its content and handing that to the caller to make a resource
     * of.
     *
     * The tile is drawn by the renderer rather than here because
     * everything it takes to draw is there: the pattern's children are
     * ordinary elements, drawn with the same gradients, stylesheet and
     * resources as the rest of the drawing. See SvgTileSource.
     *
     * @param array{0: float, 1: float, 2: float, 3: float, 4: float, 5: float} $matrix
     * @param \Closure(): array{0: float, 1: float, 2: float, 3: float} $bounds
     * @param \Closure(string): mixed $setPattern
     */
    private function applyPattern(
        string $reference,
        array $matrix,
        \Closure $bounds,
        \Closure $setPattern,
    ): bool {
        $pattern = $this->patterns[$reference];
        $box = $bounds();

        if (!$pattern->canPaint($box)) {
            return false;
        }

        $tile = $this->tiles->tileFor($reference, $pattern, $pattern->contentMatrix($box));

        if ($tile === null) {
            return false;
        }

        $name = $this->resources->tilingPatternResourceName($pattern, $tile, $matrix, $box);

        if ($name === null) {
            return false;
        }

        $setPattern($name);

        return true;
    }

    /**
     * Paints with a url(#id) gradient reference, reporting whether
     * anything was painted at all.
     *
     * @param array{0: float, 1: float, 2: float, 3: float, 4: float, 5: float} $matrix
     * @param \Closure(): array{0: float, 1: float, 2: float, 3: float} $bounds
     * @param \Closure(float, float, float): mixed $setColor
     * @param \Closure(string): mixed $setPattern
     */
    private function applyGradient(
        string $reference,
        array $matrix,
        \Closure $bounds,
        \Closure $setColor,
        \Closure $setPattern,
    ): bool {
        $gradient = $this->gradients[$reference] ?? null;

        if ($gradient === null) {
            return false;
        }

        // A gradient whose stops are all one colour is a flat fill
        // written the long way -- and a PDF shading that interpolates a
        // colour to itself is a shading readers are entitled to reject.
        $solid = $gradient->solidColor();

        if ($solid !== null) {
            $setColor(...$solid);

            return true;
        }

        $box = $bounds();

        if (!SvgShadingPattern::canPaint($gradient, $box)) {
            return false;
        }

        $name = $this->resources->shadingPatternResourceName($gradient, $matrix, $box);

        if ($name === null) {
            return false;
        }

        $setPattern($name);

        return true;
    }
}
