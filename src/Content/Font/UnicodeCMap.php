<?php

declare(strict_types=1);

namespace MightyPDF\Content\Font;

use MightyPDF\Content\Text\Utf8;

/**
 * The two CMaps a font addressed by Unicode needs: the /Encoding that
 * turns the codes in a content stream into glyphs, and the /ToUnicode
 * that turns them back into text.
 *
 * A composite font can be addressed two ways, and which one a document
 * wants depends on who writes the text.
 *
 * Identity-H -- the codes *are* glyph numbers -- is right when this
 * library writes every character itself, which is the ordinary case: it
 * is compact, and it reaches glyphs no character maps to.
 *
 * It is wrong for a font someone else will write text in: a form field's
 * value is Unicode, and the reader laying it out has to get from a
 * character to a code somehow. Readers do not agree on how. Poppler
 * reverses /ToUnicode; Ghostscript takes the value's UTF-16 code units as
 * codes directly, which under Identity-H silently lands on whatever glyph
 * happens to share that number -- a form that renders as plausible-looking
 * gibberish when printed, while looking perfectly right on screen.
 *
 * Making the codes UTF-16 satisfies both, because then the two readings
 * agree: /ToUnicode reversed and "the code units are the codes" give the
 * same answer. That is what this builds -- a CMap from UTF-16 code units
 * to glyph numbers, and its identity /ToUnicode counterpart.
 */
final class UnicodeCMap
{
    /** CMap resources are conventionally written in blocks of at most 100 entries. */
    private const int BLOCK = 100;

    /**
     * Sorted by code point, so that runs of consecutive characters can be
     * written as ranges rather than one entry each.
     *
     * @var array<int, int> code point => glyph id
     */
    private readonly array $characters;

    /** @param array<int, int> $characters code point => glyph id */
    public function __construct(array $characters, private readonly string $name)
    {
        ksort($characters);
        $this->characters = $characters;
    }

    public function name(): string
    {
        return $this->name;
    }

    /**
     * The /Encoding CMap: UTF-16BE code units in, glyph numbers out.
     *
     * The code space is UTF-16's own -- two bytes, except for the
     * surrogate pairs standing for characters past the BMP, which are
     * four. Declaring that rather than a flat two-byte space is what
     * stops a reader from splitting an emoji into two codes that mean
     * nothing on their own.
     */
    public function encodingCMap(): string
    {
        [$ranges, $singles] = $this->groupIntoRanges();

        $body = self::blocks($ranges, 'cidrange', static fn (array $range): string => sprintf(
            '<%s> <%s> %d',
            self::codeHex($range[0]),
            self::codeHex($range[1]),
            $range[2],
        ));

        $body .= self::blocks($singles, 'cidchar', static fn (array $single): string => sprintf(
            '<%s> %d',
            self::codeHex($single[0]),
            $single[1],
        ));

        return <<<CMAP
            %!PS-Adobe-3.0 Resource-CMap
            %%DocumentNeededResources: ProcSet (CIDInit)
            %%IncludeResource: ProcSet (CIDInit)
            %%BeginResource: CMap ({$this->name})
            %%EndComments
            /CIDInit /ProcSet findresource begin
            12 dict begin
            begincmap
            /CIDSystemInfo 3 dict dup begin
            /Registry (Adobe) def
            /Ordering (Identity) def
            /Supplement 0 def
            end def
            /CMapName /{$this->name} def
            /CMapType 1 def
            /WMode 0 def
            3 begincodespacerange
            <0000> <D7FF>
            <D800DC00> <DBFFDFFF>
            <E000> <FFFF>
            endcodespacerange
            {$body}endcmap
            CMapName currentdict /CMap defineresource pop
            end
            end
            %%EndResource
            %%EOF
            CMAP;
    }

