<?php

declare(strict_types=1);

namespace MightyPDF\Editor\Form;

use MightyPDF\Assembler\Dictionary;
use MightyPDF\Assembler\Stream;
use MightyPDF\Assembler\Types\PdfArray;
use MightyPDF\Assembler\Types\PdfInteger;
use MightyPDF\Assembler\Types\PdfReal;
use MightyPDF\Content\Text\Utf8;
use MightyPDF\Editor\PdfEditor;

/**
 * A form font that is composite: /Type0, with codes of its own devising
 * and a width per character id.
 *
 * Reading one back is three lookups deep, and each is a different
 * question:
 *
 *   text  -> code   the /ToUnicode CMap, read backwards. It is the only
 *                   thing in the file that says what a code means, and
 *                   reading it backwards is exactly what a reader does
 *                   to lay out a typed value -- so an appearance drawn
 *                   this way agrees with the one the reader would draw.
 *   code  -> cid    the /Encoding CMap, or the identity where the
 *                   encoding is Identity-H and the two are the same
 *                   number by definition.
 *   cid   -> width  the descendant font's /W, falling back to its /DW.
 *
 * A field pointed at one of these is not exotic: it is what
 * PageBuilder::addTextField() produces when given an embedded font, so
 * a form this library wrote is the first thing that arrives here.
 */
final class CompositeFieldFont implements FieldFont
{
    /** PDF's default width for a character id the /W array does not mention. */
    private const float DEFAULT_WIDTH = 1000.0;

    /**
     * The largest character id there is: a CID is a 16-bit number
     * (ISO 32000-2 §9.7.4), so a /W entry outside this range describes a
     * character that cannot be addressed and is dropped.
     *
     * That is a bound as much as a rule. The "cFirst cLast w" form gives
     * one width to a whole run in a few bytes, so a file full of disjoint
     * runs asks for hundreds of millions of entries -- ten bytes of a
     * hostile document bought two megabytes of heap, and a nine-kilobyte
     * one exhausted a gigabyte before anything had been drawn. Clamping
     * to the ids that can exist costs a real font nothing.
     */
    private const int MAX_CID = 65_535;

    /**
     * @param array<int, float> $widths character id => width in glyph space
     */
    private function __construct(
        private readonly CMapRanges $toUnicode,
        private readonly ?CMapRanges $encoding,
        private readonly array $widths,
        private readonly float $defaultWidth,
    ) {
    }

    /**
     * Reads $font, or returns null where it says too little to draw
     * with -- no /ToUnicode, and there is no way from a value to the
     * codes that show it.
     */
    public static function read(PdfEditor $editor, Dictionary $font): ?self
    {
        $toUnicode = $editor->resolve($font->get('ToUnicode'));

        if (!$toUnicode instanceof Stream) {
            return null;
        }

        $ranges = CMapRanges::toUnicode($editor->store()->decodedStream($toUnicode));

        if ($ranges->isEmpty()) {
            return null;
        }

        $descendant = $editor->resolve($font->get('DescendantFonts'));
        $descendant = $descendant instanceof PdfArray
            ? $editor->resolveDictionary($descendant->items()[0] ?? null)
            : null;

        return new self(
            $ranges,
            self::encodingOf($editor, $font),
            $descendant === null ? [] : self::widthsOf($editor, $descendant),
            self::defaultWidthOf($editor, $descendant),
        );
    }

    public function widthOfPt(string $utf8Text, float $sizePt): float
    {
        $total = 0.0;

        foreach (Utf8::codePoints($utf8Text) as $codePoint) {
            $total += $this->widthOfCodePoint($codePoint);
        }

        return $total / 1000.0 * $sizePt;
    }

    public function show(string $utf8Text): string
    {
        $bytes = '';

        foreach (Utf8::codePoints($utf8Text) as $codePoint) {
            $bytes .= $this->toUnicode->codeFor($codePoint) ?? '';
        }

        // Hex, always: a composite font's codes are arbitrary bytes, and
        // half of them would need escaping as a literal string.
        return '<' . bin2hex($bytes) . '>';
    }

