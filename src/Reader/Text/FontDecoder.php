<?php

declare(strict_types=1);

namespace MightyPDF\Reader\Text;

use MightyPDF\Assembler\Dictionary;
use MightyPDF\Assembler\Stream;
use MightyPDF\Assembler\Types\PdfArray;
use MightyPDF\Assembler\Types\PdfInteger;
use MightyPDF\Assembler\Types\PdfName;
use MightyPDF\Assembler\Types\PdfReal;
use MightyPDF\Assembler\Types\PdfValue;
use MightyPDF\Assembler\Types\WinAnsiEncoding;
use MightyPDF\Content\Font\StandardFont;
use MightyPDF\Content\Text\Utf8;
use MightyPDF\Editor\Form\CMapRanges;
use MightyPDF\Editor\PdfEditor;

/**
 * One font in a page's resources, reduced to the two questions text
 * extraction asks of it: what text does this code stand for, and how far
 * does it move the pen.
 *
 * This is the hard half of reading a PDF, and the reason "just get the
 * text out" is not a small job. A content stream contains *codes*, and
 * what a code means is entirely a property of the font it is shown in:
 * the byte 0x41 is "A" in one font, a bullet in the next, and half of a
 * two-byte character id in a third. Nothing in the stream says which.
 *
 * There are three sources for the answer, in the order they are trusted:
 *
 * 1. **`/ToUnicode`**, a CMap the writer supplied precisely so this
 *    question can be answered. When it is there it is definitive, because
 *    it is the writer stating what it meant.
 * 2. **`/Encoding`**, when the font is a simple one -- a base encoding,
 *    possibly with /Differences naming individual glyphs. Glyph names are
 *    then turned back into characters (see GlyphNames), which works
 *    because the names are conventional and fails where a producer
 *    invented its own.
 * 3. **The standard 14's own encoding**, for a font with neither, which
 *    is WinAnsi in practice.
 *
 * A font with none of these -- a subset embedded with invented glyph
 * names and no /ToUnicode -- genuinely cannot be read back, and that is a
 * property of the file rather than a shortcoming here. Such codes come
 * back as U+FFFD rather than being silently dropped, so a caller can tell
 * "this page has text I could not decode" from "this page has no text".
 */
final class FontDecoder
{
    /** The width used where a font says nothing at all (§9.2.4). */
    private const int DEFAULT_WIDTH = 500;

    /**
     * How many per-CID widths one font may declare, counted across every
     * range in its /W rather than per range.
     *
     * Which is the whole of the bound: a code is two bytes, so 65 536
     * distinct CIDs is every width a font can be asked about, and a file
     * listing more is listing the same ones again. It costs it nothing to
     * do so -- ten bytes per "0 65535 1" triple -- and each triple is
     * 65 536 assignments, so a megabyte of them is minutes of CPU spent
     * arriving back at the same array.
     */
    private const int MAX_WIDTH_ENTRIES = 65_536;

    private readonly bool $composite;

    private ?CMapRanges $toUnicode = null;

    /** @var array<int, string> code => the text it stands for */
    private array $encoding = [];

    /** @var array<int, int> code (or CID) => width in 1/1000 em */
    private array $widths = [];

    private int $defaultWidth = self::DEFAULT_WIDTH;

    public function __construct(
        private readonly PdfEditor $editor,
        private readonly Dictionary $font,
    ) {
        $subtype = $this->editor->resolve($font->get('Subtype'));
        $this->composite = $subtype instanceof PdfName && $subtype->value() === 'Type0';

        $this->readToUnicode();

        if ($this->composite) {
            $this->readCompositeWidths();
        } else {
            $this->readSimpleEncoding();
            $this->readSimpleWidths();
        }
    }

    /**
     * How many bytes one code takes.
     *
     * One for a simple font, two for a composite one. That is a
     * simplification: a composite font's /Encoding CMap defines codespace
     * ranges which may mix one- and two-byte codes. In practice all but a
     * vanishing few use Identity-H or a predefined CMap that is two bytes
     * throughout, and guessing wrong on the rest costs mojibake rather
     * than a crash.
     */
    public function codeLength(): int
    {
        return $this->composite ? 2 : 1;
    }

