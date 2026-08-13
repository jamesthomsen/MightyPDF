<?php

declare(strict_types=1);

namespace MightyPDF\Assembler;

use MightyPDF\Assembler\Types\PdfArray;
use MightyPDF\Assembler\Types\PdfInteger;
use MightyPDF\Assembler\Types\PdfName;
use MightyPDF\Assembler\Types\PdfString;
use MightyPDF\Assembler\Types\PdfValue;
use MightyPDF\Exception\InvalidArgumentException;
use MightyPDF\Exception\LogicException;

/**
 * What the reader calls each page (ISO 32000-2 §12.4.2) -- the number in
 * its toolbar, its thumbnails and its "go to page" box.
 *
 * Without this a reader counts from 1 and has no other idea, so a report
 * whose front matter is numbered i, ii, iii and whose body restarts at 1
 * shows "page 5 of 40" while the paper in the reader's hand says 1. That
 * is the whole problem being solved: the labels here are what the reader
 * displays, and it is a separate business from any folio drawn on the
 * page.
 *
 * The document is described as a series of runs, each starting at a page
 * and continuing until the next one starts:
 *
 * ```php
 * $document->pageLabels()
 *     ->from(0, PageLabelStyle::LowercaseRoman)              // i, ii, iii, iv
 *     ->from(4, PageLabelStyle::Decimal)                     // 1, 2, 3, ...
 *     ->from(30, PageLabelStyle::Decimal, prefix: 'A-', startAt: 1);  // A-1, A-2
 * ```
 *
 * Runs may be declared in any order -- they are sorted on the way out,
 * because §12.4.2's number tree requires ascending keys and a reader
 * handed them unsorted does not search it, it just gets the wrong answer.
 */
final class PageLabels extends Dictionary
{
    /**
     * Page index => the entry starting there. Kept as its own map rather
     * than only as /Nums so that a run can be redefined, and so that the
     * ordering is re-established every time rather than depended on.
     *
     * @var array<int, array{style: PageLabelStyle, prefix: string, startAt: int}>
     */
    private array $runs = [];

    /**
     * Starts a run of labels at $pageIndex, replacing any run already
     * declared to start exactly there.
     *
     * @param int $pageIndex zero-based, like every other page index here
     *        and unlike the number the label itself produces
     * @param string $prefix put in front of every label in the run -- the
     *        "A-" of "A-1", or the whole label where the style is None
     * @param int $startAt what the first page of the run counts as, for a
     *        chapter that continues another document's numbering
     */
    public function from(
        int $pageIndex,
        PageLabelStyle $style = PageLabelStyle::Decimal,
        string $prefix = '',
        int $startAt = 1,
    ): static {
        if ($pageIndex < 0) {
            throw new InvalidArgumentException(
                "A run of page labels starts at a page, and there is no page $pageIndex.",
            );
        }

        if ($startAt < 1) {
            throw new InvalidArgumentException(
                "Page labels are numbered from 1 upwards (\u{a7}12.4.2 requires /St to be at least 1), "
                . "so a run cannot start at $startAt.",
            );
        }

        $this->runs[$pageIndex] = ['style' => $style, 'prefix' => $prefix, 'startAt' => $startAt];

        $this->rebuild();

        return $this;
    }

    /** Whether anything has been declared at all. */
    public function isEmpty(): bool
    {
        return $this->runs === [];
    }

    /**
     * The label a reader will show for a page -- prefix and number
     * together.
     *
     * The point of being able to ask is that the answer is otherwise only
     * visible by opening the file: a table of contents that has to print
     * "see page A-4" needs the same string the toolbar will show, and
     * working it out a second time by hand is how the two come to
     * disagree.
     */
    public function labelFor(int $pageIndex): string
    {
        $start = null;

        foreach (array_keys($this->runs) as $candidate) {
            if ($candidate <= $pageIndex && ($start === null || $candidate > $start)) {
                $start = $candidate;
            }
        }

        if ($start === null) {
            // Before the first run, or no runs at all: the reader is
            // counting from 1 by itself.
            return (string) ($pageIndex + 1);
        }

        $run = $this->runs[$start];

        return $run['prefix'] . $run['style']->format($run['startAt'] + $pageIndex - $start);
    }

    /**
     * Checks that the tree says what page 0 is called.
     *
     * §12.4.2 requires an entry at index 0, and a tree without one is the
     * one mistake here that readers handle differently from each other:
     * some fall back to counting, some label the front of the document
     * with the first run they find, and some show nothing. Refusing is the
     * only outcome that is the same everywhere.
     */
    public function validate(): void
    {
        if ($this->runs === [] || isset($this->runs[0])) {
            return;
        }

        throw new LogicException(sprintf(
            'Page labels have to say what page 0 is called: §12.4.2 requires an entry at index 0, and '
            . 'the earliest run here starts at %d. Add a run from 0, or start that one at 0.',
            min(array_keys($this->runs)),
        ));
    }

    private function rebuild(): void
    {
        $runs = $this->runs;
        ksort($runs);

        $nums = [];

        foreach ($runs as $pageIndex => $run) {
            $nums[] = new PdfInteger($pageIndex);
            $nums[] = self::entry($run['style'], $run['prefix'], $run['startAt']);
        }

        $this->set('Nums', new PdfArray(...$nums));
    }

    /** @return Dictionary */
    private static function entry(PageLabelStyle $style, string $prefix, int $startAt): PdfValue
    {
        $entry = new Dictionary();

        // No /S at all is how "prefix only" is expressed -- /S with an
        // empty name would be a style called "", which is not one.
        if ($style !== PageLabelStyle::None) {
            $entry->set('S', new PdfName($style->value));
        }

        if ($prefix !== '') {
            $entry->set('P', PdfString::text($prefix));
        }

        // Omitted where it is the default, since /St 1 on every run is
        // noise in a file people do read by hand.
        if ($startAt !== 1) {
            $entry->set('St', new PdfInteger($startAt));
        }

        return $entry;
    }
}
