<?php

declare(strict_types=1);

namespace MightyPDF\Content\Font\TrueType;

/**
 * A parsed TrueType font file: its tables, the metrics a PDF font
 * descriptor has to state, and the two questions drawing text asks --
 * which glyph draws this character, and how wide is it.
 *
 * Scope, in this project's "explicitly unsupported rather than silently
 * wrong" spirit: TrueType outlines only, i.e. a file with a 'glyf' table.
 * OpenType fonts with PostScript outlines (an 'OTTO' file, glyphs in a
 * 'CFF ' table) are a different embedding path in PDF -- a different
 * descendant font type and a different subsetter, since CFF has its own
 * charstring format -- and are refused by name rather than half-read.
 * Font collections (.ttc) are refused for the same reason: the file holds
 * several fonts and nothing here would say which one was meant.
 *
 * Units: everything a font file states about geometry is in font design
 * units, of which there are unitsPerEm() to the em (1000 and 2048 are
 * both common). PDF's glyph space is fixed at 1/1000 em, so nothing here
 * converts -- callers scale by 1000 / unitsPerEm exactly once, where the
 * value enters the PDF (see EmbeddedFont).
 */
final class TrueTypeFile
{
    /** Version 1.0 outlines; 'true' is the same thing as written by older Apple tools. */
    private const int VERSION_1_0 = 0x00010000;
    private const string VERSION_TRUE = 'true';
    private const string VERSION_CFF = 'OTTO';
    private const string VERSION_COLLECTION = 'ttcf';

    private const int MAGIC = 0x5F0F3CF5;

    /** A glyph count over the format's own 16-bit limit means the file is not what it says. */
    private const int MAX_GLYPHS = 0xFFFF;

    private readonly SfntReader $reader;

    /** @var array<string, array{offset: int, length: int}> */
    private readonly array $directory;

    private readonly int $unitsPerEm;
    private readonly int $numGlyphs;
    private readonly int $indexToLocFormat;
    private readonly int $numberOfHMetrics;
    private readonly ?CmapTable $cmap;

    /** @var list<int>|null glyph id => offset into 'glyf', one longer than the glyph count */
    private ?array $loca = null;

    /** Kept rather than re-sliced per glyph: measuring a paragraph asks for one width per character. */
    private ?SfntReader $hmtx = null;

    private ?FontFileMetrics $metrics = null;

    /**
     * Tables are sliced out of the file on demand and kept.
     *
     * Not a micro-optimization: subsetting asks for one glyph at a time,
     * and 'glyf' is the whole outline data of the font -- hundreds of
     * kilobytes copied per glyph, if every call re-slices it.
     *
     * @var array<string, string>
     */
    private array $tables = [];

    private function __construct(string $bytes)
    {
        $this->reader = new SfntReader($bytes);
        $this->directory = $this->readDirectory();

        $head = new SfntReader($this->table('head') ?? throw new FontException('Font has no "head" table.'));

        if ($head->length() < 54 || $head->uint32(12) !== self::MAGIC) {
            throw new FontException('Font "head" table is corrupt.');
        }

        $this->unitsPerEm = $head->uint16(18);

        if ($this->unitsPerEm === 0) {
            throw new FontException('Font declares zero units per em, so no glyph has a size.');
        }

        $this->indexToLocFormat = $head->int16(50);

        if ($this->indexToLocFormat !== 0 && $this->indexToLocFormat !== 1) {
            throw new FontException('Font "head" table declares an unknown glyph-offset format.');
        }

        $maxp = new SfntReader($this->table('maxp') ?? throw new FontException('Font has no "maxp" table.'));
        $this->numGlyphs = $maxp->uint16(4);

        if ($this->numGlyphs === 0 || $this->numGlyphs > self::MAX_GLYPHS) {
            throw new FontException("Font declares an impossible glyph count ({$this->numGlyphs}).");
        }

        $hhea = new SfntReader($this->table('hhea') ?? throw new FontException('Font has no "hhea" table.'));
        $this->numberOfHMetrics = min($hhea->uint16(34), $this->numGlyphs);

        if ($this->numberOfHMetrics === 0) {
            throw new FontException('Font states no horizontal metrics, so no glyph has a width.');
        }

        $cmap = $this->table('cmap');
        $this->cmap = $cmap === null ? null : CmapTable::parse($cmap);
    }