    /** The text one code stands for, or U+FFFD where it cannot be known. */
    public function textFor(int $code): string
    {
        if ($this->toUnicode !== null) {
            $value = $this->toUnicode->valueFor($this->codeBytes($code));

            if ($value !== null) {
                return self::codePointToUtf8($value);
            }
        }

        if (isset($this->encoding[$code])) {
            return $this->encoding[$code];
        }

        if ($this->composite) {
            return "\u{FFFD}";
        }

        // A simple font with no /ToUnicode and nothing in /Differences:
        // the base encoding, which for anything this side of 1995 is
        // WinAnsi in all but name.
        $text = WinAnsiEncoding::decode(chr($code));

        // A control character is not text. WinAnsi assigns nothing below
        // 0x20, so a code down there means the font was using its own
        // encoding and never said what it was -- the same undecodable
        // case as a subset with invented glyph names, and it has to be
        // reported the same way rather than putting a raw 0x01 in a
        // string a caller is about to search or print.
        return $text === '' || $code < 0x20 || $code === 0x7F ? "\u{FFFD}" : $text;
    }

    /** The advance for one code, in 1/1000 em. */
    public function widthFor(int $code): int
    {
        return $this->widths[$code] ?? $this->defaultWidth;
    }

    /** Whether this code is the single-byte space that /Tw applies to. */
    public function isWordSpace(int $code): bool
    {
        // §9.3.3: word spacing applies to byte 32 only, and only in a
        // single-byte encoding. Applying it to a two-byte code whose low
        // half happens to be 32 is a classic way to space CJK wrongly.
        return !$this->composite && $code === 32;
    }

    private function codeBytes(int $code): string
    {
        return $this->composite ? chr(($code >> 8) & 0xFF) . chr($code & 0xFF) : chr($code);
    }

    private function readToUnicode(): void
    {
        $stream = $this->editor->resolve($this->font->get('ToUnicode'));

        if (!$stream instanceof Stream || !$this->editor->store()->canDecode($stream)) {
            return;
        }

        $ranges = CMapRanges::toUnicode($this->editor->store()->decodedStream($stream));

        $this->toUnicode = $ranges->isEmpty() ? null : $ranges;
    }

    /**
     * Builds code => text from /Encoding: a base encoding, then whatever
     * /Differences renames on top of it.
     */
    private function readSimpleEncoding(): void
    {
        $encoding = $this->editor->resolve($this->font->get('Encoding'));

        if ($encoding instanceof PdfName) {
            // A named base encoding and nothing else. All three named
            // encodings agree with WinAnsi across ASCII, which is where
            // the overwhelming majority of text lives, so the default
            // path already handles them.
            return;
        }

        if (!$encoding instanceof Dictionary) {
            return;
        }

        $differences = $this->editor->resolve($encoding->get('Differences'));

        if (!$differences instanceof PdfArray) {
            return;
        }

        $code = 0;

        foreach ($differences->items() as $item) {
            $item = $this->editor->resolve($item);

            if ($item instanceof PdfInteger) {
                $code = $item->value();

                continue;
            }

            if ($item instanceof PdfReal) {
                $code = (int) $item->value();

                continue;
            }

            if ($item instanceof PdfName && $code >= 0 && $code <= 255) {
                $text = GlyphNames::toText($item->value());

                if ($text !== null) {
                    $this->encoding[$code] = $text;
                }

                ++$code;
            }
        }
    }

    private function readSimpleWidths(): void
    {
        $first = $this->editor->resolve($this->font->get('FirstChar'));
        $widths = $this->editor->resolve($this->font->get('Widths'));

        if ($widths instanceof PdfArray && $first instanceof PdfInteger) {
            foreach ($widths->items() as $index => $width) {
                $value = $this->number($width);

                if ($value !== null) {
                    $this->widths[$first->value() + $index] = (int) round($value);
                }
            }
        }

        $descriptor = $this->editor->resolveDictionary($this->font->get('FontDescriptor'));
        $missing = $this->number($descriptor?->get('MissingWidth'));

        if ($missing !== null) {
            $this->defaultWidth = (int) round($missing);
        }

        if ($this->widths !== []) {
            return;
        }

        // No /Widths at all, which is legal for the standard 14 and
        // means "look them up". This library ships those metrics for its
        // own writing, so it can answer rather than guess.
        $this->readStandardWidths();
    }

