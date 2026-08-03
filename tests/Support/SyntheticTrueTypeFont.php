<?php

declare(strict_types=1);

namespace MightyPDF\Tests\Support;

/**
 * Builds a small but genuine TrueType font, byte by byte, to test the
 * font parser, subsetter and embedding against.
 *
 * Written out here rather than shipping a .ttf fixture for two reasons.
 * A binary blob in the repository is a fixture nobody can read: "the
 * subset kept the right glyph" is only checkable against a font whose
 * every glyph is known, and a real font's are not. And a real font is
 * someone's licensed work, which a test suite has no business
 * redistributing.
 *
 * It is deliberately an independent implementation of the format rather
 * than a call into TrueTypeSubset's serializer -- a fixture built by the
 * code under test proves only that the code agrees with itself. Same
 * reasoning as EncryptedPdfFixture. That these bytes are a real font has
 * been checked outside this suite as well: FreeType, via fontconfig's
 * fc-query, loads them without complaint.
 *
 * The font holds six glyphs, with 1000 units to the em so that font
 * units and PDF glyph space are the same number:
 *
 *   0  .notdef       no outline
 *   1  U+0041 "A"    a triangle, 600 wide
 *   2  U+0042 "B"    a triangle, 700 wide
 *   3  U+00B4 "´"    a triangle, 300 wide
 *   4  U+00C1 "Á"    a composite of glyphs 1 and 3, 600 wide
 *   5  U+1F600       a triangle, 800 wide -- past the BMP, and so
 *                    reachable only through the format 12 subtable
 *   6  U+0020 " "    no outline, 250 wide -- a space is a real glyph
 *                    with a real width, and text that has to be
 *                    wrapped or justified is made of them
 */
final class SyntheticTrueTypeFont
{
    public const int UNITS_PER_EM = 1000;
    public const string POSTSCRIPT_NAME = 'SyntheticTest';

    public const int ASCENT = 800;
    public const int DESCENT = -200;
    public const int CAP_HEIGHT = 700;

    public const int GLYPH_A = 1;
    public const int GLYPH_B = 2;
    public const int GLYPH_ACUTE = 3;
    public const int GLYPH_A_ACUTE = 4;
    public const int GLYPH_ASTRAL = 5;
    public const int GLYPH_SPACE = 6;

    public const int ASTRAL_CODE_POINT = 0x1F600;

    /** @var array<int, int> glyph id => advance width, in font units */
    public const array ADVANCES = [
        0 => 500,
        self::GLYPH_A => 600,
        self::GLYPH_B => 700,
        self::GLYPH_ACUTE => 300,
        self::GLYPH_A_ACUTE => 600,
        self::GLYPH_ASTRAL => 800,
        self::GLYPH_SPACE => 250,
    ];

    /**
     * Ascending by code point, which the format 4 subtable built below
     * requires of its segments.
     *
     * @var array<int, int> code point => glyph id
     */
    public const array CHARACTERS = [
        0x0020 => self::GLYPH_SPACE,
        0x0041 => self::GLYPH_A,
        0x0042 => self::GLYPH_B,
        0x00B4 => self::GLYPH_ACUTE,
        0x00C1 => self::GLYPH_A_ACUTE,
        self::ASTRAL_CODE_POINT => self::GLYPH_ASTRAL,
    ];

    private function __construct()
    {
    }

    /**
     * @param ?array<int, int> $characters code point => glyph id, for a
     *        font whose cmap covers something other than every glyph --
     *        the ids a font embedded whole describes come from its cmap,
     *        so leaving a glyph out of it is how gaps in them are made.
     *        Ascending by code point, as cmapFormat4() requires.
     */
    public static function build(?array $characters = null): string
    {
        $characters ??= self::CHARACTERS;
        $glyphs = [
            0 => '',
            self::GLYPH_A => self::simpleGlyph(),
            self::GLYPH_B => self::simpleGlyph(),
            self::GLYPH_ACUTE => self::simpleGlyph(),
            self::GLYPH_A_ACUTE => self::compositeGlyph(self::GLYPH_A, self::GLYPH_ACUTE),
            self::GLYPH_ASTRAL => self::simpleGlyph(),
            self::GLYPH_SPACE => '',
        ];

        [$glyf, $loca] = self::glyfAndLoca($glyphs);

        return self::serialize([
            'head' => self::head(),
            'hhea' => self::hhea(count($glyphs)),
            'maxp' => self::maxp(count($glyphs)),
            'hmtx' => self::hmtx(),
            'cmap' => self::cmap($characters),
            'loca' => $loca,
            'glyf' => $glyf,
            'name' => self::name(),
            'post' => self::post(),
            'OS/2' => self::os2(),
        ]);
    }

