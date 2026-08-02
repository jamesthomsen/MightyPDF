<?php

declare(strict_types=1);

namespace MightyPDF\Content\Font\TrueType;

/**
 * The character-to-glyph mapping of a TrueType font ('cmap'), reduced to
 * the one question this library asks of it: which glyph draws this
 * Unicode code point?
 *
 * A font's cmap is a *set* of subtables for different platforms and
 * encodings, and they do not all say the same thing -- the Macintosh
 * (1, 0) subtable covers 256 characters of MacRoman, while the Windows
 * (3, 1) one covers the Basic Multilingual Plane and (3, 10) covers all
 * of Unicode. Picking the widest-coverage subtable rather than the first
 * one present is the whole job of choose() below: reading (1, 0) from a
 * font that also has (3, 10) would turn every non-Latin character into a
 * missing glyph while the font in fact contains it.
 *
 * Symbol fonts (3, 0) are the exception to "code point in, glyph out":
 * they map their characters into the Private Use Area at U+F000, so a
 * request for U+0041 has to be retried as U+F041. See glyphFor().
 */
final class CmapTable
{
    private const int SYMBOL_RANGE_START = 0xF000;

    /**
     * A ceiling on how many characters one font may claim to map.
     *
     * Format 12 states its ranges as pairs of 32-bit code points, so a
     * single malformed group can declare the whole of Unicode -- and
     * expanding it fills memory with entries for a font that has at most
     * 65,535 glyphs to point them at. The largest real fonts (the pan-CJK
     * ones) map on the order of 65,000 characters, so this leaves room
     * for several times the worst honest case.
     */
    private const int MAX_MAPPINGS = 200_000;

    /**
     * Subtable (platformID, encodingID) pairs in descending order of
     * coverage. A font offering several gets read through the first of
     * these it has.
     *
     * @var list<array{int, int}>
     */
    private const array PREFERENCE = [
        [3, 10], // Windows, full Unicode
        [0, 6],  // Unicode 13.0+, full Unicode
        [0, 4],  // Unicode 2.0+, full Unicode
        [3, 1],  // Windows, BMP -- by far the most common
        [0, 3],  // Unicode 2.0+, BMP
        [0, 2],
        [0, 1],
        [0, 0],
        [3, 0],  // Windows symbol -- see the class doc comment
        [1, 0],  // Macintosh, 256 characters of MacRoman
    ];

    private readonly bool $isSymbolic;

    /** @var array<int, int> code point => glyph id, for the chosen subtable only */
    private readonly array $map;

    private function __construct(array $map, bool $isSymbolic)
    {
        $this->map = $map;
        $this->isSymbolic = $isSymbolic;
    }

    /**
     * Reads a whole 'cmap' table into one lookup.
     *
     * Subtables are merged rather than chosen between, in the preference
     * order above, with the first mapping of a code point winning.
     * Taking only the widest-coverage subtable would be the obvious
     * alternative, and it quietly loses characters the font has: a
     * font's subtables are not required to agree, and in practice they
     * do not -- DroidSansFallbackFull's (3, 1) and (3, 10) subtables
     * list different ranges, the latter reaching characters outside the
     * BMP that the former, being 16-bit, cannot express at all.
     *
     * Returns null for a font with no subtable in any format understood
     * here rather than throwing: a font can still be embedded and drawn
     * with when its cmap is unusable, as long as the caller works in
     * glyph ids -- it is asking for a *character* that has to fail then,
     * and EmbeddedFont is where that failure belongs.
     */
    public static function parse(string $bytes): ?self
    {
        $reader = new SfntReader($bytes);

        if (!$reader->has(0, 4)) {
            return null;
        }

        $subtableCount = $reader->uint16(2);
        $offsets = [];

        for ($i = 0; $i < $subtableCount; ++$i) {
            $record = 4 + $i * 8;

            if (!$reader->has($record, 8)) {
                break;
            }

            $offsets[$reader->uint16($record) . ':' . $reader->uint16($record + 2)] = $reader->uint32($record + 4);
        }

        $map = [];
        $symbolOnly = true;
        $found = false;

        foreach (self::PREFERENCE as [$platform, $encoding]) {
            $offset = $offsets["$platform:$encoding"] ?? null;

            if ($offset === null || !$reader->has($offset, 4)) {
                continue;
            }

            $subtable = self::parseSubtable($reader, $offset);

            if ($subtable === null || $subtable === []) {
                continue;
            }

            $found = true;

            // + rather than array_merge: numeric keys, and the earlier
            // (more preferred) subtable's mapping of a code point has to
            // survive the later one's.
            $map += $subtable;

            if (count($map) > self::MAX_MAPPINGS) {
                throw new FontException(sprintf(
                    'Font maps more than %d characters, which no real font does -- its "cmap" table is corrupt.',
                    self::MAX_MAPPINGS,
                ));
            }

            if ($platform !== 3 || $encoding !== 0) {
                $symbolOnly = false;
            }
        }

        return $found ? new self($map, $symbolOnly) : null;
    }

    /**
     * The glyph drawing $codePoint, or null when the font has none.
     *
     * Glyph 0 is not a glyph: it is .notdef, the empty box a font shows
     * for a character it does not have, so a subtable mapping something
     * to 0 is saying "absent" in the same words as a subtable that omits
     * it entirely. Returning null for both is what lets EmbeddedFont
     * report a missing character instead of drawing a box.
     */
    public function glyphFor(int $codePoint): ?int
    {
        $glyph = $this->map[$codePoint] ?? null;

        // A symbol font's own idea of "A" lives at U+F041, and the text
        // being drawn will say U+0041 -- there is no way to type the
        // former and no reason to expect a caller to.
        if ($glyph === null && $this->isSymbolic && $codePoint <= 0xFF) {
            $glyph = $this->map[self::SYMBOL_RANGE_START + $codePoint] ?? null;
        }

        return ($glyph === null || $glyph === 0) ? null : $glyph;
    }

