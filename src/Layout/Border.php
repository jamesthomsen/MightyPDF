<?php

declare(strict_types=1);

namespace MightyPDF\Layout;

use MightyPDF\Content\Color;
use MightyPDF\Content\Dash;
use MightyPDF\Content\Paint;
use MightyPDF\Content\Stroke;

/**
 * Which edges of a cell are ruled, how thickly and in what colour.
 *
 * A value object rather than the flag FPDF uses, where $border is 0, 1,
 * or a string like "LTB" whose letters are matched one at a time. That
 * signature cannot be type-checked, cannot carry a width or a colour,
 * and reads identically whether it was meant as a boolean or as a set of
 * edges -- passing `1` and passing `true` both draw a box, passing `'1'`
 * draws nothing, and none of the three is a mistake the language can
 * catch.
 *
 * The width is in points even though a Flow's coordinates are not,
 * because rule weights are specified in points everywhere -- a hairline
 * is 0.25pt in every style guide, and nobody asks for a 0.088mm rule.
 * The dash lengths are in points for the same reason.
 *
 * The colour is a Paint rather than a Color, so a rule can be set in a
 * process colour or a named ink like anything else on the page.
 */
final class Border
{
    public function __construct(
        public readonly bool $top = false,
        public readonly bool $right = false,
        public readonly bool $bottom = false,
        public readonly bool $left = false,
        public readonly float $widthPt = 0.2,
        public readonly ?Paint $color = null,
        public readonly ?Dash $dash = null,
    ) {
    }

    public static function none(): self
    {
        return new self();
    }

    public static function box(float $widthPt = 0.2, ?Paint $color = null, ?Dash $dash = null): self
    {
        return new self(true, true, true, true, $widthPt, $color, $dash);
    }

    public static function bottom(float $widthPt = 0.2, ?Paint $color = null, ?Dash $dash = null): self
    {
        return new self(bottom: true, widthPt: $widthPt, color: $color, dash: $dash);
    }

    public static function top(float $widthPt = 0.2, ?Paint $color = null, ?Dash $dash = null): self
    {
        return new self(top: true, widthPt: $widthPt, color: $color, dash: $dash);
    }

    public function isEmpty(): bool
    {
        return !$this->top && !$this->right && !$this->bottom && !$this->left;
    }

    public function colorOrBlack(): Paint
    {
        return $this->color ?? Color::black();
    }

    /**
     * This border as a Stroke, for the primitives that take one.
     *
     * A rule is a stroke with the edges picked out separately, so keeping
     * one conversion here is what stops the two descriptions drifting.
     */
    public function stroke(): Stroke
    {
        return new Stroke(
            $this->colorOrBlack(),
            $this->widthPt,
            $this->dash ?? new Dash([]),
        );
    }
}
