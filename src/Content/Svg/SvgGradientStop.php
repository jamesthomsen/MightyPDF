<?php

declare(strict_types=1);

namespace MightyPDF\Content\Svg;

/**
 * One colour stop of a gradient: a position from 0 to 1 along it, the
 * colour there, and how opaque it is.
 *
 * The opacity is carried separately because PDF carries it separately.
 * A colour has no transparency in a shading; a gradient that fades out
 * is drawn as a second shading in greyscale, attached to the graphics
 * state as a luminosity soft mask, where white means opaque and black
 * means invisible. See SvgSoftMask.
 */
final class SvgGradientStop
{
    /** @param array{0: float, 1: float, 2: float} $color */
    public function __construct(
        public readonly float $offset,
        public readonly array $color,
        public readonly float $opacity = 1.0,
    ) {
    }

    /** The stop's opacity as the grey a luminosity mask reads it from. */
    public function luminosity(): array
    {
        return [$this->opacity];
    }
}