    /** A font with no 'glyf' table at all, i.e. one this library must refuse. */
    public static function withoutOutlines(): string
    {
        return self::serialize([
            'head' => self::head(),
            'hhea' => self::hhea(1),
            'maxp' => self::maxp(1),
            'CFF ' => str_repeat("\x00", 16),
        ]);
    }

    /** A three-point triangle: the smallest thing that is unambiguously an outline. */
    private static function simpleGlyph(): string
    {
        return pack('nnnnn', 1, 0, 0, 500, 700)      // one contour, bounding box
            . pack('n', 2)                           // last point index of contour 0
            . pack('n', 0)                           // no hinting instructions
            . "\x01\x01\x01"                         // three on-curve points
            . pack('nnn', 0, 500, 0xFF06)            // x deltas: 0, +500, -250
            . pack('nnn', 0, 0, 700);                // y deltas
    }

    /**
     * An accented letter, drawn as a base glyph plus a mark placed above
     * it -- the case that makes subsetting more than a filter, since the
     * components have to come along and be renumbered.
     */
    private static function compositeGlyph(int $base, int $mark): string
    {
        return pack('nnnnn', 0xFFFF, 0, 0, 500, 900) // -1 contours: composite
            . pack('nnnn', 0x0023, $base, 0, 0)      // words + xy values + more
            . pack('nnnn', 0x0003, $mark, 100, 300); // words + xy values, last
    }

    /**
     * @param array<int, string> $glyphs
     * @return array{string, string}
     */
    private static function glyfAndLoca(array $glyphs): array
    {
        $glyf = '';
        $offsets = [0];

        foreach ($glyphs as $data) {
            $glyf .= $data . str_repeat("\x00", (4 - strlen($data) % 4) % 4);
            $offsets[] = strlen($glyf);
        }

        $loca = '';
        foreach ($offsets as $offset) {
            $loca .= pack('N', $offset);
        }

        return [$glyf, $loca];
    }

    private static function head(): string
    {
        return pack('NN', 0x00010000, 0x00010000)    // version, font revision
            . pack('NN', 0, 0x5F0F3CF5)              // checksum adjustment, magic
            . pack('nn', 0, self::UNITS_PER_EM)      // flags, units per em
            . str_repeat("\x00", 16)                 // created, modified
            . pack('nnnn', 0, 0xFF38, 500, 900)      // xMin, yMin (-200), xMax, yMax
            . pack('nnn', 0, 8, 2)                   // macStyle, lowestRecPPEM, direction
            . pack('nn', 1, 0);                      // long loca offsets, glyph format
    }

    private static function hhea(int $glyphCount): string
    {
        return pack('N', 0x00010000)
            . pack('nnn', self::ASCENT, self::DESCENT & 0xFFFF, 0)
            . pack('nnnn', 800, 0, 0, 500)           // advance max, bearings, extent
            . pack('nnn', 1, 0, 0)                   // caret slope rise/run, offset
            . str_repeat("\x00", 8)                  // four reserved fields
            . pack('nn', 0, $glyphCount);            // metric format, hMetric count
    }

    private static function maxp(int $glyphCount): string
    {
        return pack('N', 0x00010000) . pack('n', $glyphCount) . str_repeat("\x00", 26);
    }

    private static function hmtx(): string
    {
        $hmtx = '';

        foreach (self::ADVANCES as $advance) {
            $hmtx .= pack('nn', $advance, 0);
        }

        return $hmtx;
    }

