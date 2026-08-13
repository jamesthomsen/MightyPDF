<?php

declare(strict_types=1);

namespace MightyPDF\Assembler\Types;

use MightyPDF\Exception\InvalidArgumentException;

/**
 * Encodes a PHP (UTF-8) string into WinAnsiEncoding bytes for use in PDF
 * literal strings and content-stream text-showing operators. Lives
 * alongside PdfNumberFormat as a shared low-level encoding utility used
 * by both the content layer (drawn text) and form fields (/V, /DA) --
 * not content-drawing logic itself.
 *
 * WinAnsiEncoding is, for practical purposes, Windows-1252/CP1252 (they
 * differ only in a handful of rarely-used code points), so the
 * conversion itself defers to iconv. The repertoire is wider than
 * Latin-1: CP1252 fills 0x80-0x9F with the typographic punctuation
 * Latin-1 lacks, so the euro sign, en and em dashes, curly quotes and
 * the "œ" ligature all survive as themselves. Characters outside it are
 * transliterated to the closest approximation ("Ł" -> "L", "ﬁ" -> "fi"),
 * and one iconv cannot transliterate becomes "?" -- text is never
 * dropped and encoding never fails on account of the repertoire, because
 * a caller drawing a name has no way to recover from a mid-string
 * refusal and an empty string is invisible in review and obvious in
 * print. Drawing the rest of Unicode as itself needs font embedding; see
 * EmbeddedFont.
 *
 * Transliteration is asked for one character at a time, which is what
 * makes that promise portable rather than a property of one iconv build:
 * glibc's //TRANSLIT substitutes "?" for what it cannot transliterate
 * and reports success, while GNU libiconv (macOS, musl) fails the
 * *whole* conversion instead, so a string with even one such character
 * used to come back false there and be refused. Per character, the worst
 * either can do is one "?".
 *
 * "Never dropped" is checked rather than assumed, because a successful
 * conversion is not by itself evidence that anything came out: glibc
 * converts the Unicode tag block, U+E0000-U+E007F, to the empty string
 * and reports success either way. A conversion that keeps every
 * character returns one byte per character, CP1252 being single-byte,
 * so anything else falls through to the per-character walk.
 *
 * What the repertoire *is* does not go through iconv at all -- see
 * repertoire() and unrepresentableCharacters(). No iconv call answers
 * that question the same way everywhere: whether a conversion
 * transliterates when it was not asked to is build-specific, so a probe
 * converting "Ł" reads as a hit on the builds that quietly return "L".
 *
 * Malformed UTF-8 still throws: that is a caller mistake rather than a
 * limit of the encoding, and substituting for it would turn a bad byte
 * into plausible-looking text.
 */
final class WinAnsiEncoding
{
    /**
     * The CP1252 characters that are not simply the code point equal to
     * their byte: 0x80-0x9F, where CP1252 puts the typographic
     * punctuation Latin-1 leaves as C1 controls. Five of those 32 codes
     * -- 0x81, 0x8D, 0x8F, 0x90, 0x9D -- are undefined in CP1252 as
     * well, and so are absent here.
     *
     * Keyed by the byte rather than listed in order, because both
     * directions read it: repertoire() wants the characters and decode()
     * wants which byte each one is.
     */
    private const HIGH_PUNCTUATION = [
        0x80 => '€', 0x82 => '‚', 0x83 => 'ƒ', 0x84 => '„', 0x85 => '…', 0x86 => '†',
        0x87 => '‡', 0x88 => 'ˆ', 0x89 => '‰', 0x8A => 'Š', 0x8B => '‹', 0x8C => 'Œ',
        0x8E => 'Ž', 0x91 => '‘', 0x92 => '’', 0x93 => '“', 0x94 => '”', 0x95 => '•',
        0x96 => '–', 0x97 => '—', 0x98 => '˜', 0x99 => '™', 0x9A => 'š', 0x9B => '›',
        0x9C => 'œ', 0x9E => 'ž', 0x9F => 'Ÿ',
    ];

    private function __construct()
    {
    }

    public static function encode(string $utf8Text): string
    {
        // Asked without //TRANSLIT first: if the whole string is already
        // in the repertoire -- which almost all drawn text is -- this
        // succeeds and is exactly the bytes wanted. Anything else is
        // worth the per-character walk below to keep transliteration
        // from being a whole-string all-or-nothing.
        $encoded = @iconv('UTF-8', 'CP1252', $utf8Text);

        // Bytes that came back identical are proof enough on their own:
        // every non-ASCII character is one CP1252 stores at a different
        // length or a different byte, so an unchanged string was ASCII,
        // which CP1252 shares outright. That is the common case for
        // drawn text and worth not paying a scan for.
        if ($encoded === $utf8Text) {
            return $encoded;
        }

        // Otherwise success is not taken at face value. CP1252 is
        // single-byte, so a conversion that kept every character
        // returned exactly one byte per character: fewer means iconv
        // dropped something and reported success -- glibc converts the
        // U+E0000 tag block to nothing at all -- and more means it
        // transliterated unasked, which Apple's libiconv 1.11 does. The
        // walk answers both honestly, the first with "?" and the second
        // with the same transliteration iconv just made.
        if ($encoded !== false && strlen($encoded) === self::characterCount($utf8Text)) {
            return $encoded;
        }

        $result = '';

        foreach (self::characters($utf8Text) as $character) {
            $result .= self::encodeCharacter($character);
        }

        return $result;
    }

