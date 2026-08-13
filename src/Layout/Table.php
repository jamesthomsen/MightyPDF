<?php

declare(strict_types=1);

namespace MightyPDF\Layout;

use MightyPDF\Assembler\Structure\StructureElement;
use MightyPDF\Assembler\Structure\StructureRole;
use MightyPDF\Content\Color;
use MightyPDF\Content\Paint;
use MightyPDF\Content\Text\HorizontalAlign;
use MightyPDF\Exception\InvalidArgumentException;

/**
 * A table: fixed column widths, cells that wrap, rows that size
 * themselves to their tallest cell, and a header that comes back at the
 * top of every page the table runs onto.
 *
 * Flow could already draw one -- a row is a run of cell() calls and a
 * newLine() -- and that is exactly the problem. Every table written that
 * way restates its column widths on every row, has to be edited in three
 * places to add a column, and silently loses its header the moment an
 * automatic page break lands in the middle of it. A reader looking at
 * page four of a table has no way to know what the columns are.
 *
 * ```php
 * $flow->table([70.0, 60.0, 30.0], $body, $heading)
 *     ->align(2, HorizontalAlign::Right)
 *     ->striped(Color::gray(0.96))
 *     ->header(['Control', 'Owner', 'Status'])
 *     ->rows($controls, fn (Control $c) => [$c->name, $c->owner, $c->status])
 *     ->end();
 * ```
 *
 * **Row heights.** A row is as tall as its tallest cell once wrapped,
 * plus the table's vertical padding, and never shorter than
 * minRowHeight. That is the whole reason cells wrap here and not in
 * cell(): a row cannot know its height until every cell in it has been
 * measured, so the measuring has to belong to something that sees the
 * row rather than to something that sees one cell.
 *
 * **Page breaks** go through Flow's own, so a Flow built with
 * autoPageBreak off does not get one here either. The header is redrawn
 * when -- and only when -- a break actually happened, which Table detects
 * from the page number rather than by predicting it.
 */
final class Table
{
    /** @var list<Cell>|null the header, kept so it can be drawn again after a break */
    private ?array $header = null;

    /** @var array<int, Style> column index => style overriding the row's */
    private array $columnStyles = [];

    /** @var array<int, HorizontalAlign> column index => alignment laid over whatever style applies */
    private array $alignments = [];

    private ?Paint $stripe = null;

    private int $bodyRows = 0;

    /** The /Table element, once a row has been drawn into it. */
    private ?StructureElement $tableElement = null;

    /** Where the Flow was before the table opened, to put it back. */
    private ?StructureElement $outerElement = null;

    /**
     * @param list<float> $widths column widths in the Flow's unit
     * @param float $verticalPaddingPt breathing room above and below a
     *        cell's text, in points -- the vertical counterpart to
     *        Style::$paddingPt, which lives here rather than on Style
     *        because it is a property of how a row is *sized* and Style
     *        deliberately has no say in that (see Style's doc comment)
     */
    public function __construct(
        private readonly Flow $flow,
        private readonly array $widths,
        private readonly Style $bodyStyle,
        private readonly ?Style $headerStyle = null,
        private readonly float $minRowHeight = 0.0,
        private readonly float $verticalPaddingPt = 2.0,
    ) {
        if ($widths === []) {
            throw new InvalidArgumentException('A table needs at least one column.');
        }

        foreach ($widths as $width) {
            if ($width <= 0.0) {
                throw new InvalidArgumentException("A column must be wider than nothing, got $width.");
            }
        }
    }

    /** @return list<float> */
    public function widths(): array
    {
        return $this->widths;
    }

    /** The sum of the column widths, in the Flow's unit. */
    public function width(): float
    {
        return array_sum($this->widths);
    }

    /**
     * A style applied to one column, overriding whatever the row was
     * given -- how the figures column gets right-aligned once rather
     * than once per row.
     */
    public function columnStyle(int $index, Style $style): static
    {
        $this->requireColumn($index);
        $this->columnStyles[$index] = $style;

        return $this;
    }

    /**
     * The common case of columnStyle(): change one column's alignment and
     * leave everything else alone.
     *
     * Laid over whatever style the row turns out to be drawn with rather
     * than captured now, so it keeps working for a row given a style of
     * its own. A *cell* with its own style is taken whole and this does
     * not apply -- see styleFor().
     */
    public function align(int $index, HorizontalAlign $align): static
    {
        $this->requireColumn($index);
        $this->alignments[$index] = $align;

        return $this;
    }

