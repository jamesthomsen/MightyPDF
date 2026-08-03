<?php

declare(strict_types=1);

namespace MightyPDF\Content\Text;

use MightyPDF\Assembler\Types\WinAnsiEncoding;
use MightyPDF\Content\Font\Font;
use MightyPDF\Content\Font\FontMetrics;

/**
 * Word-wraps plain text to a maximum line width, for use by
 * PageBuilder::drawParagraph() and by callers that need to know a
 * paragraph's height *before* drawing it (auto-sizing a box to its
 * content: measure via wrap(), multiply the line count by the desired
 * line height -- no dry-run/rollback render needed, since MightyPDF is a
 * pure writer with no reader-side state to undo).
 *
 * Two entry points, differing only in what does the measuring and
 * therefore in what comes back:
 *
 * - wrapUtf8() measures through a Font -- any font, embedded ones
 *   included -- and returns text, which is what a caller then draws.
 * - wrap() measures through a standard font's width table and returns
 *   WinAnsi-encoded bytes, for a caller working in encoded text.
 * - wrapBy() measures through whatever closure it is handed and returns
 *   the text as given, for a caller whose font is neither -- a form
 *   field's, read back out of a file, which is a width table only after
 *   several lookups (see Editor\Form\FieldFont).
 */
final class TextWrapper
{
    private function __construct()
    {
    }

    /**
     * @return list<string> one WinAnsiEncoding-encoded line per element,
     *   always at least one element (a single empty string for empty input)
     */
    public static function wrap(string $utf8Text, FontMetrics $metrics, float $sizePt, float $maxWidthPt): array
    {
        $lines = self::wrapBy(
            $utf8Text,
            static fn (string $text): float => $metrics->widthOf(WinAnsiEncoding::encode($text), $sizePt),
            $maxWidthPt,
        );

        return array_map(WinAnsiEncoding::encode(...), $lines);
    }

    /**
     * @return list<string> one UTF-8 line per element, always at least
     *   one element (a single empty string for empty input)
     */
    public static function wrapUtf8(string $utf8Text, Font $font, float $sizePt, float $maxWidthPt): array
    {
        return self::wrapBy(
            $utf8Text,
            static fn (string $text): float => $font->widthOfPt($text, $sizePt),
            $maxWidthPt,
        );
    }

    /**
     * Wrapping proper, over whatever measures a string.
     *
     * Measurement is a callback rather than a width table because the
     * callers disagree about what a string even is: one is measuring
     * characters a font will encode for itself, another bytes that are
     * already encoded, another a form font read back out of a file.
     * All three agree about where a line can break, which is all this
     * needs to know.
     *
     * @param \Closure(string): float $widthOf
     * @return list<string> one UTF-8 line per element
     */
    public static function wrapBy(string $text, \Closure $widthOf, float $maxWidthPt): array
    {
        $lines = [];

        foreach (explode("\n", $text) as $paragraph) {
            array_push($lines, ...self::wrapParagraph($paragraph, $widthOf, $maxWidthPt));
        }

        return $lines;
    }

    /**
     * @param \Closure(string): float $widthOf
     * @return list<string>
     */
    private static function wrapParagraph(string $paragraph, \Closure $widthOf, float $maxWidthPt): array
    {
        $lines = [];
        $current = '';

        foreach (preg_split('/ +/', $paragraph) as $word) {
            $candidate = $current === '' ? $word : "$current $word";

            // A lone word wider than the max width is placed on its own
            // line rather than split mid-word -- there's no hyphenation
            // here, and breaking a word arbitrarily would be worse.
            if ($current !== '' && $widthOf($candidate) > $maxWidthPt) {
                $lines[] = $current;
                $current = $word;
            } else {
                $current = $candidate;
            }
        }

        $lines[] = $current;

        return $lines;
    }
}
