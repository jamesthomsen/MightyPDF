<?php

declare(strict_types=1);

namespace MightyPDF\Content\Font\TrueType;

use MightyPDF\Exception\LogicException;

/**
 * Builds a new, smaller TrueType font containing only the glyphs a
 * document actually draws.
 *
 * Why bother: a text font is 200KB-800KB and a CJK one runs to megabytes,
 * nearly all of it glyphs nobody used. A page with a line of text in a
 * custom font should not cost half a megabyte -- and embedding the whole
 * file also hands out a redistributable copy of it, which many font
 * licenses permit only for the subset a document needs.
 *
 * The glyphs are *renumbered*, not just filtered: the caller hands over
 * original glyph ids in the order it wants them, and they become glyphs
 * 1, 2, 3... of the result, with 0 reserved for .notdef as the format
 * requires. That is what lets the PDF side use /CIDToGIDMap /Identity --
 * the character ids written into the content stream are the glyph
 * numbers of this font, with no mapping table in between (see
 * EmbeddedFont).
 *
 * Two things make this more than a copy:
 *
 * - Composite glyphs (an "e-acute" drawn as an "e" plus an accent) refer
 *   to other glyphs by number. Those components have to come along, and
 *   their numbers inside the glyph's own bytes have to be rewritten to
 *   the new numbering, recursively -- a component may itself be a
 *   composite.
 * - Hinting lives in three tables ('cvt ', 'fpgm', 'prep') that glyph
 *   instructions refer to by index, so they are carried over whole. Drop
 *   them and hinted glyphs are distorted at small sizes rather than
 *   merely unhinted.
 */
final class TrueTypeSubset
{
    /** Tables a CIDFontType2's embedded program needs (ISO 32000-2 §9.9.2), plus hinting. */
    private const array TABLES_TO_COPY = ['cvt ', 'fpgm', 'prep', 'gasp'];

    /** Composite glyph flags (the ones that change how long a component record is). */
    private const int ARG_1_AND_2_ARE_WORDS = 0x0001;
    private const int WE_HAVE_A_SCALE = 0x0008;
    private const int MORE_COMPONENTS = 0x0020;
    private const int WE_HAVE_AN_X_AND_Y_SCALE = 0x0040;
    private const int WE_HAVE_A_TWO_BY_TWO = 0x0080;

    /**
     * Guards the composite closure below. A glyph referring to itself,
     * directly or through a cycle, is malformed -- but "malformed" is
     * what a font built to attack a PDF generator looks like, and an
     * unbounded recursion here would be its payload.
     */
    private const int MAX_COMPOSITE_DEPTH = 8;

    /** @var list<int> original glyph id, indexed by its id in the subset */
    private array $glyphs = [0];

    /** @var array<int, int> original glyph id => subset glyph id */
    private array $subsetIds = [0 => 0];

    private function __construct(private readonly TrueTypeFile $font)
    {
    }

    /**
     * The subset font program, as sfnt bytes.
     *
     * $originalGlyphIds is taken in order and defines the numbering of
     * the result: the first becomes glyph 1, the second glyph 2, and so
     * on. Glyph 0 is always .notdef and is always present. Components
     * pulled in for composite glyphs are appended after everything the
     * caller asked for, so a caller that assigned its own character ids
     * from the same list keeps them.
     *
     * @param list<int> $originalGlyphIds
     */
    public static function build(TrueTypeFile $font, array $originalGlyphIds): string
    {
        $subset = new self($font);

        foreach ($originalGlyphIds as $glyph) {
            $subset->add($glyph);
        }

        $subset->closeOverComponents();

        return $subset->assemble();
    }

    private function add(int $glyph): int
    {
        if (isset($this->subsetIds[$glyph])) {
            return $this->subsetIds[$glyph];
        }

        $this->glyphs[] = $glyph;

        return $this->subsetIds[$glyph] = count($this->glyphs) - 1;
    }

    /**
     * Pulls in every glyph referred to by a composite glyph already in
     * the subset -- and every glyph those refer to in turn.
     *
     * Walks the list by index rather than with foreach because it grows
     * as it goes: adding a component appends to the very array being
     * walked, and that new entry has to be examined too.
     */
    private function closeOverComponents(): void
    {
        for ($index = 0; $index < count($this->glyphs); ++$index) {
            foreach ($this->componentsOf($this->glyphs[$index], 0) as $component) {
                $this->add($component);
            }
        }
    }