    /**
     * Shades alternate body rows. Counted over body rows only, so the
     * striping does not shift when a header is added, and it carries
     * across a page break rather than restarting -- a row keeps its
     * shade wherever it lands.
     */
    public function striped(?Paint $shade = null): static
    {
        $this->stripe = $shade ?? Color::gray(0.96);

        return $this;
    }

    /**
     * Sets the header and draws it here. Kept, so that every page this
     * table runs onto gets it again.
     *
     * @param list<Cell|string> $cells
     */
    public function header(array $cells, ?float $height = null): static
    {
        $this->header = array_map(Cell::from(...), $cells);

        $this->drawRow($this->header, $this->headerStyle ?? $this->bodyStyle, $height, null, isHeader: true);

        return $this;
    }

    /**
     * One body row. Breaks the page first if it would not fit, redrawing
     * the header on the new one.
     *
     * @param list<Cell|string> $cells
     */
    public function row(array $cells, ?Style $style = null, ?float $height = null): static
    {
        $resolved = array_map(Cell::from(...), $cells);
        $style ??= $this->bodyStyle;

        $shade = $this->stripe !== null && $this->bodyRows % 2 === 1 ? $this->stripe : null;
        ++$this->bodyRows;

        $this->drawRow($resolved, $style, $height, $shade);

        return $this;
    }

    /**
     * Every row of a collection, through a closure that turns one item
     * into one row's cells -- which is what the calling code was going to
     * write as a foreach anyway, and keeps the table a single expression.
     *
     * @template T
     *
     * @param iterable<T> $items
     * @param \Closure(T, int): list<Cell|string> $toCells
     */
    public function rows(iterable $items, \Closure $toCells, ?Style $style = null): static
    {
        $index = 0;

        foreach ($items as $item) {
            $this->row($toCells($item, $index++), $style);
        }

        return $this;
    }

    /**
     * Back to the Flow, so a table can be the middle of a chain -- and,
     * in a tagged document, where the /Table element closes.
     *
     * A table left unended in a tagged document has its remaining content
     * nested inside it, which is why this is worth calling even when the
     * chain does not need it.
     */
    public function end(): Flow
    {
        $this->closeTag();

        return $this->flow;
    }

    /**
     * The height this row will take, in the Flow's unit, without drawing
     * it -- for a caller deciding whether a section fits, or levelling a
     * table against something beside it.
     *
     * @param list<Cell|string> $cells
     */
    public function heightOf(array $cells, ?Style $style = null): float
    {
        return $this->measure(array_map(Cell::from(...), $cells), $style ?? $this->bodyStyle, null);
    }

    /**
     * Measures, breaks if it must, and draws -- the body every row goes
     * through, header included.
     *
     * @param list<Cell> $cells
     */
    private function drawRow(array $cells, Style $style, ?float $height, ?Paint $shade, bool $isHeader = false): void
    {
        $spans = $this->spans($cells);
        $height ??= $this->measure($cells, $style, $spans);

        // Through Flow's own break rather than around it, so a Flow built
        // with autoPageBreak off stays that way, and so the "never break
        // at the top of a page" rule that stops an oversized row looping
        // forever still applies.
        $before = $this->flow->pageNumber();
        $this->flow->breakIfNeeded($height);

        if (!$isHeader && $this->header !== null && $this->flow->pageNumber() !== $before) {
            // Detected rather than predicted: whether a break happened is
            // Flow's decision, and asking it afterwards cannot disagree
            // with what it did. At the top of a fresh page the header's
            // own breakIfNeeded() is a no-op, so this cannot recurse.
            $this->drawRow($this->header, $this->headerStyle ?? $this->bodyStyle, null, null, isHeader: true);
        }

        $x = $this->flow->margins()->left;
        $y = $this->flow->y();

        // A row is a /TR and its cells are /TH or /TD -- which the layout
        // knows without being told, this being a table. Header cells
        // matter more than they look: they are what lets a screen reader
        // say which column a value is in, instead of reading a grid of
        // unattached numbers.
        $this->openRowTag();

        foreach ($cells as $index => $cell) {
            $width = $spans[$index];
            $cellStyle = $this->styleFor($cell, $index, $style, $shade);

            $this->flow->tag(
                $isHeader ? StructureRole::TableHeader : StructureRole::TableData,
                fn (Flow $flow) => $flow->paragraphAt($x, $y, $width, $height, $cell->text, $cellStyle),
            );

            $x += $width;
        }

        $this->closeRowTag();

        $this->flow->newLine($height);
    }