    public static function fromBytes(string $bytes): self
    {
        return new self($bytes);
    }

    public static function fromFile(string $path): self
    {
        $bytes = @file_get_contents($path);

        if ($bytes === false) {
            throw new FontException("Font file could not be read: $path");
        }

        return new self($bytes);
    }

    public function unitsPerEm(): int
    {
        return $this->unitsPerEm;
    }

    /** The file exactly as it was read -- for embedding a font whole rather than subset. */
    public function bytes(): string
    {
        return $this->reader->slice(0, $this->reader->length());
    }

    public function numGlyphs(): int
    {
        return $this->numGlyphs;
    }

    public function indexToLocFormat(): int
    {
        return $this->indexToLocFormat;
    }

    /** The glyph drawing $codePoint, or null when this font has none. */
    public function glyphForCodePoint(int $codePoint): ?int
    {
        return $this->cmap?->glyphFor($codePoint);
    }

    public function hasCharacterMap(): bool
    {
        return $this->cmap !== null;
    }

    /**
     * $glyph's advance width in font design units.
     *
     * 'hmtx' stops early on purpose: a font whose last glyphs all have
     * the same advance (a monospaced font, or a CJK font's uniform-width
     * ideographs) stores that width once and lets every glyph past
     * numberOfHMetrics inherit it. Reading past the array rather than
     * repeating the last entry is not an edge case -- it is how the
     * format saves space in exactly the fonts with the most glyphs.
     */
    public function advanceWidth(int $glyph): int
    {
        $hmtx = $this->hmtx ??= new SfntReader(
            $this->table('hmtx') ?? throw new FontException('Font has no "hmtx" table.'),
        );
        $index = min(max($glyph, 0), $this->numberOfHMetrics - 1);
        $offset = $index * 4;

        return $hmtx->has($offset, 2) ? $hmtx->uint16($offset) : 0;
    }

    /**
     * $glyph's left side bearing in font design units -- the gap between
     * the pen position and the left edge of the outline.
     *
     * Unlike advance widths, the tail of 'hmtx' past numberOfHMetrics
     * does record a bearing per glyph: it is only the advance that the
     * shortened form shares.
     */
    public function leftSideBearing(int $glyph): int
    {
        $hmtx = $this->hmtx ??= new SfntReader(
            $this->table('hmtx') ?? throw new FontException('Font has no "hmtx" table.'),
        );

        $offset = $glyph < $this->numberOfHMetrics
            ? $glyph * 4 + 2
            : $this->numberOfHMetrics * 4 + ($glyph - $this->numberOfHMetrics) * 2;

        return $hmtx->has($offset, 2) ? $hmtx->int16($offset) : 0;
    }

    /**
     * $glyph's outline, exactly as stored in 'glyf'.
     *
     * An empty string is a real answer, not a missing one: a space has no
     * outline, and the format says so by giving it a zero-length entry
     * (its loca offset equals the next glyph's).
     */
    public function glyphData(int $glyph): string
    {
        if ($glyph < 0 || $glyph >= $this->numGlyphs) {
            return '';
        }

        $loca = $this->loca();
        $start = $loca[$glyph];
        $end = $loca[$glyph + 1];

        if ($end <= $start) {
            return '';
        }

        $glyf = $this->table('glyf') ?? throw new FontException('Font has no "glyf" table.');

        if ($end > strlen($glyf)) {
            throw new FontException("Font glyph $glyph runs past the end of its \"glyf\" table.");
        }

        return substr($glyf, $start, $end - $start);
    }

