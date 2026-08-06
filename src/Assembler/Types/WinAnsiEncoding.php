<?php

declare(strict_types=1);

namespace MightyPDF\Assembler\Types;

/**
 * Encodes a PHP (UTF-8) string into WinAnsiEncoding bytes for use in PDF
 * literal strings and content-stream text-showing operators. Lives
 * alongside PdfNumberFormat as a shared low-level encoding utility used
 * by both the content layer (drawn text) and form fields (/V, /DA) --
 * not content-drawing logic itself.
 *
 * WinAnsiEncoding is, for practical purposes, Windows-1252/CP1252 (they
 * differ only in a handful of rarely-used code points), so this defers to
 * iconv rather than hand-rolling a second code-point table. The
 * repertoire is wider than Latin-1: CP1252 fills 0x80-0x9F with the
 * typographic punctuation Latin-1 lacks, so the euro sign, en and em
 * dashes, curly quotes and the "œ" ligature all survive as themselves.
 * Characters outside it are transliterated to the closest approximation
 * ("Ł" -> "L", "ﬁ" -> "fi"), and one iconv cannot transliterate becomes
 * "?" -- text is never dropped and encoding never fails on account of the
 * repertoire, because a caller drawing a name has no way to recover from
 * a mid-string refusal and an empty string is invisible in review and
 * obvious in print. Drawing the rest of Unicode as itself needs font
 * embedding; see EmbeddedFont.
 *
 * Transliteration is asked for one character at a time, which is what
 * makes that promise portable rather than a property of one iconv build:
 * glibc's //TRANSLIT substitutes "?" for what it cannot transliterate
 * and reports success, while GNU libiconv (macOS, musl) fails the
 * *whole* conversion instead, so a string with even one such character
 * used to come back false there and be refused. Per character, the worst
 * either can do is one "?".
 *
 * Malformed UTF-8 still throws: that is a caller mistake rather than a
 * limit of the encoding, and substituting for it would turn a bad byte
 * into plausible-looking text.
 */
final class WinAnsiEncoding
{
    private function __construct()
    {
    }

    public static function encode(string $utf8Text): string
    {
        // Asked without //TRANSLIT first: if the whole string is already
        // in the repertoire -- which almost all drawn text is -- this
        // succeeds, and it is the one conversion whose result no iconv
        // build has any latitude over. Anything else is worth the
        // per-character walk below to keep transliteration from being a
        // whole-string all-or-nothing.
        $encoded = @iconv('UTF-8', 'CP1252', $utf8Text);

        if ($encoded !== false) {
            return $encoded;
        }

        $result = '';

        foreach (self::characters($utf8Text) as $character) {
            $result .= self::encodeCharacter($character);
        }

        return $result;
    }

    private static function encodeCharacter(string $character): string
    {
        $encoded = @iconv('UTF-8', 'CP1252', $character);

        if ($encoded !== false) {
            return $encoded;
        }

        $transliterated = @iconv('UTF-8', 'CP1252//TRANSLIT', $character);

        return $transliterated === false || $transliterated === '' ? '?' : $transliterated;
    }

    /**
     * The characters of $utf8Text that WinAnsiEncoding has no code for,
     * without duplicates and in the order they appear -- the ones
     * encode() will transliterate or substitute rather than carry
     * through. Asked without //TRANSLIT, since a character that only
     * survives as an approximation is precisely one this cannot write.
     *
     * @return list<string>
     */
    public static function unrepresentableCharacters(string $utf8Text): array
    {
        $missing = [];

        foreach (self::characters($utf8Text) as $character) {
            if (@iconv('UTF-8', 'CP1252', $character) === false) {
                $missing[$character] = $character;
            }
        }

        return array_values($missing);
    }

    /**
     * Split into characters with PCRE rather than Content\Text\Utf8:
     * Assembler must not depend on Content (see Document's font cache).
     *
     * @return list<string>
     */
    private static function characters(string $utf8Text): array
    {
        $characters = preg_split('//u', $utf8Text, -1, PREG_SPLIT_NO_EMPTY);

        if ($characters === false) {
            throw new \InvalidArgumentException('Text is not valid UTF-8.');
        }

        return $characters;
    }
}