    /**
     * Opens the /Table element on the first row drawn, and a /TR for the
     * row about to be drawn.
     *
     * Opened lazily rather than in the constructor, because a Table that
     * is built and never given a row should not leave an empty element in
     * the structure -- and because the /TR has to close before the next
     * one opens, which a constructor cannot arrange.
     */
    private function openRowTag(): void
    {
        if ($this->flow->currentElement() === null) {
            return;
        }

        if ($this->tableElement === null) {
            $this->tableElement = $this->flow->currentElement()?->child(StructureRole::Table);
            $this->outerElement = $this->flow->currentElement();
        }

        if ($this->tableElement !== null) {
            $this->flow->enterElement($this->tableElement->child(StructureRole::TableRow));
        }
    }

    private function closeRowTag(): void
    {
        if ($this->tableElement !== null) {
            $this->flow->enterElement($this->tableElement);
        }
    }

    /** Closes the /Table, putting the Flow back where it was. */
    private function closeTag(): void
    {
        if ($this->tableElement !== null && $this->outerElement !== null) {
            $this->flow->enterElement($this->outerElement);
        }

        $this->tableElement = null;
        $this->outerElement = null;
    }

    /**
     * The width each cell occupies, which is its columns' widths summed.
     *
     * The count is checked rather than padded or truncated: a row with
     * the wrong number of cells is a bug in the calling code, and drawing
     * it anyway puts every column after the mistake under the wrong
     * heading -- which reads as data rather than as an error.
     *
     * @param list<Cell> $cells
     *
     * @return list<float> one width per cell, not per column
     */
    private function spans(array $cells): array
    {
        $widths = [];
        $column = 0;

        foreach ($cells as $cell) {
            $span = 0.0;

            for ($i = 0; $i < $cell->colspan; ++$i) {
                if (!isset($this->widths[$column])) {
                    throw new InvalidArgumentException(sprintf(
                        'This row spans more than the table\'s %d column(s).',
                        count($this->widths),
                    ));
                }

                $span += $this->widths[$column++];
            }

            $widths[] = $span;
        }

        if ($column !== count($this->widths)) {
            throw new InvalidArgumentException(sprintf(
                'This row covers %d of the table\'s %d column(s) -- pad it with empty cells, or widen one with a colspan.',
                $column,
                count($this->widths),
            ));
        }

        return $widths;
    }

    /**
     * The tallest cell once wrapped, plus the vertical padding, floored
     * at minRowHeight.
     *
     * @param list<Cell> $cells
     * @param list<float>|null $spans computed if not already known
     */
    private function measure(array $cells, Style $style, ?array $spans): float
    {
        $spans ??= $this->spans($cells);
        $padding = $this->flow->unit()->fromPoints(2 * $this->verticalPaddingPt);

        $tallest = 0.0;

        foreach ($cells as $index => $cell) {
            if ($cell->text === '') {
                continue;
            }

            $tallest = max($tallest, $this->flow->paragraphHeight(
                $spans[$index],
                $cell->text,
                $this->styleFor($cell, $index, $style, null),
            ));
        }

        return max($this->minRowHeight, $tallest + $padding);
    }

    /**
     * What a cell is actually drawn with.
     *
     * Most specific wins, and a cell that names a style of its own is as
     * specific as it gets: it is taken whole, alignment included, because
     * a caller writing one out has said what they want and merging half
     * of something else into it is a rule nobody can predict.
     *
     * Otherwise the column's style, or the row's, with the column's
     * alignment laid over it -- alignment separately because it is the
     * one thing a column almost always wants to say and a whole Style is
     * a heavy way to say it.
     *
     * The stripe goes on last and only where nothing else claimed a fill,
     * since it is decoration rather than intent.
     */
    private function styleFor(Cell $cell, int $index, Style $rowStyle, ?Paint $shade): Style
    {
        if ($cell->style !== null) {
            return $cell->style;
        }

        $style = $this->columnStyles[$index] ?? $rowStyle;

        if (isset($this->alignments[$index])) {
            $style = $style->with(align: $this->alignments[$index]);
        }

        return $shade === null || $style->fill !== null ? $style : $style->with(fill: $shade);
    }

    private function requireColumn(int $index): void
    {
        if (!isset($this->widths[$index])) {
            throw new InvalidArgumentException(sprintf(
                'Column %d does not exist -- this table has %d.',
                $index,
                count($this->widths),
            ));
        }
    }
}
