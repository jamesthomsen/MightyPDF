<?php

declare(strict_types=1);

namespace MightyPDF\Content\Font;

use MightyPDF\Assembler\DocumentContext;
use MightyPDF\Content\Font\TrueType\FontException;
use MightyPDF\Content\Font\TrueType\TrueTypeFile;
use MightyPDF\Content\Text\Utf8;

/**
 * A TrueType font file to be embedded in the document and drawn with --
 * the way out of the standard 14 fonts and the WinAnsi repertoire they
 * are limited to. Text drawn through one of these can hold any character
 * the font itself contains, Greek and Cyrillic and CJK included.
 *
 * ```php
 * $font = EmbeddedFont::load('/path/to/Inter-Regular.ttf');
 * $content->drawText($font, 12.0, 72, 700, 'Λορεμ ιπσθμ — Ünicode');
 * ```
 *
 * By default only the glyphs the document actually draws are embedded
 * (see TrueTypeSubset for why that matters and what it costs). Pass
 * subset: false to embed the file whole, which is what a document whose
 * text is not settled when it is written needs -- a form field, where
 * the reader draws whatever someone types. A whole font is also
 * addressed differently: by character rather than by glyph number, since
 * nothing can be assigned to text that does not exist yet. See
 * Type0Font and UnicodeCMap.
 *
 * An EmbeddedFont is a value, not document state: loading the same file
 * twice gives two objects that behave identically and share one font
 * object in any document either is drawn into (see cacheKey()). The
 * per-document part -- which glyphs were used, and what they were
 * renumbered to -- lives in Type0Font, where it belongs.
 *
 * Scope: TrueType (.ttf) or OpenType/CFF (.otf) outlines, per
 * TrueTypeFile -- but a CFF font can only be embedded whole, since
 * subsetting one means taking its charstrings apart, and a CID-keyed CFF
 * is refused outright because its glyphs are not addressed by index at
 * all. A character the font has no glyph for is refused rather than
 * drawn as an empty box; see missingCharacters().
 */
final class EmbeddedFont implements Font
{
    private function __construct(
        private readonly TrueTypeFile $file,
        private readonly string $cacheKey,
        private readonly bool $subset,
    ) {
        if (!$file->hasCffOutlines()) {
            return;
        }

        if ($subset) {
            throw new FontException(
                'This is an OpenType/CFF font, whose PostScript outlines this library can embed but not subset. '
                . 'Load it with EmbeddedFont::load($path, subset: false) to embed the file whole.',
            );
        }

        if ($file->hasCidKeyedCff()) {
            throw new FontException(
                'This OpenType/CFF font is CID-keyed: its glyphs are addressed through a character collection of '
                . 'its own rather than by glyph index, which is not something this library can map text onto. '
                . 'Use a TrueType (.ttf) build of the font.',
            );
        }
    }

    public static function load(string $path, bool $subset = true): self
    {
        $bytes = @file_get_contents($path);

        if ($bytes === false) {
            throw new FontException("Font file could not be read: $path");
        }

        return self::fromBytes($bytes, $subset);
    }

    /**
     * Keyed by the font's own bytes rather than by where they came from:
     * the same font reached by two paths (a symlink, a relative and an
     * absolute path) is one font, and embedding it twice would be a
     * silent duplication of the largest thing in the file. Same
     * reasoning as Document's image cache.
     */
    public static function fromBytes(string $bytes, bool $subset = true): self
    {
        return new self(
            TrueTypeFile::fromBytes($bytes),
            'ttf:' . sha1($bytes) . ($subset ? ':subset' : ':full'),
            $subset,
        );
    }

    public function cacheKey(): string
    {
        return $this->cacheKey;
    }

    public function file(): TrueTypeFile
    {
        return $this->file;
    }

    public function isSubset(): bool
    {
        return $this->subset;
    }

    /** The name the font gives itself, e.g. "DejaVuSans". */
    public function name(): string
    {
        return $this->file->postScriptName();
    }

    public function widthOfPt(string $utf8Text, float $sizePt): float
    {
        $metrics = $this->file->metrics();
        $total = 0;

        foreach (Utf8::codePoints($utf8Text) as $codePoint) {
            $glyph = $this->file->glyphForCodePoint($codePoint);

            // Measuring must not throw the way drawing does: a caller
            // laying text out asks how wide it is before it asks for it
            // to be drawn, and a missing character is worth exactly one
            // error, from the place that can name what to do about it.
            $total += $metrics->toGlyphSpace($this->file->advanceWidth($glyph ?? 0));
        }

        return $total / 1000.0 * $sizePt;
    }

    public function ascentPt(float $sizePt): float
    {
        $metrics = $this->file->metrics();

        return $metrics->toGlyphSpace($metrics->ascent) / 1000.0 * $sizePt;
    }

    /**
     * hhea records the descent as a negative number, which this reports
     * as the distance it stands for -- see Font::descentPt(). A font
     * whose hhea is positive there (a handful of older files are) would
     * otherwise report a descent that lifts text instead of dropping it.
     */
    public function descentPt(float $sizePt): float
    {
        $metrics = $this->file->metrics();

        return abs($metrics->toGlyphSpace($metrics->descent)) / 1000.0 * $sizePt;
    }

    public function capHeightPt(float $sizePt): float
    {
        return $this->file->metrics()->capHeightInGlyphSpace() / 1000.0 * $sizePt;
    }

    /** Whether every character of $utf8Text has a glyph in this font. */
    public function supports(string $utf8Text): bool
    {
        return $this->missingCharacters($utf8Text) === [];
    }

    /**
     * The characters of $utf8Text this font has no glyph for, without
     * duplicates and in the order they appear.
     *
     * Worth calling before drawing text from an untrusted or unknown
     * source: drawing refuses on the first missing character rather than
     * drawing an empty box for it, and knowing all of them at once is
     * the difference between choosing a different font and finding out
     * one character at a time.
     *
     * @return list<string>
     */
    public function missingCharacters(string $utf8Text): array
    {
        $missing = [];

        foreach (Utf8::codePoints($utf8Text) as $codePoint) {
            if ($this->file->glyphForCodePoint($codePoint) === null) {
                $missing[$codePoint] = Utf8::fromCodePoint($codePoint);
            }
        }

        return array_values($missing);
    }

    public function writerFor(DocumentContext $document): FontWriter
    {
        $cached = $document->cachedFont($this->cacheKey);

        if ($cached instanceof Type0Font) {
            return $cached;
        }

        $font = Type0Font::create($document, $this->file, $this->subset);
        $document->cacheFont($this->cacheKey, $font);

        return $font;
    }
}
