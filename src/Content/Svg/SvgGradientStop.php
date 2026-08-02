<?php

declare(strict_types=1);

namespace MightyPDF\Content\Svg;

/**
 * One colour stop of a gradient: a position from 0 to 1 along it, and
 * the colour there.
 *
 * stop-opacity is deliberately absent. A partly transparent stop is not
 * a property of the colour in PDF: it needs a second shading, of
 * greyscale luminosity, attached as a soft mask to the graphics state --
 * a whole parallel object graph for an effect that, in the icons and
 * logos this SVG support is aimed at, is rare. The colour is honoured
 * and the transparency is not; see the README.
 */
final class SvgGradientStop
{
    /** @param array{0: float, 1: float, 2: float} $color */
    public function __construct(
        public readonly float $offset,
        public readonly array $color,
    ) {
    }
}
