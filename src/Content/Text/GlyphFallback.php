<?php

declare(strict_types=1);

namespace MightyPDF\Content\Text;

use MightyPDF\Assembler\Types\WinAnsiEncoding;
use MightyPDF\Content\Font\Font;

/**
 * Rewrites text so a font can draw all of it, replacing what it cannot
 * with the closest thing it can.
 *
 * Drawing normally refuses instead: an embedded font throws on the first
 * character it has no glyph for, deliberately, because a blank box is
 * invisible in review and obvious in print (see EmbeddedFont). That is
 * the right default for a library and the wrong one for a document
 * assembled on demand from names other people typed -- there, a name
 * rendered imperfectly beats a report that returns a 500. This is how a
 * caller chooses the second behaviour explicitly, per document, rather
 * than by catching an exception it cannot act on.
 *
 * Two properties make it safe to build a document on:
 *
 * - Text is never blanked. Every character becomes at least "?", so a
 *   company name that could not be set is visibly approximate rather
 *   than missing, which is the one failure worse than an approximation:
 *   nothing on the page shows it happened. (The exception is a font with
 *   no "?" of its own, which is a symbol font being asked to set prose.)
 * - The answer does not depend on the iconv build. Transliteration is
 *   asked for one character at a time and non-ASCII or empty results are
 *   refused, so the GNU libiconv behaviour of failing a whole string it
 *   cannot fully transliterate -- and the Apple build's habit of
 *   transliterating unasked -- change nothing here. Same discipline, and
 *   the same reason, as WinAnsiEncoding.
 */
final class GlyphFallback
{
    private function __construct()
    {
    }

    /**
     * $text with every character $font cannot draw as itself replaced by
     * a transliteration it can, or by "?".
     *
     * The whole string is tested first because that is the case that
     * matters: text a font can already set comes back untouched, without
     * a per-character walk or a single iconv call.
     */
    public static function apply(string $text, Font $font): string
    {
        if ($text === '' || $font->supports($text)) {
            return $text;
        }

        $result = '';

        foreach (Utf8::characters($text) as $character) {
            $result .= $font->supports($character) ? $character : self::closest($character, $font);
        }

        return $result;
    }

    /**
     * The best thing $font can set in place of $character, from a ladder
     * of increasingly lossy candidates.
     *
     * The order is what makes the result readable. CP1252 comes first
     * because it keeps the character *as a character*: "ǽ" becomes "æ",
     * "œ" stays "œ", "€" stays "€". ASCII is the same conversion with
     * the diacritics thrown away, and it also expands -- "€" to "EUR",
     * "½" to " 1/2 ", spaces and all -- which changes the width of the
     * cell it lands in and introduces a line-break opportunity in the
     * middle of a word. That is worth having as a second resort and not
     * as a first.
     *
     * The font decides, at every rung. What limits a substitution is
     * which glyphs this font actually has, not which characters some
     * charset happens to contain, so a candidate is only taken if the
     * font can set it.
     */
    private static function closest(string $character, Font $font): string
    {
        foreach ([self::toCp1252($character), self::toAscii($character)] as $candidate) {
            if ($candidate !== null && $font->supports($candidate)) {
                return $candidate;
            }
        }

        // A font with no question mark cannot say "something was here",
        // so the character is dropped rather than drawn as a notdef box.
        return $font->supports('?') ? '?' : '';
    }

    /**
     * One character transliterated into CP1252 and read back out as
     * UTF-8, or null where that produced nothing.
     *
     * The trip back through WinAnsiEncoding::decode() is a table lookup
     * rather than a second conversion, so only the transliteration is
     * iconv's -- and that is asked one character at a time, which is
     * what keeps a build that fails whole strings from mattering.
     */
    private static function toCp1252(string $character): ?string
    {
        $bytes = @iconv('UTF-8', 'CP1252//TRANSLIT', $character);

        if ($bytes === false || $bytes === '') {
            return null;
        }

        $utf8 = WinAnsiEncoding::decode($bytes);

        return $utf8 === '' ? null : $utf8;
    }

    /**
     * The same into ASCII, or null where that cannot be trusted.
     *
     * Anything that comes back empty, or with a byte outside printable
     * ASCII, is refused rather than used: the point of the conversion is
     * a result a font is likely to have, and a build that answers with
     * the original character, with nothing, or with Latin-1 bytes has
     * not produced one. Refusing leaves "?", which is always drawable.
     */
    private static function toAscii(string $character): ?string
    {
        $ascii = @iconv('UTF-8', 'ASCII//TRANSLIT', $character);

        if ($ascii === false || preg_match('/^[\x20-\x7E]+$/', $ascii) !== 1) {
            return null;
        }

        return $ascii;
    }
}
