<?php

declare(strict_types=1);

namespace MightyPDF\Layout;

use MightyPDF\Content\Color;
use MightyPDF\Content\Font\Font;
use MightyPDF\Content\Font\StandardFont;
use MightyPDF\Content\Text\HorizontalAlign;
use MightyPDF\Content\Text\VerticalAlign;

/**
 * How a cell or paragraph is drawn: its typeface, colours, rules and
 * alignment, as one value.
 *
 * Immutable, with with() returning a modified copy, because that is what
 * a real document's styles are: a handful of variations on a base. A
 * zebra-striped table is one style and one `with(fill: $grey)`; passing
 * eight arguments per cell instead is where a row ends up in the wrong
 * weight because one call in twenty was edited and the rest were not.
 *
 * Every measurement here is in points, including $paddingPt, so a style
 * means the same thing in a millimetre Flow and an inch one and can be
 * shared between them. A padding in the Flow's unit would read as 1mm in
 * one document and 1 inch in another from the same default.
 *
 * $paddingPt is horizontal only -- the inset between a rule and the text
 * inside it. There is no vertical padding because the cell's height and
 * its VerticalAlign already say where the text sits; a second control
 * over the same thing gives two ways to write one layout and one way to
 * write a contradiction.
 */
final class Style
{
    public function __construct(
        public readonly Font $font = StandardFont::Helvetica,
        public readonly float $sizePt = 10.0,
        public readonly Color $color = new Color(0.0, 0.0, 0.0),
        public readonly ?Color $fill = null,
        public readonly Border $border = new Border(),
        public readonly HorizontalAlign $align = HorizontalAlign::Left,
        public readonly VerticalAlign $valign = VerticalAlign::Middle,
        public readonly float $paddingPt = 3.0,
    ) {
    }

    /**
     * A copy with the named properties replaced. Anything left out --
     * or passed as null -- keeps this style's value.
     *
     * Which is why removing a fill has a method of its own: null here
     * has to mean "unchanged" for the other seven properties to be
     * optional, so it cannot also mean "no fill". withoutFill() says the
     * thing null would have been ambiguous about.
     */
    public function with(
        ?Font $font = null,
        ?float $sizePt = null,
        ?Color $color = null,
        ?Color $fill = null,
        ?Border $border = null,
        ?HorizontalAlign $align = null,
        ?VerticalAlign $valign = null,
        ?float $paddingPt = null,
    ): self {
        return new self(
            $font ?? $this->font,
            $sizePt ?? $this->sizePt,
            $color ?? $this->color,
            $fill ?? $this->fill,
            $border ?? $this->border,
            $align ?? $this->align,
            $valign ?? $this->valign,
            $paddingPt ?? $this->paddingPt,
        );
    }

    public function withoutFill(): self
    {
        return new self(
            $this->font,
            $this->sizePt,
            $this->color,
            null,
            $this->border,
            $this->align,
            $this->valign,
            $this->paddingPt,
        );
    }

    /**
     * The default distance between the baselines of wrapped lines.
     *
     * 1.15 of the type size, matching PageBuilder::drawParagraph()'s own
     * default so that the two layers cannot drift apart on it.
     */
    public function lineHeightPt(): float
    {
        return $this->sizePt * 1.15;
    }
}