    /**
     * The repertoire picks which conversion to ask for; iconv still does
     * the converting. Asking the table rather than trying the plain
     * conversion and reading its failure keeps the choice off builds
     * that transliterate unasked, and costs one iconv call less per
     * character outside the repertoire.
     *
     * Either result coming back empty is treated as no result at all:
     * glibc renders the U+E0000 tag block as nothing and reports
     * success, and silently dropping a character is the one outcome
     * this class promises never to produce.
     */
    private static function encodeCharacter(string $character): string
    {
        $encoded = isset(self::repertoire()[$character])
            ? @iconv('UTF-8', 'CP1252', $character)
            : @iconv('UTF-8', 'CP1252//TRANSLIT', $character);

        return $encoded === false || $encoded === '' ? '?' : $encoded;
    }

    /**
     * Counts characters without splitting the string, which the fast
     * path above would otherwise pay for on every call: in valid UTF-8
     * every character is one lead byte plus its continuation bytes, and
     * only continuation bytes fall in 0x80-0xBF. Invalid UTF-8 makes
     * this meaningless, but iconv has already refused it by the time the
     * count is compared.
     */
    private static function characterCount(string $utf8Text): int
    {
        return strlen($utf8Text) - (int) preg_match_all('/[\x80-\xBF]/', $utf8Text);
    }

    /**
     * The characters of $utf8Text that WinAnsiEncoding has no code for,
     * without duplicates and in the order they appear -- the ones
     * encode() will transliterate or substitute rather than carry
     * through.
     *
     * Answered from the repertoire table rather than by attempting a
     * conversion. CP1252 is 256 static entries, so the question has one
     * right answer, but iconv is not the way to get it: a plain
     * UTF-8-to-CP1252 conversion is only lossless on builds that stick
     * to the repertoire, and Apple's bundled libiconv transliterates
     * without being asked, returning "L" for "Ł" instead of false. A
     * probe built on that reports "Łódź" as fully representable on macOS
     * and not on a conforming build -- the same string, classified by
     * platform. A table also spares the caller an iconv call per
     * character in favour of a hash lookup.
     *
     * @return list<string>
     */
    public static function unrepresentableCharacters(string $utf8Text): array
    {
        $repertoire = self::repertoire();
        $missing = [];

        foreach (self::characters($utf8Text) as $character) {
            if (!isset($repertoire[$character])) {
                $missing[$character] = $character;
            }
        }

        return array_values($missing);
    }

    /**
     * CP1252 bytes back to UTF-8.
     *
     * The inverse of encode(), and total where encode() is lossy: every
     * one of the 251 assigned bytes has exactly one character, so this
     * cannot approximate, fail, or depend on an iconv build -- it is the
     * same table read the other way.
     *
     * Bytes CP1252 leaves undefined are dropped rather than guessed at.
     * They cannot come from encode(), and Latin-1's C1 controls are not
     * what a byte in a CP1252 string was meant to be.
     */
    public static function decode(string $bytes): string
    {
        // Flipped once per process, on the same reasoning as repertoire()
        // memoizing itself: GlyphFallback calls this once per character
        // it substitutes, and flipping 251 entries per call made the
        // table rebuild about half the cost of substituting a name.
        static $characters = null;

        $characters ??= array_flip(self::repertoire());
        $utf8 = '';

        for ($i = 0, $length = strlen($bytes); $i < $length; $i++) {
            $utf8 .= $characters[ord($bytes[$i])] ?? '';
        }

        return $utf8;
    }

    /**
     * Every character CP1252 has a code for, as a map from character to
     * the byte that stands for it. Built once per process rather than
     * written out as 251 literals: the two long runs are contiguous, and
     * the run boundaries say what the table means in a way a wall of
     * characters would not.
     *
     * @return array<string, int>
     */
    private static function repertoire(): array
    {
        static $repertoire = null;

        if ($repertoire !== null) {
            return $repertoire;
        }

        $repertoire = [];

        // 0x00-0x7F and 0xA0-0xFF are the code points equal to the byte,
        // as ASCII and Latin-1 leave them; 0x80-0x9F is the range CP1252
        // reassigns, and is HIGH_PUNCTUATION's.
        for ($code = 0x00; $code <= 0x7F; $code++) {
            $repertoire[chr($code)] = $code;
        }

        for ($code = 0xA0; $code <= 0xFF; $code++) {
            $repertoire[chr(0xC0 | $code >> 6) . chr(0x80 | $code & 0x3F)] = $code;
        }

        foreach (self::HIGH_PUNCTUATION as $code => $character) {
            $repertoire[$character] = $code;
        }

        return $repertoire;
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
            throw new InvalidArgumentException('Text is not valid UTF-8.');
        }

        return $characters;
    }
}
