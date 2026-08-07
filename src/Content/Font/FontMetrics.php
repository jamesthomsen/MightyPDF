<?php

declare(strict_types=1);

namespace MightyPDF\Content\Font;

/**
 * Per-character advance widths (in 1/1000 em units, per ISO 32000-2
 * §9.2.4) for a single font, keyed by WinAnsi code point.
 */
final class FontMetrics
{
    private const int DEFAULT_WIDTH = 500;

    /** @param array<int, int> $widths WinAnsi code point => advance width */
    public function __construct(
        private readonly array $widths,
        private readonly int $defaultWidth = self::DEFAULT_WIDTH,
    ) {
    }

    /**
     * The codes WinAnsiEncoding assigns no glyph to: 0x00-0x1F and 0x7F,
     * the C0 controls and DEL. ISO 32000-2 Annex D.2 names a glyph for
     * every other code in the repertoire and none for these.
     *
     * They are worth stating because they are *encodable* -- CP1252 maps
     * them to themselves, so WinAnsiEncoding::encode() passes a tab or a
     * DEL straight through rather than transliterating it, and it lands
     * in the content stream as a byte a reader draws nothing for. See
     * forWinAnsi().
     */
    private const array UNMAPPED_WINANSI_CODES = [
        0x00, 0x01, 0x02, 0x03, 0x04, 0x05, 0x06, 0x07, 0x08, 0x09, 0x0A, 0x0B,
        0x0C, 0x0D, 0x0E, 0x0F, 0x10, 0x11, 0x12, 0x13, 0x14, 0x15, 0x16, 0x17,
        0x18, 0x19, 0x1A, 0x1B, 0x1C, 0x1D, 0x1E, 0x1F, 0x7F,
    ];

    public static function fixedWidth(int $width): self
    {
        return self::forWinAnsi([], $width);
    }

    /**
     * Metrics for a font read through WinAnsiEncoding, which is all
     * fourteen standard ones (Symbol and ZapfDingbats are read through
     * their own built-in encodings, but those assign no glyph below 0x20
     * either).
     *
     * The difference from the plain constructor is that the codes with
     * no glyph measure zero rather than falling to $defaultWidth. A
     * reader draws nothing for them, so anything else is measuring ink
     * that will not be there: a tab arriving in a name from a database
     * column adds half an em to every width taken of the string, which
     * moves every centred, right-aligned, wrapped and justified line
     * containing it -- and moves it by an amount nothing on the page
     * accounts for, since the character itself is invisible.
     *
     * Not folded into the constructor because a FontMetrics can also be
     * built from a form field's own /Widths array, where the codes are
     * whatever that font's /Encoding says and 0x09 may well be a glyph
     * (see Editor\Form\TextAppearanceBuilder).
     *
     * @param array<int, int> $widths WinAnsi code point => advance width
     */
    public static function forWinAnsi(array $widths = [], int $defaultWidth = self::DEFAULT_WIDTH): self
    {
        // Union rather than array_merge: integer keys, and a width the
        // table states explicitly must win over the zero default.
        return new self($widths + array_fill_keys(self::UNMAPPED_WINANSI_CODES, 0), $defaultWidth);
    }

    public function widthOfCode(int $code): int
    {
        return $this->widths[$code] ?? $this->defaultWidth;
    }

    /**
     * The rendered width, in points, of $winAnsiBytes at $sizePt -- the
     * bytes must already be WinAnsi-encoded (see WinAnsiEncoding::encode()).
     */
    public function widthOf(string $winAnsiBytes, float $sizePt): float
    {
        $total = 0;
        for ($i = 0, $length = strlen($winAnsiBytes); $i < $length; ++$i) {
            $total += $this->widthOfCode(ord($winAnsiBytes[$i]));
        }

        return $total / 1000.0 * $sizePt;
    }
}
