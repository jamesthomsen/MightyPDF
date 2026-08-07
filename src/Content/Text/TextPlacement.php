<?php

declare(strict_types=1);

namespace MightyPDF\Content\Text;

use MightyPDF\Content\Font\Font;

/**
 * Where text goes inside a box: the arithmetic that turns a rectangle,
 * a font and an alignment into the baseline and left edge a drawing
 * call needs.
 *
 * It lives in one place, and every caller in this library goes through
 * it, because the alternative is what the ecosystem actually does --
 * FPDF centres text with a hardcoded `0.3 * fontSize`, TCPDF with its
 * own variant -- and a magic fraction of the type size is a bug whose
 * size grows with the type. Getting one of those constants wrong is
 * invisible in a 10pt table and centimetres out in a headline, so it
 * passes review and ships.
 *
 * Nothing here is approximate: it asks the font for its real ascent,
 * descent and cap height (see Font, VerticalMetrics) and the answers
 * are exact for the alignment asked for.
 *
 * A multi-line block is measured from the first line's ascent to the
 * last line's descent -- ascent + descent + (n-1) x line height -- which
 * is what makes firstBaselineY() with one line identical to baselineY(),
 * for every alignment and every line height. That identity is the whole
 * point: a wrapped cell and an unwrapped one line up because they are
 * the same calculation, not because two formulas were kept in step.
 */
final class TextPlacement
{
    private function __construct()
    {
    }

    /**
     * The baseline for a single line of text in the box whose bottom
     * edge is $boxBottomY and whose height is $boxHeightPt.
     */
    public static function baselineY(
        Font $font,
        float $sizePt,
        float $boxBottomY,
        float $boxHeightPt,
        VerticalAlign $valign,
    ): float {
        return self::firstBaselineY($font, $sizePt, $boxBottomY, $boxHeightPt, 1, 0.0, $valign);
    }

    /**
     * The baseline of the *first* of $lineCount lines set $lineHeightPt
     * apart. Later lines follow at $lineHeightPt intervals below it.
     *
     * Top is deliberately independent of the line count: text hung from
     * the top edge stays put as it grows downwards, which is what lets a
     * column of boxes share a top rule however much text each holds.
     */
    public static function firstBaselineY(
        Font $font,
        float $sizePt,
        float $boxBottomY,
        float $boxHeightPt,
        int $lineCount,
        float $lineHeightPt,
        VerticalAlign $valign,
    ): float {
        $ascent = $font->ascentPt($sizePt);
        $descent = $font->descentPt($sizePt);
        $trailing = max(0, $lineCount - 1) * $lineHeightPt;

        return match ($valign) {
            VerticalAlign::Top => $boxBottomY + $boxHeightPt - $ascent,
            VerticalAlign::Bottom => $boxBottomY + $descent + $trailing,
            VerticalAlign::Middle => $boxBottomY
                + ($boxHeightPt + $ascent + $descent + $trailing) / 2 - $ascent,
            VerticalAlign::CapMiddle => $boxBottomY
                + ($boxHeightPt + $font->capHeightPt($sizePt) + $trailing) / 2
                - $font->capHeightPt($sizePt),
        };
    }

    /**
     * The height a block of $lineCount lines occupies, ink to ink --
     * what a caller sizing a box to its text needs, and what
     * firstBaselineY() centres.
     *
     * Not $lineCount x $lineHeightPt: that measures baseline to baseline
     * and so counts a line's worth of leading below the last line, which
     * is space the text does not occupy. A box sized that way looks
     * bottom-heavy, and the discrepancy is what makes a single-line
     * paragraph and a cell disagree.
     */
    public static function blockHeightPt(
        Font $font,
        float $sizePt,
        int $lineCount,
        float $lineHeightPt,
    ): float {
        return $font->ascentPt($sizePt)
            + $font->descentPt($sizePt)
            + max(0, $lineCount - 1) * $lineHeightPt;
    }

    /**
     * The left edge to start drawing a line of $lineWidthPt at, inside a
     * box that starts at $boxX and is $boxWidthPt wide.
     *
     * Justify comes back flush left: stretching happens between words
     * and is the drawing call's business, since only it knows whether
     * this is the last line of the paragraph (which is not stretched).
     */
    public static function lineX(
        HorizontalAlign $align,
        float $boxX,
        float $boxWidthPt,
        float $lineWidthPt,
    ): float {
        return match ($align) {
            HorizontalAlign::Center => $boxX + ($boxWidthPt - $lineWidthPt) / 2,
            HorizontalAlign::Right => $boxX + $boxWidthPt - $lineWidthPt,
            default => $boxX,
        };
    }
}
