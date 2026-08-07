<?php

declare(strict_types=1);

namespace MightyPDF\Content;

/**
 * How an outline is drawn: its paint, its weight, and the four bits of
 * graphics state that make a line look like a rule, a fold mark or a
 * hand-drawn edge.
 *
 * One object rather than five parameters on every shape, because they
 * travel together and a document has a handful of them: a hairline for
 * table rules, a heavier one for a frame, a dotted one for a cut line.
 * Immutable, with with() for the variations, exactly like Layout\Style.
 *
 * A shape drawn with no Stroke is not stroked at all -- the primitives
 * take `?Stroke $stroke = null`, so "filled, no outline" needs nothing
 * said.
 */
final class Stroke
{
    public function __construct(
        public readonly Paint $paint = new Color(0.0, 0.0, 0.0),
        public readonly float $widthPt = 1.0,
        public readonly Dash $dash = new Dash([]),
        public readonly LineCap $cap = LineCap::Butt,
        public readonly LineJoin $join = LineJoin::Miter,
        public readonly float $miterLimit = 10.0,
    ) {
        if ($widthPt < 0.0) {
            throw new \InvalidArgumentException("A stroke width cannot be negative, got $widthPt.");
        }
    }

    /**
     * A hairline in the given paint. Zero width is legal PDF and means
     * "the thinnest line this device can draw", which is a different
     * thickness on every device -- so this uses a real 0.25pt, the weight
     * a style guide means by "hairline".
     */
    public static function hairline(?Paint $paint = null): self
    {
        return new self($paint ?? Color::black(), 0.25);
    }

    /**
     * Round dots, which needs the cap as well as the pattern: a
     * zero-length dash under the default butt cap has no area, and draws
     * nothing at all rather than drawing dots.
     */
    public static function dotted(float $widthPt = 1.0, float $spacingPt = 2.0, ?Paint $paint = null): self
    {
        return new self(
            $paint ?? Color::black(),
            $widthPt,
            Dash::dotted($spacingPt),
            LineCap::Round,
        );
    }

    public static function dashed(float $widthPt = 1.0, float $lengthPt = 3.0, ?Paint $paint = null): self
    {
        return new self($paint ?? Color::black(), $widthPt, Dash::dashed($lengthPt));
    }

    public function with(
        ?Paint $paint = null,
        ?float $widthPt = null,
        ?Dash $dash = null,
        ?LineCap $cap = null,
        ?LineJoin $join = null,
        ?float $miterLimit = null,
    ): self {
        return new self(
            $paint ?? $this->paint,
            $widthPt ?? $this->widthPt,
            $dash ?? $this->dash,
            $cap ?? $this->cap,
            $join ?? $this->join,
            $miterLimit ?? $this->miterLimit,
        );
    }

    /**
     * Emits everything this stroke sets, ready for the path to be built
     * and stroked. Callers wrap it in q/Q -- all six are graphics state
     * and would otherwise outlive the shape they were meant for.
     *
     * @param \Closure(SpotColor): string $nameColorSpace see Paint
     */
    public function apply(ContentStream $operators, \Closure $nameColorSpace): void
    {
        $this->paint->applyStroke($operators, $nameColorSpace);

        $operators->setLineWidth($this->widthPt)
            ->setLineCap($this->cap)
            ->setLineJoin($this->join)
            ->setLineDash($this->dash->pattern, $this->dash->phase);

        if ($this->join === LineJoin::Miter) {
            $operators->setMiterLimit($this->miterLimit);
        }
    }
}