    /**
     * The glyph ids a composite glyph is built from, one level deep.
     * Simple glyphs have none.
     *
     * @return list<int>
     */
    private function componentsOf(int $glyph, int $depth): array
    {
        if ($depth >= self::MAX_COMPOSITE_DEPTH) {
            throw new FontException("Font glyph $glyph nests composite glyphs too deeply to be read.");
        }

        $data = $this->font->glyphData($glyph);

        if (strlen($data) < 10) {
            return [];
        }

        $reader = new SfntReader($data);

        // A negative contour count is what marks a glyph as composite;
        // it is not a count of anything.
        if ($reader->int16(0) >= 0) {
            return [];
        }

        $components = [];
        $offset = 10;

        do {
            if (!$reader->has($offset, 4)) {
                throw new FontException("Font glyph $glyph has a truncated composite description.");
            }

            $flags = $reader->uint16($offset);
            $components[] = $reader->uint16($offset + 2);
            $offset += self::componentRecordLength($flags);
        } while (($flags & self::MORE_COMPONENTS) !== 0);

        return $components;
    }

    /**
     * How far past a component record the next one starts: two bytes of
     * flags, two of glyph number, then placement arguments that are
     * bytes or words, then an optional 2x2 transform stored as one,
     * two or four values. Nothing in the record says its own length --
     * this is the only way to find the next component.
     */
    private static function componentRecordLength(int $flags): int
    {
        return 4 + (($flags & self::ARG_1_AND_2_ARE_WORDS) !== 0 ? 4 : 2) + match (true) {
            ($flags & self::WE_HAVE_A_TWO_BY_TWO) !== 0 => 8,
            ($flags & self::WE_HAVE_AN_X_AND_Y_SCALE) !== 0 => 4,
            ($flags & self::WE_HAVE_A_SCALE) !== 0 => 2,
            default => 0,
        };
    }

    /**
     * A composite glyph's bytes with every component glyph number
     * rewritten to its number in the subset.
     *
     * The rewrite is in place, over a copy: component records are
     * variable length and interleaved with transform data, so the only
     * way to reach the next number is to walk past the previous record,
     * exactly as componentsOf() does. Reading stays on the original
     * bytes while writing goes to the copy -- safe because a glyph
     * number is replaced by a glyph number, so nothing moves.
     */
    private function renumberComponents(int $glyph, string $data): string
    {
        $reader = new SfntReader($data);

        if (strlen($data) < 10 || $reader->int16(0) >= 0) {
            return $data;
        }

        $rewritten = $data;
        $offset = 10;

        do {
            $flags = $reader->uint16($offset);
            $component = $reader->uint16($offset + 2);

            $subsetId = $this->subsetIds[$component]
                ?? throw new LogicException("Composite glyph $glyph refers to glyph $component, which the closure missed.");

            $rewritten = substr_replace($rewritten, pack('n', $subsetId), $offset + 2, 2);
            $offset += self::componentRecordLength($flags);
        } while (($flags & self::MORE_COMPONENTS) !== 0);

        return $rewritten;
    }

    private function assemble(): string
    {
        [$glyf, $loca] = $this->buildGlyfAndLoca();

        $tables = [
            'head' => $this->buildHead(),
            'hhea' => $this->buildHhea(),
            'maxp' => $this->buildMaxp(),
            'hmtx' => $this->buildHmtx(),
            'loca' => $loca,
            'glyf' => $glyf,
        ];

        foreach (self::TABLES_TO_COPY as $tag) {
            $table = $this->font->table($tag);

            if ($table !== null) {
                $tables[$tag] = $table;
            }
        }

        return self::serialize($tables);
    }

    /**
     * @return array{string, string} the new 'glyf' table and the 'loca'
     *         offsets into it
     */
    private function buildGlyfAndLoca(): array
    {
        $glyf = '';
        $offsets = [0];

        foreach ($this->glyphs as $glyph) {
            $data = $this->renumberComponents($glyph, $this->font->glyphData($glyph));

            // Every glyph must start on a 4-byte boundary for the long
            // 'loca' format to be able to describe it at all.
            $glyf .= $data . str_repeat("\x00", (4 - strlen($data) % 4) % 4);
            $offsets[] = strlen($glyf);
        }

        // Always the long format: the short one stores halved offsets and
        // so cannot describe a table over 128KB, and a subset is not
        // reliably under that (one CJK page is not). Costing two bytes
        // per glyph to remove a size limit is the right way round.
        $loca = '';
        foreach ($offsets as $offset) {
            $loca .= pack('N', $offset);
        }

        return [$glyf, $loca];
    }