    /**
     * The glyph offsets from 'loca', normalized to byte offsets.
     *
     * The short format stores offsets halved, which fits a font under
     * 128KB of outlines into 16 bits per entry -- so a reader that
     * forgets to double them gets glyphs that are subtly the wrong bytes
     * rather than an error.
     *
     * @return list<int>
     */
    private function loca(): array
    {
        if ($this->loca !== null) {
            return $this->loca;
        }

        $loca = new SfntReader($this->table('loca') ?? throw new FontException('Font has no "loca" table.'));
        $isLong = $this->indexToLocFormat === 1;
        $entrySize = $isLong ? 4 : 2;
        $expected = ($this->numGlyphs + 1) * $entrySize;

        if ($loca->length() < $expected) {
            throw new FontException(sprintf(
                'Font "loca" table holds %d bytes, too few for %d glyphs.',
                $loca->length(),
                $this->numGlyphs,
            ));
        }

        $offsets = [];

        for ($glyph = 0; $glyph <= $this->numGlyphs; ++$glyph) {
            $offsets[] = $isLong
                ? $loca->uint32($glyph * 4)
                : $loca->uint16($glyph * 2) * 2;
        }

        return $this->loca = $offsets;
    }

    /** The raw bytes of one table, or null when the font has no such table. */
    public function table(string $tag): ?string
    {
        if (isset($this->tables[$tag])) {
            return $this->tables[$tag];
        }

        $entry = $this->directory[$tag] ?? null;

        if ($entry === null) {
            return null;
        }

        return $this->tables[$tag] = $this->reader->slice($entry['offset'], $entry['length']);
    }

    /** @return list<string> every table tag present, in the file's own order */
    public function tableTags(): array
    {
        return array_keys($this->directory);
    }

    /**
     * The name the font calls itself, as PDF's /BaseFont wants it: the
     * PostScript name from 'name' (id 6), with the characters PDF names
     * cannot hold stripped out.
     *
     * Falls back to the family name and then to a fixed string, because
     * this ends up in /BaseFont, which is required -- a font with a
     * damaged 'name' table still draws perfectly, and refusing to embed
     * it over a cosmetic string would be the wrong trade.
     */
    public function postScriptName(): string
    {
        $name = $this->nameRecord(6) ?? $this->nameRecord(1) ?? 'UnnamedFont';

        // /BaseFont is a name object: no whitespace, no delimiters. The
        // subset prefix added later uses '+', so that goes too.
        $clean = preg_replace('/[^A-Za-z0-9._-]/', '', $name) ?? '';

        return $clean === '' ? 'UnnamedFont' : $clean;
    }

    /** Read once and kept: laying out a paragraph asks for a width per word. */
    public function metrics(): FontFileMetrics
    {
        return $this->metrics ??= $this->readMetrics();
    }

    private function readMetrics(): FontFileMetrics
    {
        $head = new SfntReader($this->table('head') ?? throw new FontException('Font has no "head" table.'));
        $hhea = new SfntReader($this->table('hhea') ?? throw new FontException('Font has no "hhea" table.'));
        $os2 = $this->table('OS/2');
        $post = $this->table('post');

        $os2Reader = $os2 === null ? null : new SfntReader($os2);
        $postReader = $post === null ? null : new SfntReader($post);

        $macStyle = $head->uint16(44);
        $italicAngle = $postReader !== null && $postReader->has(4, 4) ? $postReader->fixed(4) : 0.0;

        // OS/2 gained sCapHeight at version 2; asking an older table for
        // it reads whatever follows the table instead.
        $capHeight = null;
        if ($os2Reader !== null && $os2Reader->uint16(0) >= 2 && $os2Reader->has(88, 2)) {
            $capHeight = $os2Reader->int16(88);
        }

        $weightClass = $os2Reader !== null && $os2Reader->has(4, 2) ? $os2Reader->uint16(4) : 400;

        return new FontFileMetrics(
            unitsPerEm: $this->unitsPerEm,
            xMin: $head->int16(36),
            yMin: $head->int16(38),
            xMax: $head->int16(40),
            yMax: $head->int16(42),
            ascent: $hhea->int16(4),
            descent: $hhea->int16(6),
            capHeight: $capHeight,
            italicAngle: $italicAngle,
            weightClass: $weightClass,
            isItalic: ($macStyle & 0x02) !== 0 || $italicAngle !== 0.0,
            isBold: ($macStyle & 0x01) !== 0 || $weightClass >= 600,
            isFixedPitch: $postReader !== null && $postReader->has(12, 4) && $postReader->uint32(12) !== 0,
            isSymbolic: !$this->hasCharacterMap(),
        );
    }

