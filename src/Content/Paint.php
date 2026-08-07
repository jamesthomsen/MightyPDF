<?php

declare(strict_types=1);

namespace MightyPDF\Content;

/**
 * Something a shape can be painted with: a colour in one of PDF's colour
 * spaces.
 *
 * This exists because a colour is not always three numbers. RGB is what a
 * screen wants and what every design token states, and it is what Color
 * has always held -- but a document destined for a press is specified in
 * CMYK, and a brand colour that has to come out right on paper is a named
 * ink, which is a colour space of its own with a tint and a fallback. All
 * three are "the fill colour" as far as a caller is concerned, and none of
 * them can be written as the other two without losing what makes it worth
 * asking for.
 *
 * The float triples on PageBuilder's original primitives are untouched:
 * `fillRectangle($x, $y, $w, $h, ...$color->rgb())` still means what it
 * always did. What takes a Paint is the newer, general primitives
 * (drawRectangle(), drawEllipse(), drawPath() and friends) and the whole
 * layout layer, where Style and Border widened from Color to this.
 *
 * Applying a paint is polymorphic rather than a match on the concrete
 * type, because one of them needs a resource: a separation has to be
 * declared in the /Resources /ColorSpace of whatever scope is being drawn
 * into before its name means anything. Only PageBuilder knows that scope,
 * so it hands in a closure that registers one and hands back the name.
 */
interface Paint
{
    /**
     * Stable identity for this paint, so a page declares one colour-space
     * resource per distinct one rather than one per shape.
     *
     * Two paints with the same key must produce the same marks. A spot
     * colour's key deliberately leaves out its tint: the tint is an
     * operand of the paint operator, not part of the colour space, so
     * every tint of one ink shares one resource.
     */
    public function paintKey(): string;

    /**
     * What this paint looks like in RGB.
     *
     * For the places that can hold nothing else -- a form field's default
     * appearance string, a caller spreading into the float-triple
     * primitives. Lossy by definition for the other two: see CmykColor
     * for how far off that is and why it is still worth having.
     */
    public function toRgb(): Color;

    /**
     * Emits the operators that make this the nonstroking (fill) colour.
     *
     * @param \Closure(SpotColor): string $nameColorSpace registers a
     *        separation colour space in the scope being drawn into and
     *        returns its resource name. Never called by a paint that
     *        needs no resource.
     */
    public function applyFill(ContentStream $operators, \Closure $nameColorSpace): void;

    /** The same for the stroking colour. */
    public function applyStroke(ContentStream $operators, \Closure $nameColorSpace): void;
}
