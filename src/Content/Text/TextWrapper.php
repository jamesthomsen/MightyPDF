<?php

declare(strict_types=1);

namespace MightyPDF\Content\Text;

use MightyPDF\Assembler\Types\WinAnsiEncoding;
use MightyPDF\Content\Font\FontMetrics;

/**
 * Word-wraps plain text to a maximum line width, for use by
 * PageBuilder::drawParagraph() and by callers that need to know a
 * paragraph's height *before* drawing it (auto-sizing a box to its
 * content: measure via wrap(), multiply the line count by the desired
 * line height -- no dry-run/rollback render needed, since MightyPDF is a
 * pure writer with no reader-side state to undo).
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
        $encoded = WinAnsiEncoding::encode($utf8Text);

        $lines = [];
        foreach (explode("\n", $encoded) as $paragraph) {
            array_push($lines, ...self::wrapParagraph($paragraph, $metrics, $sizePt, $maxWidthPt));
        }

        return $lines;
    }

    /** @return list<string> */
    private static function wrapParagraph(string $paragraph, FontMetrics $metrics, float $sizePt, float $maxWidthPt): array
    {
        $lines = [];
        $current = '';

        foreach (preg_split('/ +/', $paragraph) as $word) {
            $candidate = $current === '' ? $word : "$current $word";

            // A lone word wider than the max width is placed on its own
            // line rather than split mid-word -- there's no hyphenation
            // here, and breaking a word arbitrarily would be worse.
            if ($current !== '' && $metrics->widthOf($candidate, $sizePt) > $maxWidthPt) {
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