    /**
     * @return array<string, array{offset: int, length: int}>
     */
    private function readDirectory(): array
    {
        if ($this->reader->length() < 12) {
            throw new FontException('Font file is too short to be a font.');
        }

        $version = $this->reader->tag(0);

        if ($version === self::VERSION_CFF) {
            throw new FontException(
                'This is an OpenType/CFF font (PostScript outlines). Only TrueType outlines can be embedded here -- '
                . 'use the .ttf build of the font.',
            );
        }

        if ($version === self::VERSION_COLLECTION) {
            throw new FontException(
                'This is a TrueType collection (.ttc) holding several fonts. Extract the single font you want first.',
            );
        }

        if ($this->reader->uint32(0) !== self::VERSION_1_0 && $version !== self::VERSION_TRUE) {
            throw new FontException('This file is not a TrueType font.');
        }

        $tableCount = $this->reader->uint16(4);
        $directory = [];

        for ($i = 0; $i < $tableCount; ++$i) {
            $record = 12 + $i * 16;

            if (!$this->reader->has($record, 16)) {
                throw new FontException('Font table directory is truncated.');
            }

            $tag = $this->reader->tag($record);
            $offset = $this->reader->uint32($record + 8);
            $length = $this->reader->uint32($record + 12);

            // A table pointing outside the file is the shape a hostile or
            // truncated font takes; every later read assumes the
            // directory has already been checked.
            if (!$this->reader->has($offset, $length)) {
                throw new FontException("Font table \"$tag\" lies outside the file.");
            }

            $directory[$tag] = ['offset' => $offset, 'length' => $length];
        }

        if (!isset($directory['glyf'])) {
            throw new FontException(
                'Font has no "glyf" table, so its glyphs are not TrueType outlines. Only TrueType outlines can be '
                . 'embedded here.',
            );
        }

        return $directory;
    }

    /**
     * One string out of the 'name' table, decoded to UTF-8.
     *
     * Windows name records are UTF-16BE and Macintosh ones are (for
     * practical purposes) Latin-1; taking the Windows record first is
     * both the more common case and the one that survives non-ASCII
     * family names.
     */
    private function nameRecord(int $nameId): ?string
    {
        $name = $this->table('name');

        if ($name === null) {
            return null;
        }

        $reader = new SfntReader($name);

        if (!$reader->has(0, 6)) {
            return null;
        }

        $count = $reader->uint16(2);
        $storage = $reader->uint16(4);
        $best = null;

        for ($i = 0; $i < $count; ++$i) {
            $record = 6 + $i * 12;

            if (!$reader->has($record, 12) || $reader->uint16($record + 6) !== $nameId) {
                continue;
            }

            $platform = $reader->uint16($record);
            $length = $reader->uint16($record + 8);
            $offset = $storage + $reader->uint16($record + 10);

            if (!$reader->has($offset, $length)) {
                continue;
            }

            $value = $reader->slice($offset, $length);

            if ($platform === 3) {
                $utf8 = @iconv('UTF-16BE', 'UTF-8', $value);

                return $utf8 === false ? null : $utf8;
            }

            $best ??= $value;
        }

        return $best;
    }
}
