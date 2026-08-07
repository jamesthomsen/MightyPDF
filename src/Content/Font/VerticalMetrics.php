<?php

declare(strict_types=1);

namespace MightyPDF\Content\Font;

/**
 * What a font says about the vertical extent of its glyphs, in PDF glyph
 * space (1/1000 em, per ISO 32000-2 §9.2.4) so that the numbers mean the
 * same thing whatever the font was designed at.
 *
 * Three measurements rather than one, because placing text in a box needs
 * more than "how tall is it":
 *
 * - ascent and descent bound the em box, which is what centres a line of
 *   mixed-case prose -- the descenders in "typography" are part of what
 *   the eye centres.
 * - capHeight bounds capitals and digits, which is what centres a label,
 *   a table heading or a single large letter. Centring one of those on
 *   the em box leaves it visibly high, because the space the descenders
 *   would have occupied is empty.
 *
 * Descent is a positive distance *below* the baseline here, the opposite
 * sign to AFM's Descender and the PDF descriptor's /Descent. Every
 * placement formula wants ascent + descent; with the negative convention
 * it is ascent - descent, and that sign is invisible at 10pt and 22pt out
 * at 270pt. See TextPlacement.
 */
final class VerticalMetrics
{
    public function __construct(
        public readonly int $ascent,
        public readonly int $descent,
        public readonly int $capHeight,
    ) {
    }

    public function ascentPt(float $sizePt): float
    {
        return $this->ascent / 1000.0 * $sizePt;
    }

    public function descentPt(float $sizePt): float
    {
        return $this->descent / 1000.0 * $sizePt;
    }

    public function capHeightPt(float $sizePt): float
    {
        return $this->capHeight / 1000.0 * $sizePt;
    }
}
