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
     * Repeating the last call is free, which is what makes the
     * measure-then-draw pattern this class exists for cost one wrap
     * rather than two: a caller sizing a box asks for the line count and
     * then hands the same text, font, size and width to a drawing call
     * that wraps it again to place it (Layout\Flow::paragraph() is
     * exactly this, and PageBuilder::drawParagraph() wraps internally by
     * design). One entry rather than a cache, because that is the shape
     * of the pattern -- measure, draw, move on -- and an unbounded cache
     * of every paragraph in a long document is a memory leak wearing a
     * performance fix's clothes.
     *
     * The size and width go into the key as their raw bytes rather than
     * as decimal text, so two widths that differ below the printing
     * precision cannot collide into one another's lines.
     *
     * @return list<string> one UTF-8 line per element, always at least
     *   one element (a single empty string for empty input)
     */
    public static function wrapUtf8(string $utf8Text, Font $font, float $sizePt, float $maxWidthPt): array
    {
        static $lastKey = null;
        static $lastLines = [];

        $key = $font->cacheKey() . "\0" . pack('dd', $sizePt, $maxWidthPt) . "\0" . $utf8Text;

        if ($key === $lastKey) {
            return $lastLines;
        }

        $lastLines = self::wrapBy(
            $utf8Text,
            static fn (string $text): float => $font->widthOfPt($text, $sizePt),
            $maxWidthPt,
        );
        $lastKey = $key;

        return $lastLines;
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
        return self::wrapRagged($text, $widthOf, $maxWidthPt, $maxWidthPt);
    }

    /**
     * The same wrap with a short first line -- text that starts partway
     * along a line already occupied by something else and runs on at full
     * width below it. Layout\Flow::write() is the caller: an inline run
     * begins wherever the cursor was left and continues between the
     * margins.
     *
     * Only the very first line is narrowed, not the first line of each
     * "\n"-separated paragraph: a newline puts the cursor back at the
     * left margin, so every line after the first has the whole width
     * whatever put it there.
     *
     * A word that does not fit the space left starts the next line --
     * reported as a leading empty line, since the caller has to move down
     * past the space it declined to use. That holds even for a word too
     * wide for a whole line: it overflows either way, and a whole line is
     * the least it can overflow by.
     *
     * @param \Closure(string): float $widthOf
     * @return list<string> one UTF-8 line per element
     */
    public static function wrapRagged(
        string $text,
        \Closure $widthOf,
        float $firstWidthPt,
        float $restWidthPt,
    ): array {
        $lines = [];
        $first = true;

        foreach (explode("\n", $text) as $paragraph) {
            array_push($lines, ...self::wrapParagraph(
                $paragraph,
                $widthOf,
                $first ? $firstWidthPt : $restWidthPt,
                $restWidthPt,
            ));

            $first = false;
        }

        return $lines;
    }

    /**
     * Measuring the accumulated line, rather than each word, is what
     * made this quadratic: a line of k words measured its own prefix k
     * times, and for a standard font re-encoded it each time too. On a
     * page-width box that is invisible, because k is small; on a wide
     * one it is not -- 4000 words on a single line took 1.6 seconds, and
     * four times the words took sixteen times as long.
     *
     * So widths accumulate instead. Every measurer in this library sums
     * per-glyph advances and so is additive -- width("a b") is
     * width("a") + width(" ") + width("b") -- but that is a property of
     * these fonts rather than of the \Closure signature, and it is only
     * additive to within floating-point rounding either way. Near the
     * boundary the running total is therefore not trusted: a candidate
     * whose estimate lands within a hair of the limit is measured for
     * real, which is also what resyncs the total against any drift. The
     * guard band is nine orders of magnitude wider than the error it
     * covers and costs at most one extra measurement per line, so the
     * wrap this produces is the wrap the naive loop produced, character
     * for character.
     *
     * @param \Closure(string): float $widthOf
     * @return list<string>
     */
    private static function wrapParagraph(
        string $paragraph,
        \Closure $widthOf,
        float $firstWidthPt,
        float $restWidthPt,
    ): array {
        $lines = [];
        $current = '';
        $currentWidth = 0.0;
        $maxWidthPt = $firstWidthPt;
        $onFirstLine = true;
        $spaceWidth = $widthOf(' ');
        $tolerance = 1e-9 * max(1.0, abs($restWidthPt));

        foreach (preg_split('/ +/', $paragraph) as $word) {
            $wordWidth = $widthOf($word);

            // A lone word wider than the max width is placed on its own
            // line rather than split mid-word -- there's no hyphenation
            // here, and breaking a word arbitrarily would be worse. That
            // is this branch: nothing to break away from.
            if ($current === '') {
                // Unless the line is short only because something else
                // is already on it: then there is a fuller line to go to,
                // and taking it is the difference between a run that
                // wraps and one that overflows the margin.
                if ($onFirstLine && $wordWidth > $maxWidthPt && $maxWidthPt < $restWidthPt) {
                    $lines[] = '';
                    $maxWidthPt = $restWidthPt;
                    $onFirstLine = false;
                }

                $current = $word;
                $currentWidth = $wordWidth;

                continue;
            }

            $candidateWidth = $currentWidth + $spaceWidth + $wordWidth;

            if (abs($candidateWidth - $maxWidthPt) <= $tolerance) {
                $candidateWidth = $widthOf("$current $word");
            }

            if ($candidateWidth > $maxWidthPt) {
                $lines[] = $current;
                $current = $word;
                $currentWidth = $wordWidth;
                $maxWidthPt = $restWidthPt;
                $onFirstLine = false;
            } else {
                $current = "$current $word";
                $currentWidth = $candidateWidth;
            }
        }

        $lines[] = $current;

        return $lines;
    }
}