    public function canShow(string $utf8Text): bool
    {
        foreach (Utf8::codePoints($utf8Text) as $codePoint) {
            if ($this->toUnicode->codeFor($codePoint) === null) {
                return false;
            }
        }

        return true;
    }

    public function characters(string $utf8Text): array
    {
        return Utf8::characters($utf8Text);
    }

    private function widthOfCodePoint(int $codePoint): float
    {
        $code = $this->toUnicode->codeFor($codePoint);

        if ($code === null) {
            return $this->defaultWidth;
        }

        // Identity-H is the case with no CMap to consult: the code *is*
        // the character id, which is what "identity" names.
        $cid = $this->encoding?->valueFor($code) ?? self::number($code);

        return $this->widths[$cid] ?? $this->defaultWidth;
    }

    private static function encodingOf(PdfEditor $editor, Dictionary $font): ?CMapRanges
    {
        $encoding = $editor->resolve($font->get('Encoding'));

        if ($encoding instanceof Stream) {
            $ranges = CMapRanges::encoding($editor->store()->decodedStream($encoding));

            return $ranges->isEmpty() ? null : $ranges;
        }

        // A named encoding that is not Identity is one of the predefined
        // CJK CMaps, which are not in the file to be read. Treating it as
        // the identity is wrong, but only for the widths -- and a wrong
        // width misplaces text a reader will redraw anyway, where a
        // wrong *code* would draw the wrong characters.
        return null;
    }

    /** @return array<int, float> */
    private static function widthsOf(PdfEditor $editor, Dictionary $descendant): array
    {
        $w = $editor->resolve($descendant->get('W'));

        if (!$w instanceof PdfArray) {
            return [];
        }

        $items = array_map($editor->resolve(...), $w->items());
        $widths = [];

        for ($i = 0, $count = count($items); $i < $count;) {
            $first = self::asNumber($items[$i] ?? null);
            $second = $items[$i + 1] ?? null;

            if ($first === null || $second === null) {
                break;
            }

            // Two forms: "c [w w w]" lists a width each from c onwards,
            // and "cFirst cLast w" gives one width to a whole run.
            if ($second instanceof PdfArray) {
                foreach ($second->items() as $offset => $width) {
                    $width = self::asNumber($editor->resolve($width));
                    $cid = (int) $first + $offset;

                    if ($width !== null && $cid >= 0 && $cid <= self::MAX_CID) {
                        $widths[$cid] = $width;
                    }
                }

                $i += 2;

                continue;
            }

            $last = self::asNumber($second);
            $width = self::asNumber($items[$i + 2] ?? null);

            if ($last === null || $width === null) {
                break;
            }

            // Clamped at both ends before the loop rather than tested
            // inside it: a run written as "-1000000000 4000000000 500"
            // would otherwise spend billions of iterations on ids that
            // are discarded one at a time.
            $end = min((int) $last, self::MAX_CID);

            for ($cid = max((int) $first, 0); $cid <= $end; ++$cid) {
                $widths[$cid] = $width;
            }

            $i += 3;
        }

        return $widths;
    }

    private static function defaultWidthOf(PdfEditor $editor, ?Dictionary $descendant): float
    {
        $dw = $descendant === null ? null : self::asNumber($editor->resolve($descendant->get('DW')));

        return $dw ?? self::DEFAULT_WIDTH;
    }

    private static function asNumber(mixed $value): ?float
    {
        return match (true) {
            $value instanceof PdfInteger => (float) $value->value(),
            $value instanceof PdfReal => $value->value(),
            default => null,
        };
    }

    private static function number(string $bytes): int
    {
        $number = 0;

        for ($i = 0, $length = strlen($bytes); $i < $length; ++$i) {
            $number = ($number << 8) | ord($bytes[$i]);
        }

        return $number;
    }
}