    /**
     * The /ToUnicode CMap, which for these codes says what they already
     * are -- and is written anyway. A reader has no way to know that the
     * codes are Unicode unless the document says so, and one that assumes
     * they are not copies gibberish out of a page that renders perfectly.
     *
     * Unlike the encoding above, this one is flatly two bytes wide: a
     * /ToUnicode CMap takes one code width throughout, and a reader
     * handed a second one rejects every entry that uses it (poppler
     * warns once per entry and drops it). That costs nothing here,
     * because a code and its meaning are the same UTF-16 -- a character
     * past the BMP is two codes that each stand for themselves, and a
     * reader joining them up gets the surrogate pair it started with.
     */
    public function toUnicodeCMap(): string
    {
        $codes = [];
        $astral = false;

        foreach (array_keys($this->characters) as $codePoint) {
            if ($codePoint > 0xFFFF) {
                $astral = true;
                continue;
            }

            $codes[] = $codePoint;
        }

        if ($astral) {
            // Every surrogate, high and low: which pairs of them a font
            // uses is not worth working out, since each stands for
            // itself either way.
            //
            // Sorted and deduplicated afterwards rather than appended
            // blindly: the surrogates land in the middle of the code
            // points already collected, and a font whose cmap maps one
            // of them itself -- malformed, but files like that exist --
            // would otherwise be written twice. Runs are built by
            // comparing each code with the one before, so both leave a
            // reader entries that overlap or run backwards.
            $codes = array_values(array_unique(array_merge($codes, range(0xD800, 0xDFFF))));
            sort($codes);
        }

        $runs = [];

        foreach ($codes as $codePoint) {
            $last = count($runs) - 1;

            // Broken every 256 for the same reason the encoding's ranges
            // are -- see groupIntoRanges().
            if ($last >= 0 && $runs[$last][1] === $codePoint - 1 && $codePoint >> 8 === $runs[$last][0] >> 8) {
                $runs[$last][1] = $codePoint;
                continue;
            }

            $runs[] = [$codePoint, $codePoint];
        }

        $body = self::blocks($runs, 'bfrange', static fn (array $run): string => sprintf(
            '<%04X> <%04X> <%04X>',
            $run[0],
            $run[1],
            $run[0],
        ));

        return <<<CMAP
            /CIDInit /ProcSet findresource begin
            12 dict begin
            begincmap
            /CIDSystemInfo << /Registry (Adobe) /Ordering (UCS) /Supplement 0 >> def
            /CMapName /Adobe-Identity-UCS def
            /CMapType 2 def
            1 begincodespacerange
            <0000> <FFFF>
            endcodespacerange
            {$body}endcmap
            CMapName currentdict /CMap defineresource pop
            end
            end
            CMAP;
    }

    /** The bytes standing for $utf8Text in a content stream: UTF-16BE. */
    public static function encode(string $utf8Text): string
    {
        $bytes = '';

        foreach (Utf8::codePoints($utf8Text) as $codePoint) {
            $bytes .= self::codeUnits($codePoint);
        }

        return $bytes;
    }

    /**
     * Consecutive characters mapping to consecutive glyphs, as
     * [firstCode, lastCode, firstGlyph] -- plus the ones left over, as
     * [code, glyph].
     *
     * A cidrange may only vary in the last byte of its codes: the two
     * ends must be the same width and agree on every byte before it. So
     * a run is broken every 256 characters even where the font maps them
     * without a gap. Ghostscript enforces this and poppler does not,
     * which is the worst way for it to be wrong -- a Greek range written
     * across the boundary rendered perfectly in one and as empty boxes
     * in the other.
     *
     * @return array{0: list<array{0: int, 1: int, 2: int}>, 1: list<array{0: int, 1: int}>}
     */
    private function groupIntoRanges(): array
    {
        $ranges = [];
        $singles = [];
        $current = null;

        foreach ($this->characters as $codePoint => $glyph) {
            if (
                $current !== null
                && $codePoint === $current[1] + 1
                && $glyph === $current[2] + ($current[1] - $current[0]) + 1
                && $codePoint >> 8 === $current[0] >> 8
            ) {
                $current[1] = $codePoint;
                continue;
            }

            if ($current !== null) {
                self::flush($current, $ranges, $singles);
            }

            $current = [$codePoint, $codePoint, $glyph];
        }

        if ($current !== null) {
            self::flush($current, $ranges, $singles);
        }

        return [$ranges, $singles];
    }

    /**
     * @param array{0: int, 1: int, 2: int} $range
     * @param list<array{0: int, 1: int, 2: int}> $ranges
     * @param list<array{0: int, 1: int}> $singles
     */
    private static function flush(array $range, array &$ranges, array &$singles): void
    {
        // A one-character range is a cidchar entry: same meaning, and it
        // says it in half the space, which over a font's worth of
        // scattered coverage is most of the entries.
        if ($range[0] === $range[1]) {
            $singles[] = [$range[0], $range[2]];

            return;
        }

        $ranges[] = $range;
    }

    /**
     * @param list<mixed> $entries
     * @param callable(mixed): string $format
     */
    private static function blocks(array $entries, string $keyword, callable $format): string
    {
        $out = '';

        foreach (array_chunk($entries, self::BLOCK) as $chunk) {
            $out .= count($chunk) . " begin$keyword\n"
                . implode("\n", array_map($format, $chunk))
                . "\nend$keyword\n";
        }

        return $out;
    }

    /** A code point as the hex of the UTF-16BE code units standing for it. */
    private static function codeHex(int $codePoint): string
    {
        return strtoupper(bin2hex(self::codeUnits($codePoint)));
    }

    private static function codeUnits(int $codePoint): string
    {
        if ($codePoint <= 0xFFFF) {
            return pack('n', $codePoint);
        }

        $offset = $codePoint - 0x10000;

        return pack('nn', 0xD800 + ($offset >> 10), 0xDC00 + ($offset & 0x3FF));
    }
}