    /** @return array<int, int>|null */
    private static function parseSubtable(SfntReader $reader, int $offset): ?array
    {
        return match ($reader->uint16($offset)) {
            0 => self::parseFormat0($reader, $offset),
            4 => self::parseFormat4($reader, $offset),
            6 => self::parseFormat6($reader, $offset),
            12 => self::parseFormat12($reader, $offset),
            // Formats 2 (mixed 8/16-bit CJK), 13 and 14 (variation
            // sequences) exist, but never as a font's only mapping.
            default => null,
        };
    }

    /** @return array<int, int> */
    private static function parseFormat0(SfntReader $reader, int $offset): array
    {
        $map = [];

        for ($code = 0; $code < 256; ++$code) {
            $glyph = $reader->uint8($offset + 6 + $code);

            if ($glyph !== 0) {
                $map[$code] = $glyph;
            }
        }

        return $map;
    }

    /**
     * Format 4: segments of contiguous code points, each either offset by
     * a delta or indexed into a shared glyph array. The awkward part is
     * idRangeOffset, which is a byte distance measured *from where the
     * offset itself is stored* -- a 1980s trick for making the whole
     * subtable relocatable, and the one place a naive reader goes wrong.
     *
     * @return array<int, int>
     */
    private static function parseFormat4(SfntReader $reader, int $offset): array
    {
        $segCount = intdiv($reader->uint16($offset + 6), 2);
        $endCodes = $offset + 14;
        $startCodes = $endCodes + $segCount * 2 + 2; // + reservedPad
        $idDeltas = $startCodes + $segCount * 2;
        $idRangeOffsets = $idDeltas + $segCount * 2;

        if (!$reader->has($idRangeOffsets, $segCount * 2)) {
            return [];
        }

        $map = [];

        for ($segment = 0; $segment < $segCount; ++$segment) {
            $end = $reader->uint16($endCodes + $segment * 2);
            $start = $reader->uint16($startCodes + $segment * 2);
            $delta = $reader->int16($idDeltas + $segment * 2);
            $rangeOffsetAt = $idRangeOffsets + $segment * 2;
            $rangeOffset = $reader->uint16($rangeOffsetAt);

            // The final segment is required to be 0xFFFF..0xFFFF, a
            // terminator rather than a character.
            if ($start > $end || $start === 0xFFFF) {
                continue;
            }

            for ($code = $start; $code <= $end; ++$code) {
                if ($rangeOffset === 0) {
                    $glyph = ($code + $delta) & 0xFFFF;
                } else {
                    $glyphAt = $rangeOffsetAt + $rangeOffset + ($code - $start) * 2;

                    if (!$reader->has($glyphAt, 2)) {
                        continue;
                    }

                    $glyph = $reader->uint16($glyphAt);

                    if ($glyph !== 0) {
                        $glyph = ($glyph + $delta) & 0xFFFF;
                    }
                }

                if ($glyph !== 0) {
                    $map[$code] = $glyph;
                }
            }
        }

        return $map;
    }

    /** @return array<int, int> */
    private static function parseFormat6(SfntReader $reader, int $offset): array
    {
        $first = $reader->uint16($offset + 6);
        $count = $reader->uint16($offset + 8);

        if (!$reader->has($offset + 10, $count * 2)) {
            return [];
        }

        $map = [];

        for ($i = 0; $i < $count; ++$i) {
            $glyph = $reader->uint16($offset + 10 + $i * 2);

            if ($glyph !== 0) {
                $map[$first + $i] = $glyph;
            }
        }

        return $map;
    }

    /**
     * Format 12: groups of contiguous code points, and the only format
     * that reaches past the Basic Multilingual Plane -- emoji and the
     * less common CJK planes are here or nowhere.
     *
     * @return array<int, int>
     */
    private static function parseFormat12(SfntReader $reader, int $offset): array
    {
        $groupCount = $reader->uint32($offset + 12);

        if (!$reader->has($offset + 16, $groupCount * 12)) {
            return [];
        }

        $map = [];

        for ($group = 0; $group < $groupCount; ++$group) {
            $at = $offset + 16 + $group * 12;
            $start = $reader->uint32($at);
            $end = $reader->uint32($at + 4);
            $startGlyph = $reader->uint32($at + 8);

            // A group spanning more code points than Unicode has is
            // corrupt (or hostile): expanding it would fill memory with
            // entries for characters that cannot exist.
            if ($start > $end || $end > 0x10FFFF) {
                continue;
            }

            // Checked before expanding rather than after, since the
            // expansion is what costs the memory: one group may legally
            // declare a million code points, and the format states them
            // in 32 bits precisely so that it can.
            if (count($map) + ($end - $start + 1) > self::MAX_MAPPINGS) {
                throw new FontException(sprintf(
                    'Font maps more than %d characters, which no real font does -- its "cmap" table is corrupt.',
                    self::MAX_MAPPINGS,
                ));
            }

            for ($code = $start; $code <= $end; ++$code) {
                $glyph = $startGlyph + ($code - $start);

                if ($glyph !== 0) {
                    $map[$code] = $glyph;
                }
            }
        }

        return $map;
    }
}
