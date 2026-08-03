<?php

declare(strict_types=1);

namespace MightyPDF\Editor\Form;

/**
 * The font a field's /DA names, as far as drawing that field's value
 * needs to know: how wide the text is, and what to write to show it.
 *
 * The interface exists because a form's font may be either of two things
 * that have almost nothing in common. A simple font takes one byte per
 * character and carries a /Widths array; a composite one takes codes of
 * its own devising, described by a CMap, and carries widths per
 * character id. TextAppearanceBuilder cares about neither -- it lays out
 * a value in a box -- so the difference stops here.
 *
 * Everything is in terms of UTF-8 text rather than encoded bytes. The
 * builder used to work in WinAnsi throughout, which quietly assumed the
 * answer to the question this interface asks.
 */
interface FieldFont
{
    /** The width of $utf8Text, in points, at $sizePt. */
    public function widthOfPt(string $utf8Text, float $sizePt): float;

    /**
     * $utf8Text as a text-showing operand, written out -- "(...)" or
     * "<...>" depending on what the font's codes are.
     */
    public function show(string $utf8Text): string;

    /**
     * Whether every character of $utf8Text can be written in this font.
     *
     * A value with a character the font cannot reach is left to the
     * reader (/NeedAppearances) rather than drawn with the character
     * missing: an appearance that disagrees with the value is worse than
     * no appearance at all, because it looks finished.
     */
    public function canShow(string $utf8Text): bool;

    /**
     * The text split into the characters a comb field puts one per cell.
     *
     * @return list<string>
     */
    public function characters(string $utf8Text): array;
}
