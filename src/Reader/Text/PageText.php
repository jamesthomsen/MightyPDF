<?php

declare(strict_types=1);

namespace MightyPDF\Reader\Text;

/**
 * The text of one page, and the geometry needed to read it back in order.
 *
 * A PDF page has no lines, no words and no paragraphs. It has show-text
 * operators at positions, and everything above that is inferred -- which
 * is why two tools extracting from the same page disagree about where the
 * line breaks are. The inference here is deliberately simple and stated
 * rather than tuned:
 *
 * - Fragments whose baselines are within a fraction of the font size are
 *   the same line. Baselines rather than boxes, because a superscript or a
 *   larger initial on the same line has a different height and the same
 *   baseline.
 * - Within a line, fragments are read left to right, and a gap wider than
 *   a fraction of the font size becomes a space -- because a producer that
 *   kerns by repositioning emits no space characters at all, and one that
 *   lays out columns with two positioned runs emits no separator either.
 * - Lines are read top to bottom.
 *
 * That handles ordinary documents and does not attempt multi-column
 * reading order, tables, or right-to-left runs. fragments() is there for
 * a caller who needs to do better: everything the page said about where
 * its text is, without this class's opinions applied.
 */
final class PageText
{
    /**
     * How close two baselines must be, as a fraction of the font size, to
     * count as one line.
     */
    private const float LINE_TOLERANCE = 0.3;

    /**
     * How wide a gap must be, as a fraction of the font size, before it
     * is read as a space. Roughly the width of a space in most faces;
     * generous enough not to split kerned pairs.
     */
    private const float SPACE_RATIO = 0.17;

    /**
     * @param list<TextFragment> $fragments
     * @param bool $truncated whether the page hit one of the extractor's
     *        limits before it ran out of content -- see isTruncated()
     */
    public function __construct(
        private readonly array $fragments,
        private readonly bool $truncated = false,
    ) {
    }

    /**
     * Every run of text the page drew, in the order it drew them.
     *
     * @return list<TextFragment>
     */
    public function fragments(): array
    {
        return $this->fragments;
    }

    public function isEmpty(): bool
    {
        return $this->fragments === [];
    }

    /**
     * Whether this page stopped early: it reached one of the limits
     * TextExtractor puts on how much work a single page may cause, and
     * there was still content it had not followed.
     *
     * Worth asking about, and the reason it is asked rather than thrown.
     * Those limits exist because a page can invoke a form XObject as many
     * times as it likes, and a few hundred bytes of file can ask for more
     * work than there is time in the day -- so they cannot be lifted. But
     * a caller extracting text is usually doing something forgiving with
     * it, and throwing would turn "most of this page" into "none of it"
     * for the one document in a million that is merely enormous rather
     * than hostile.
     *
     * So the text is returned and the shortfall is stated. A search index
     * can note the page as needing another look; a checker can refuse it;
     * something counting words can carry on. What none of them has to do
     * is guess, which is what a silent truncation would make them do.
     */
    public function isTruncated(): bool
    {
        return $this->truncated;
    }

    /**
     * The page's text, laid out into lines.
     *
     * @return list<string> one entry per line, top to bottom
     */
    public function lines(): array
    {
        $lines = [];

        foreach ($this->grouped() as $group) {
            $line = '';
            $previous = null;

            foreach ($group as $fragment) {
                if ($previous !== null && self::needsSpace($previous, $fragment)) {
                    $line .= ' ';
                }

                $line .= $fragment->text;
                $previous = $fragment;
            }

            $trimmed = rtrim($line);

            if ($trimmed !== '') {
                $lines[] = $trimmed;
            }
        }

        return $lines;
    }

    public function text(): string
    {
        return implode("\n", $this->lines());
    }

    /**
     * Fragments gathered into lines, each sorted left to right, the lines
     * themselves top to bottom.
     *
     * @return list<list<TextFragment>>
     */
    private function grouped(): array
    {
        $ordered = $this->fragments;

        // Sorted by baseline first so that a page whose producer drew its
        // footer before its body still reads in visual order.
        usort($ordered, static function (TextFragment $a, TextFragment $b): int {
            return $b->y <=> $a->y ?: $a->x <=> $b->x;
        });

        $lines = [];
        $current = [];
        $baseline = null;

        foreach ($ordered as $fragment) {
            $tolerance = max($fragment->fontSize, 1.0) * self::LINE_TOLERANCE;

            if ($baseline !== null && abs($fragment->y - $baseline) > $tolerance) {
                $lines[] = self::leftToRight($current);
                $current = [];
            }

            // The baseline of a line is the first fragment's, not a
            // running average: a line with a taller initial should not
            // drag the line it belongs to away from the rest of it.
            $baseline ??= $fragment->y;

            if ($current === []) {
                $baseline = $fragment->y;
            }

            $current[] = $fragment;
        }

        if ($current !== []) {
            $lines[] = self::leftToRight($current);
        }

        return $lines;
    }

    /**
     * @param list<TextFragment> $line
     * @return list<TextFragment>
     */
    private static function leftToRight(array $line): array
    {
        usort($line, static fn (TextFragment $a, TextFragment $b): int => $a->x <=> $b->x);

        return $line;
    }

    private static function needsSpace(TextFragment $previous, TextFragment $next): bool
    {
        if (str_ends_with($previous->text, ' ') || str_starts_with($next->text, ' ')) {
            return false;
        }

        $gap = $next->x - $previous->endX();

        return $gap > max($next->fontSize, 1.0) * self::SPACE_RATIO;
    }
}