    private function buildHead(): string
    {
        $head = $this->font->table('head') ?? throw new FontException('Font has no "head" table.');

        // checkSumAdjustment (offset 8) is a checksum of the whole file,
        // so it cannot be right until the file exists -- serialize()
        // fills it in. indexToLocFormat (offset 50) becomes long; see
        // buildGlyfAndLoca().
        $head = substr_replace($head, pack('N', 0), 8, 4);

        return substr_replace($head, pack('n', 1), 50, 2);
    }

    private function buildHhea(): string
    {
        $hhea = $this->font->table('hhea') ?? throw new FontException('Font has no "hhea" table.');

        // Every glyph in the subset gets its own advance width below, so
        // the "the rest share the last one" tail is empty.
        return substr_replace($hhea, pack('n', count($this->glyphs)), 34, 2);
    }

    private function buildMaxp(): string
    {
        $maxp = $this->font->table('maxp') ?? throw new FontException('Font has no "maxp" table.');

        return substr_replace($maxp, pack('n', count($this->glyphs)), 4, 2);
    }

    private function buildHmtx(): string
    {
        $hmtx = '';

        foreach ($this->glyphs as $glyph) {
            // The left side bearing is carried over, not zeroed: the
            // hinting instructions kept above use it (it is one of the
            // phantom points a hinted glyph is moved relative to), so
            // dropping it distorts exactly the small sizes hinting is
            // there to fix.
            $hmtx .= pack('nn', $this->font->advanceWidth($glyph), $this->font->leftSideBearing($glyph) & 0xFFFF);
        }

        return $hmtx;
    }

    /**
     * Writes the tables out as an sfnt file: the offset table, the table
     * directory (sorted by tag, as the format requires), then the tables
     * themselves, each padded to a 4-byte boundary.
     *
     * @param array<string, string> $tables
     */
    private static function serialize(array $tables): string
    {
        ksort($tables);

        $count = count($tables);

        // searchRange and friends are a binary-search hint. They are
        // derived, not chosen: the largest power of two not exceeding
        // the table count, times 16.
        $entrySelector = (int) floor(log($count, 2));
        $searchRange = 2 ** $entrySelector * 16;

        $out = pack('Nnnnn', 0x00010000, $count, $searchRange, $entrySelector, $count * 16 - $searchRange);

        $offset = 12 + $count * 16;
        $body = '';

        foreach ($tables as $tag => $data) {
            $padded = $data . str_repeat("\x00", (4 - strlen($data) % 4) % 4);

            // Note the length recorded is the real one, not the padded
            // one -- but the checksum is over the padded bytes.
            $out .= $tag . pack('NNN', self::checksum($padded), $offset, strlen($data));

            $body .= $padded;
            $offset += strlen($padded);
        }

        $file = $out . $body;

        return self::withHeadChecksum($file, $tables);
    }

    /**
     * Fills in head's checkSumAdjustment, which is defined as a magic
     * constant minus the checksum of the entire finished file.
     *
     * Readers do not generally verify it, but tools that inspect fonts
     * do, and a font that reports itself as damaged is a support
     * question later.
     *
     * @param array<string, string> $tables
     */
    private static function withHeadChecksum(string $file, array $tables): string
    {
        $offset = 12 + count($tables) * 16;

        foreach ($tables as $tag => $data) {
            if ($tag === 'head') {
                $adjustment = (0xB1B0AFBA - self::checksum($file)) & 0xFFFFFFFF;

                return substr_replace($file, pack('N', $adjustment), $offset + 8, 4);
            }

            $offset += strlen($data) + (4 - strlen($data) % 4) % 4;
        }

        return $file;
    }

    /** The sfnt checksum: the sum of the data read as 32-bit words, truncated to 32 bits. */
    private static function checksum(string $data): int
    {
        $data .= str_repeat("\x00", (4 - strlen($data) % 4) % 4);
        $sum = 0;

        foreach (unpack('N*', $data) as $word) {
            $sum = ($sum + $word) & 0xFFFFFFFF;
        }

        return $sum;
    }
}