    /**
     * Both a format 4 subtable covering the BMP characters and a format
     * 12 one covering everything, so that a reader picking only one of
     * them is visibly wrong: format 4 alone cannot reach U+1F600, and
     * format 12 alone is not what most fonts offer.
     */
    /** @param array<int, int> $characters */
    private static function cmap(array $characters): string
    {
        $format4 = self::cmapFormat4($characters);
        $format12 = self::cmapFormat12($characters);

        $header = pack('nn', 0, 2)
            . pack('nnN', 3, 1, 4 + 2 * 8)
            . pack('nnN', 3, 10, 4 + 2 * 8 + strlen($format4));

        return $header . $format4 . $format12;
    }

    /** @param array<int, int> $characters */
    private static function cmapFormat4(array $characters): string
    {
        $bmp = [];

        foreach ($characters as $codePoint => $glyph) {
            if ($codePoint <= 0xFFFF) {
                $bmp[$codePoint] = $glyph;
            }
        }

        // One segment per character, plus the required 0xFFFF terminator.
        $segments = [];
        foreach ($bmp as $codePoint => $glyph) {
            $segments[] = [$codePoint, $codePoint, ($glyph - $codePoint) & 0xFFFF];
        }
        $segments[] = [0xFFFF, 0xFFFF, 1];

        $count = count($segments);
        $entrySelector = (int) floor(log($count, 2));
        $searchRange = 2 ** $entrySelector * 2;

        $ends = $starts = $deltas = $rangeOffsets = '';
        foreach ($segments as [$start, $end, $delta]) {
            $ends .= pack('n', $end);
            $starts .= pack('n', $start);
            $deltas .= pack('n', $delta);
            $rangeOffsets .= pack('n', 0);
        }

        $body = pack('nnnn', $count * 2, $searchRange, $entrySelector, $count * 2 - $searchRange)
            . $ends . pack('n', 0) . $starts . $deltas . $rangeOffsets;

        return pack('nnn', 4, strlen($body) + 6, 0) . $body;
    }

    /** @param array<int, int> $characters */
    private static function cmapFormat12(array $characters): string
    {
        $groups = '';

        foreach ($characters as $codePoint => $glyph) {
            $groups .= pack('NNN', $codePoint, $codePoint, $glyph);
        }

        return pack('nn', 12, 0)
            . pack('NN', 16 + strlen($groups), 0)
            . pack('N', count($characters))
            . $groups;
    }

    private static function name(): string
    {
        $value = (string) iconv('UTF-8', 'UTF-16BE', self::POSTSCRIPT_NAME);
        $records = pack('nnnnnn', 3, 1, 0x0409, 6, strlen($value), 0);

        return pack('nnn', 0, 1, 6 + 12) . $records . $value;
    }

    private static function post(): string
    {
        return pack('N', 0x00030000)                 // version 3.0: no glyph names
            . pack('NN', 0, 0)                       // italic angle, underline position
            . pack('NN', 0, 0)                       // underline thickness, isFixedPitch
            . str_repeat("\x00", 16);                // VM usage fields
    }

    private static function os2(): string
    {
        $table = pack('n', 2)                        // version 2, which has sCapHeight
            . pack('nn', 500, 400)                   // average width, weight class
            . str_repeat("\x00", 62)                 // through to sTypoAscender
            . pack('nnn', self::ASCENT, self::DESCENT & 0xFFFF, 0)
            . pack('nn', self::ASCENT, 200)          // usWin ascent/descent
            . str_repeat("\x00", 8)                  // code page ranges
            . pack('n', 500)                         // sxHeight
            . pack('n', self::CAP_HEIGHT)
            . str_repeat("\x00", 6);

        return $table;
    }

    /** @param array<string, string> $tables */
    private static function serialize(array $tables): string
    {
        ksort($tables);

        $count = count($tables);
        $entrySelector = (int) floor(log($count, 2));
        $searchRange = 2 ** $entrySelector * 16;

        $directory = pack('Nnnnn', 0x00010000, $count, $searchRange, $entrySelector, $count * 16 - $searchRange);
        $offset = 12 + $count * 16;
        $body = '';

        foreach ($tables as $tag => $data) {
            $padded = $data . str_repeat("\x00", (4 - strlen($data) % 4) % 4);
            $directory .= $tag . pack('NNN', 0, $offset, strlen($data));
            $body .= $padded;
            $offset += strlen($padded);
        }

        return $directory . $body;
    }
}