    private function readStandardWidths(): void
    {
        $base = $this->editor->resolve($this->font->get('BaseFont'));

        if (!$base instanceof PdfName) {
            return;
        }

        // Subset prefixes ("ABCDEF+Helvetica") and the style suffix are
        // both part of the name a font is looked up by.
        $name = preg_replace('/^[A-Z]{6}\+/', '', $base->value()) ?? $base->value();
        $standard = self::standardFontNamed($name);

        if ($standard === null) {
            return;
        }

        $metrics = $standard->metrics();

        for ($code = 0; $code <= 255; ++$code) {
            $this->widths[$code] = $metrics->widthOfCode($code);
        }
    }

    private static function standardFontNamed(string $name): ?StandardFont
    {
        foreach (StandardFont::cases() as $case) {
            if (strcasecmp($case->baseFontName(), $name) === 0) {
                return $case;
            }
        }

        return null;
    }

    /**
     * A composite font's widths live on its one descendant, as /W: a list
     * mixing "first last width" triples with "first [w w w ...]" runs.
     */
    private function readCompositeWidths(): void
    {
        $descendants = $this->editor->resolve($this->font->get('DescendantFonts'));
        $descendant = $descendants instanceof PdfArray
            ? $this->editor->resolveDictionary($descendants->items()[0] ?? null)
            : null;

        if ($descendant === null) {
            return;
        }

        // §9.7.4.3: /DW defaults to 1000, not to the 500 a simple font
        // falls back to.
        $this->defaultWidth = (int) round($this->number($descendant->get('DW')) ?? 1000.0);

        $w = $this->editor->resolve($descendant->get('W'));

        if (!$w instanceof PdfArray) {
            return;
        }

        $items = $w->items();
        $count = count($items);
        $budget = self::MAX_WIDTH_ENTRIES;

        for ($i = 0; $i < $count && $budget > 0;) {
            $first = $this->number($items[$i] ?? null);

            if ($first === null) {
                break;
            }

            $next = $this->editor->resolve($items[$i + 1] ?? null);

            if ($next instanceof PdfArray) {
                foreach ($next->items() as $offset => $width) {
                    $value = $this->number($width);

                    if ($value !== null) {
                        $this->widths[(int) $first + $offset] = (int) round($value);
                    }
                }

                $budget -= count($next->items());
                $i += 2;

                continue;
            }

            $last = $this->number($items[$i + 1] ?? null);
            $width = $this->number($items[$i + 2] ?? null);

            if ($last === null || $width === null) {
                break;
            }

            // Guarded twice, against two different things. Per range,
            // because a "first last" pair spanning the whole two-byte code
            // space would otherwise be expanded into 65 536 entries by a
            // file that is a few bytes long. And against the budget,
            // because nothing stops a file holding that pair a hundred
            // thousand times over -- see MAX_WIDTH_ENTRIES.
            $last = min((int) $last, (int) $first + 65_535, (int) $first + $budget - 1);

            for ($cid = (int) $first; $cid <= $last; ++$cid) {
                $this->widths[$cid] = (int) round($width);
            }

            $budget -= $last - (int) $first + 1;
            $i += 3;
        }
    }

    private function number(?PdfValue $value): ?float
    {
        $value = $this->editor->resolve($value);

        return match (true) {
            $value instanceof PdfInteger => (float) $value->value(),
            $value instanceof PdfReal => $value->value(),
            default => null,
        };
    }

    private static function codePointToUtf8(int $codePoint): string
    {
        // Utf8::fromCodePoint() rather than mb_chr(): this library
        // declares no mbstring dependency and is not about to acquire
        // one for a single conversion.
        return $codePoint >= 0 && $codePoint <= 0x10FFFF
            ? Utf8::fromCodePoint($codePoint)
            : "\u{FFFD}";
    }
}
