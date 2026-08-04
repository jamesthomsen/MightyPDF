<?php

declare(strict_types=1);

namespace MightyPDF\Editor\Form;

/**
 * A CMap read back out of a document, reduced to the two lookups filling
 * a form field needs.
 *
 * CMaps come in two flavours and this reads both, because they are the
 * same syntax answering different questions:
 *
 * - An /Encoding CMap maps the codes in a content stream to character
 *   ids -- which is what a width comes from (`cidFor()`).
 * - A /ToUnicode CMap maps those same codes to text. Filling a field
 *   needs it backwards: the value is text, and what has to be written is
 *   codes (`codeFor()`). That is exactly how a reader lays out a typed
 *   value, and doing anything else would draw an appearance that
 *   disagrees with the one the reader would have drawn.
 *
 * Ranges are kept as ranges rather than expanded. A single entry may
 * cover the whole of a two-byte code space, and expanding one costs
 * megabytes to answer questions about a field holding a dozen
 * characters.
 */
final class CMapRanges
{
    /** A CMap with more entries than this is not one a real document wrote. */
    private const int MAX_ENTRIES = 20_000;

    /**
     * @param list<array{code: string, low: int, high: int, value: int}> $ranges
     *        $code is the low end as it is written -- its length is the
     *        code's width in bytes, which a CMap may vary
     */
    private function __construct(private readonly array $ranges)
    {
    }

    public static function empty(): self
    {
        return new self([]);
    }

    /**
     * Reads the cid mappings of an /Encoding CMap.
     *
     * The destinations are plain integers here ("<0041> <005A> 36"),
     * unlike a /ToUnicode CMap's, which are hex strings.
     */
    public static function encoding(string $cmap): self
    {
        return new self(array_merge(
            self::rangesIn($cmap, 'cidrange', decimal: true),
            self::charsIn($cmap, 'cidchar', decimal: true),
        ));
    }

    public static function toUnicode(string $cmap): self
    {
        return new self(array_merge(
            self::rangesIn($cmap, 'bfrange', decimal: false),
            self::charsIn($cmap, 'bfchar', decimal: false),
        ));
    }

    public function isEmpty(): bool
    {
        return $this->ranges === [];
    }

    /** The value a code maps to -- a character id, or a code point. */
    public function valueFor(string $code): ?int
    {
        $number = self::number($code);

        foreach ($this->ranges as $range) {
            if (strlen($range['code']) === strlen($code) && $number >= $range['low'] && $number <= $range['high']) {
                return $range['value'] + ($number - $range['low']);
            }
        }

        return null;
    }

    /**
     * The code that maps to $value, as the bytes to write.
     *
     * The first range that covers it wins, which matches how a reader
     * resolves the same ambiguity: a CMap mapping two codes to one
     * character is unusual but legal, and either answer draws the same
     * glyph.
     */
    public function codeFor(int $value): ?string
    {
        foreach ($this->ranges as $range) {
            $span = $range['high'] - $range['low'];

            if ($value < $range['value'] || $value > $range['value'] + $span) {
                continue;
            }

            return self::bytes(
                $range['low'] + ($value - $range['value']),
                strlen($range['code']),
            );
        }

        return null;
    }

    /**
     * @return list<array{code: string, low: int, high: int, value: int}>
     */
    private static function rangesIn(string $cmap, string $keyword, bool $decimal): array
    {
        $destination = $decimal ? '(\d+)' : '<([0-9A-Fa-f]+)>';
        $pattern = '/<([0-9A-Fa-f]+)>\s*<([0-9A-Fa-f]+)>\s*' . $destination . '/';
        $ranges = [];

        foreach (self::blocks($cmap, $keyword) as $block) {
            foreach (self::entriesIn($block, $pattern) as $entry) {
                if (count($ranges) >= self::MAX_ENTRIES) {
                    return $ranges;
                }

                $value = self::destination($entry[3], $decimal);

                if ($value === null) {
                    continue;
                }

                $low = self::hexBytes($entry[1]);

                $ranges[] = [
                    'code' => $low,
                    'low' => self::number($low),
                    'high' => self::number(self::hexBytes($entry[2])),
                    'value' => $value,
                ];
            }
        }

        return $ranges;
    }

