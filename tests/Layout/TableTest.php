<?php

declare(strict_types=1);

namespace MightyPDF\Tests\Layout;

use MightyPDF\Assembler\Document;
use MightyPDF\Assembler\Page;
use MightyPDF\Assembler\PageSize;
use MightyPDF\Content\Color;
use MightyPDF\Content\Font\StandardFont;
use MightyPDF\Content\Text\HorizontalAlign;
use MightyPDF\Layout\Border;
use MightyPDF\Layout\Cell;
use MightyPDF\Layout\Flow;
use MightyPDF\Layout\Margins;
use MightyPDF\Layout\Style;
use MightyPDF\Layout\Unit;
use PHPUnit\Framework\TestCase;

final class TableTest extends TestCase
{
    private function flow(?Document $document = null, bool $autoPageBreak = true): Flow
    {
        return new Flow(
            $document ?? new Document(),
            PageSize::A4,
            new Margins(15.0, 15.0, 15.0, 15.0),
            Unit::Millimetres,
            $autoPageBreak,
        );
    }

    public function testAColumnCountMismatchIsRefusedRatherThanDrawnCrooked(): void
    {
        $table = $this->flow()->table([40.0, 40.0, 40.0]);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('covers 2 of the table\'s 3 column(s)');

        $table->row(['a', 'b']);
    }

    public function testTooManyCellsIsRefusedToo(): void
    {
        $table = $this->flow()->table([40.0, 40.0]);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('spans more than');

        $table->row(['a', 'b', 'c']);
    }

    public function testAColspanTakesTheWidthOfEveryColumnItCovers(): void
    {
        $document = new Document();
        $flow = $this->flow($document);

        $flow->table([40.0, 40.0, 20.0])
            ->row([new Cell('wide', colspan: 2), 'narrow']);

        // The second cell starts 80mm past the left margin, not 40mm --
        // the first cell took both of the columns it spans.
        $content = $this->contentOf($document->pages()[0]);
        preg_match_all('/1 0 0 1 ([\d.]+) [\d.]+ Tm/', $content, $matches);

        $starts = array_map('floatval', $matches[1]);
        $padding = 3.0;

        self::assertCount(2, $starts);
        self::assertEqualsWithDelta($flow->toPointsX(15.0) + $padding, $starts[0], 1e-6);
        self::assertEqualsWithDelta($flow->toPointsX(95.0) + $padding, $starts[1], 1e-6);
    }

    public function testASingleCellRowNeedsAColspanCoveringTheTable(): void
    {
        $document = new Document();

        $this->flow($document)->table([40.0, 40.0])
            ->row([new Cell('Continued overleaf', colspan: 2)]);

        self::assertStringContainsString('(Continued overleaf) Tj', $this->contentOf($document->pages()[0]));
    }

