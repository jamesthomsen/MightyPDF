<?php

declare(strict_types=1);

namespace MightyPDF\Content\Font\TrueType;

/**
 * What a font file says about itself that a PDF font descriptor (ISO
 * 32000-2 §9.8) has to repeat: the bounding box every glyph fits in, how
 * far the font rises and drops, whether it slants, and so on.
 *
 * Values arrive in font design units and leave in PDF glyph space
 * (1/1000 em) -- toGlyphSpace() is the only place that conversion
 * happens, so a font drawn at 2048 units per em and one drawn at 1000
 * cannot disagree about what "700 wide" means.
 */
final class FontFileMetrics
{
    /**
     * Descriptor flags (§9.8.2, Table 121). Only the ones that can be
     * answered from a TrueType file are here: the rest describe
     * typographic intent no table states.
     */
    private const int FLAG_FIXED_PITCH = 1 << 0;
    private const int FLAG_SYMBOLIC = 1 << 2;
    private const int FLAG_NONSYMBOLIC = 1 << 5;
    private const int FLAG_ITALIC = 1 << 6;

    public function __construct(
        public readonly int $unitsPerEm,
        public readonly int $xMin,
        public readonly int $yMin,
        public readonly int $xMax,
        public readonly int $yMax,
        public readonly int $ascent,
        public readonly int $descent,
        public readonly ?int $capHeight,
        public readonly float $italicAngle,
        public readonly int $weightClass,
        public readonly bool $isItalic,
        public readonly bool $isBold,
        public readonly bool $isFixedPitch,
        public readonly bool $isSymbolic,
    ) {
    }

    /** A font-unit measurement in PDF glyph space, i.e. thousandths of an em. */
    public function toGlyphSpace(int|float $fontUnits): int
    {
        return (int) round($fontUnits * 1000 / $this->unitsPerEm);
    }

    /** @return array{int, int, int, int} the /FontBBox, in glyph space */
    public function boundingBox(): array
    {
        return [
            $this->toGlyphSpace($this->xMin),
            $this->toGlyphSpace($this->yMin),
            $this->toGlyphSpace($this->xMax),
            $this->toGlyphSpace($this->yMax),
        ];
    }

    /**
     * Cap height is optional in OS/2 and absent from every version 0 and
     * 1 table, so it is estimated when missing. Readers use it for
     * fitting text into a box they are laying out themselves; a
     * plausible value is worth more than an absent one.
     */
    public function capHeightInGlyphSpace(): int
    {
        return $this->toGlyphSpace($this->capHeight ?? (int) round($this->ascent * 0.7));
    }

    /**
     * The nominal vertical stem width, which no TrueType table records.
     *
     * Readers use it only to synthesize a substitute when the embedded
     * font is unavailable -- which, for a font embedded here, it is not.
     * The estimate is the conventional one, quadratic in weight so that
     * the 400-to-700 range lands roughly where real fonts measure.
     */
    public function stemV(): int
    {
        return (int) round(50 + ($this->weightClass / 65) ** 2);
    }

    public function flags(): int
    {
        $flags = 0;

        if ($this->isFixedPitch) {
            $flags |= self::FLAG_FIXED_PITCH;
        }

        if ($this->isItalic) {
            $flags |= self::FLAG_ITALIC;
        }

        // Symbolic and nonsymbolic are mutually exclusive, and getting
        // this wrong is not cosmetic: a font marked nonsymbolic is one a
        // reader may re-encode through a standard encoding, which for a
        // font whose glyphs are not characters produces the wrong
        // glyphs. A font with no Unicode cmap is taken at its word.
        $flags |= $this->isSymbolic ? self::FLAG_SYMBOLIC : self::FLAG_NONSYMBOLIC;

        // No /Serif flag: nothing in a TrueType file reliably states it
        // (OS/2's sFamilyClass nominally does and is left at 0 by most
        // tools), it only steers substitution when the embedded program
        // is missing, and guessing from the family name would be a
        // string match dressed up as a metric.
        return $flags;
    }
}
