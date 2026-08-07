<?php

declare(strict_types=1);

namespace MightyPDF\Layout;

/**
 * The unit a Flow's coordinates are given in.
 *
 * PDF itself has one unit, the point, and the content layer speaks it
 * exclusively. This exists because business documents are not specified
 * in points: a print shop's bleed, a letterhead's margin and a form's
 * column widths arrive in millimetres or inches, and converting them at
 * the call site means a stray 2.834 wherever someone forgot.
 *
 * Conversion happens here and nowhere else, which is the same reason
 * FontFileMetrics::toGlyphSpace() exists: one place to be right, and one
 * place to look when a document comes out 2.8 times too big.
 */
enum Unit
{
    case Millimetres;
    case Points;
    case Inches;

    public function toPoints(float $value): float
    {
        return match ($this) {
            self::Millimetres => $value * 72.0 / 25.4,
            self::Points => $value,
            self::Inches => $value * 72.0,
        };
    }

    public function fromPoints(float $points): float
    {
        return match ($this) {
            self::Millimetres => $points * 25.4 / 72.0,
            self::Points => $points,
            self::Inches => $points / 72.0,
        };
    }
}