    public function testColspanOfZeroIsRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new Cell('x', colspan: 0);
    }

    // -- Row heights -----------------------------------------------------

    /**
     * The whole reason a table measures a row rather than a cell: a row is
     * as tall as its tallest cell once wrapped, and nothing that sees only
     * one cell can know that.
     */
    public function testARowIsAsTallAsItsTallestWrappedCell(): void
    {
        $table = $this->flow()->table([30.0, 30.0]);

        $short = $table->heightOf(['one', 'two']);
        $tall = $table->heightOf(['one', 'a much longer piece of text that has to wrap over several lines']);

        self::assertGreaterThan($short * 2, $tall);
    }

    public function testAnEmptyRowStillGetsTheMinimumHeight(): void
    {
        $flow = $this->flow();
        $table = $flow->table([30.0, 30.0], minRowHeight: 12.0);

        self::assertSame(12.0, $table->heightOf(['', '']));
    }

    public function testRowHeightIncludesTheVerticalPadding(): void
    {
        $flow = $this->flow();

        $padded = $flow->table([60.0], verticalPaddingPt: 6.0)->heightOf(['one line']);
        $tight = $flow->table([60.0], verticalPaddingPt: 0.0)->heightOf(['one line']);

        self::assertEqualsWithDelta(Unit::Millimetres->fromPoints(12.0), $padded - $tight, 1e-9);
    }

    public function testTheCursorAdvancesByTheRowHeight(): void
    {
        $flow = $this->flow();
        $table = $flow->table([60.0]);

        $top = $flow->y();
        $height = $table->heightOf(['one line']);
        $table->row(['one line']);

        self::assertEqualsWithDelta($top + $height, $flow->y(), 1e-9);
        self::assertSame($flow->margins()->left, $flow->x(), 'and back to the left margin');
    }

    // -- Headers across pages --------------------------------------------

    /**
     * The guarantee the whole class exists for. A reader looking at page
     * four of a table has no way to know what the columns are, and a
     * header drawn once is a header only on page one.
     */
    public function testTheHeaderComesBackOnEveryPageTheTableRunsOnto(): void
    {
        $document = new Document();
        $flow = $this->flow($document);

        $table = $flow->table([60.0, 60.0], headerStyle: new Style(StandardFont::HelveticaBold))
            ->header(['Control', 'Owner']);

        for ($i = 0; $i < 60; ++$i) {
            $table->row(["Control $i", "Owner $i"]);
        }

        self::assertGreaterThan(1, $flow->pageCount());

        foreach ($document->pages() as $index => $page) {
            self::assertStringContainsString(
                '(Control) Tj',
                $this->contentOf($page),
                "page $index should carry the header",
            );
        }
    }

    public function testTheHeaderIsNotRedrawnWhenNoBreakHappened(): void
    {
        $document = new Document();

        $this->flow($document)->table([60.0, 60.0])
            ->header(['Control', 'Owner'])
            ->row(['a', 'b'])
            ->row(['c', 'd']);

        self::assertSame(1, substr_count($this->contentOf($document->pages()[0]), '(Control) Tj'));
    }

    /**
     * The break goes through Flow's own, so a Flow that was told not to
     * break pages does not get one here either -- and then there is no
     * second page for a header to appear on.
     */
    public function testATableInAFlowWithNoAutoBreakDoesNotBreak(): void
    {
        $document = new Document();
        $flow = $this->flow($document, autoPageBreak: false);

        $table = $flow->table([60.0, 60.0])->header(['Control', 'Owner']);

        for ($i = 0; $i < 60; ++$i) {
            $table->row(["Control $i", "Owner $i"]);
        }

        self::assertSame(1, $flow->pageCount());
    }

    // -- Styling ----------------------------------------------------------

    public function testAColumnAlignmentAppliesToEveryRowWithoutRestatingIt(): void
    {
        $document = new Document();
        $flow = $this->flow($document);

        $flow->table([60.0, 30.0])
            ->align(1, HorizontalAlign::Right)
            ->row(['Widgets', '12'])
            ->row(['Sprockets', '4']);

        $content = $this->contentOf($document->pages()[0]);
        preg_match_all('/1 0 0 1 ([\d.]+) [\d.]+ Tm/', $content, $matches);

        $starts = array_map('floatval', $matches[1]);

        // Both figures are right-aligned, so each sits at a different x
        // from the labels and further right than a left-aligned cell.
        self::assertGreaterThan($starts[0], $starts[1]);
        self::assertGreaterThan($starts[2], $starts[3]);
        self::assertNotEqualsWithDelta($starts[1], $starts[3], 1e-9, 'different widths, different offsets');
    }

    public function testStripingShadesAlternateBodyRowsAndIgnoresTheHeader(): void
    {
        $document = new Document();

        $this->flow($document)->table([60.0])
            ->striped(Color::gray(0.9))
            ->header(['Heading'])
            ->row(['first'])
            ->row(['second'])
            ->row(['third']);

        $content = $this->contentOf($document->pages()[0]);

        // Exactly one of the three body rows -- the second -- is shaded.
        self::assertSame(1, substr_count($content, '0.9 0.9 0.9 rg'));
    }

    public function testACellWithItsOwnFillKeepsItUnderStriping(): void
    {
        $document = new Document();

        $this->flow($document)->table([30.0, 30.0])
            ->striped(Color::gray(0.9))
            ->row(['a', 'b'])
            ->row(['c', new Cell('d', new Style(fill: Color::fromRgb255(255, 0, 0)))]);

        $content = $this->contentOf($document->pages()[0]);

        self::assertStringContainsString('1 0 0 rg', $content);
        self::assertSame(1, substr_count($content, '0.9 0.9 0.9 rg'), 'only the unstyled cell is shaded');
    }

    public function testAColumnStyleOverridesTheRowStyle(): void
    {
        $document = new Document();

        $this->flow($document)->table([30.0, 30.0])
            ->columnStyle(1, new Style(color: Color::fromRgb255(255, 0, 0)))
            ->row(['plain', 'red']);

        $content = $this->contentOf($document->pages()[0]);

        self::assertStringContainsString('0 0 0 rg', $content);
        self::assertStringContainsString('1 0 0 rg', $content);
    }

    public function testACellStyleOverridesTheColumnStyle(): void
    {
        $document = new Document();

        $this->flow($document)->table([30.0])
            ->columnStyle(0, new Style(color: Color::fromRgb255(255, 0, 0)))
            ->row([new Cell('blue', new Style(color: Color::fromRgb255(0, 0, 255)))]);

        self::assertStringContainsString('0 0 1 rg', $this->contentOf($document->pages()[0]));
    }

    public function testStylingAColumnThatDoesNotExistIsRefused(): void
    {
        $table = $this->flow()->table([30.0]);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Column 3 does not exist');

        $table->align(3, HorizontalAlign::Right);
    }

    public function testATableNeedsAtLeastOneColumn(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->flow()->table([]);
    }

    public function testAZeroWidthColumnIsRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->flow()->table([30.0, 0.0]);
    }

    // -- Chaining ---------------------------------------------------------

    public function testRowsDrawsOneRowPerItem(): void
    {
        $document = new Document();

        $this->flow($document)->table([40.0, 40.0])
            ->rows(
                [['Alice', 'ok'], ['Bob', 'late']],
                static fn (array $row): array => $row,
            )
            ->end()
            ->cell(40.0, 6.0, 'after');

        $content = $this->contentOf($document->pages()[0]);

        self::assertStringContainsString('(Alice) Tj', $content);
        self::assertStringContainsString('(late) Tj', $content);
        self::assertStringContainsString('(after) Tj', $content);
    }

    public function testBordersReachTheCells(): void
    {
        $document = new Document();

        $this->flow($document)->table([40.0], new Style(border: Border::box(0.5)))
            ->row(['boxed']);

        $content = $this->contentOf($document->pages()[0]);

        self::assertStringContainsString('0.5 w', $content);
        self::assertStringContainsString(' re', $content);
    }

    private function contentOf(Page $page): string
    {
        $streams = $page->contentStreams();

        if ($streams === []) {
            return '';
        }

        preg_match('/stream\n(.*)\nendstream/s', $streams[0]->render(true), $matches);

        return (string) gzuncompress($matches[1] ?? '');
    }
}