    /**
     * @return list<array{code: string, low: int, high: int, value: int}>
     */
    private static function charsIn(string $cmap, string $keyword, bool $decimal): array
    {
        $destination = $decimal ? '(\d+)' : '<([0-9A-Fa-f]+)>';
        $pattern = '/<([0-9A-Fa-f]+)>\s*' . $destination . '/';
        $chars = [];

        foreach (self::blocks($cmap, $keyword) as $block) {
            foreach (self::entriesIn($block, $pattern) as $entry) {
                if (count($chars) >= self::MAX_ENTRIES) {
                    return $chars;
                }

                $value = self::destination($entry[2], $decimal);

                if ($value === null) {
                    continue;
                }

                $code = self::hexBytes($entry[1]);

                $chars[] = [
                    'code' => $code,
                    'low' => self::number($code),
                    'high' => self::number($code),
                    'value' => $value,
                ];
            }
        }

        return $chars;
    }

    /**
     * The entries of one block, one at a time.
     *
     * Lazily, which is the point: preg_match_all over a block builds an
     * array of every match in it before the cap above can look at the
     * first one, and a /ToUnicode stream may decode to a hundred
     * megabytes. Handing them over one at a time lets the caller stop at
     * MAX_ENTRIES and leave the rest of the block unread.
     *
     * @return \Generator<int, list<string>>
     */
    private static function entriesIn(string $block, string $pattern): \Generator
    {
        $offset = 0;

        while (preg_match($pattern, $block, $entry, PREG_OFFSET_CAPTURE, $offset) === 1) {
            // Past the whole match, so a pattern that could match the
            // empty string still moves the scan forward.
            $offset = $entry[0][1] + max(1, strlen($entry[0][0]));

            yield array_map(static fn (array $capture): string => $capture[0], $entry);
        }
    }

    /**
     * A destination as a number: a character id as written, or the code
     * point a hex string stands for.
     *
     * A hex destination of more than one character -- a code standing
     * for a ligature, "ffi" -- has no single code point, and no way back
     * from text to that code that this could use. Skipped rather than
     * guessed at.
     */
    private static function destination(string $written, bool $decimal): ?int
    {
        if ($decimal) {
            return (int) $written;
        }

        $bytes = self::hexBytes($written);

        return match (strlen($bytes)) {
            2 => self::number($bytes),
            // Four bytes are a surrogate pair -- one character, written
            // as UTF-16 wants it.
            4 => self::fromSurrogates(self::number($bytes)),
            default => null,
        };
    }

    private static function fromSurrogates(int $pair): ?int
    {
        $high = $pair >> 16;
        $low = $pair & 0xFFFF;

        if ($high < 0xD800 || $high > 0xDBFF || $low < 0xDC00 || $low > 0xDFFF) {
            return null;
        }

        return 0x10000 + (($high - 0xD800) << 10) + ($low - 0xDC00);
    }

    /**
     * @return list<string> the body of every "N begin<keyword> ...
     *         end<keyword>" block
     *
     * Found by scanning rather than by a lazy regex. "begin(.*?)end"
     * backtracks once per character of the block, so PCRE's default
     * backtrack limit of a million stops it dead on any block past about
     * a megabyte -- and stops it by returning false, which reads as "this
     * CMap has no entries" and leaves a field silently undrawn. A CJK
     * font's /ToUnicode is routinely bigger than that.
     */
    private static function blocks(string $cmap, string $keyword): array
    {
        $begin = 'begin' . $keyword;
        $end = 'end' . $keyword;
        $blocks = [];
        $at = 0;

        while (($start = strpos($cmap, $begin, $at)) !== false) {
            $start += strlen($begin);
            $stop = strpos($cmap, $end, $start);

            if ($stop === false) {
                break;
            }

            $blocks[] = substr($cmap, $start, $stop - $start);
            $at = $stop + strlen($end);
        }

        return $blocks;
    }

    private static function hexBytes(string $hex): string
    {
        // An odd number of digits is padded on the right, as PDF says of
        // hex strings everywhere else.
        return (string) hex2bin(strlen($hex) % 2 === 0 ? $hex : $hex . '0');
    }

    private static function number(string $bytes): int
    {
        $number = 0;

        for ($i = 0, $length = strlen($bytes); $i < $length; ++$i) {
            $number = ($number << 8) | ord($bytes[$i]);
        }

        return $number;
    }

    private static function bytes(int $number, int $length): string
    {
        $bytes = '';

        for ($i = $length - 1; $i >= 0; --$i) {
            $bytes .= chr(($number >> ($i * 8)) & 0xFF);
        }

        return $bytes;
    }
}
