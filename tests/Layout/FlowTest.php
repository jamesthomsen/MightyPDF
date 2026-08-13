<?php

declare(strict_types=1);

namespace MightyPDF\Tests\Layout;

use MightyPDF\Assembler\Document;
use MightyPDF\Assembler\PageSize;
use MightyPDF\Assembler\Types\PdfNumberFormat;
use MightyPDF\Assembler\Types\PdfRectangle;
use MightyPDF\Assembler\Page;
use MightyPDF\Content\Color;
use MightyPDF\Content\Font\EmbeddedFont;
use MightyPDF\Content\Font\StandardFont;
use MightyPDF\Content\Font\TrueType\FontException;
use MightyPDF\Content\Text\HorizontalAlign;
use MightyPDF\Content\Text\TextPlacement;
use MightyPDF\Content\Text\VerticalAlign;
use MightyPDF\Layout\Border;
use MightyPDF\Layout\Flow;
use MightyPDF\Layout\Margins;
use MightyPDF\Layout\MissingGlyphs;
use MightyPDF\Layout\Style;
use MightyPDF\Layout\Unit;
use MightyPDF\Tests\Support\SyntheticTrueTypeFont;
use PHPUnit\Framework\TestCase;

final class FlowTest extends TestCase
{
    private function flow(
        ?Document $document = null,
        Margins $margins = new Margins(15.0, 15.0, 15.0, 15.0),
        bool $autoPageBreak = true,
    ): Flow {
        return new Flow($document ?? new Document(), PageSize::A4, $margins, Unit::Millimetres, $autoPageBreak);
    }

    /** A font with glyphs for "A", "B", "?" and a space, and nothing else. */
    private static function narrowFont(): EmbeddedFont
    {
        return EmbeddedFont::fromBytes(SyntheticTrueTypeFont::build([
            0x41 => SyntheticTrueTypeFont::GLYPH_A,
            0x42 => SyntheticTrueTypeFont::GLYPH_B,
            0x20 => SyntheticTrueTypeFont::GLYPH_SPACE,
            0x3F => SyntheticTrueTypeFont::GLYPH_ACUTE,
        ]), subset: false);
    }

    /**
     * A page's operators. Compression happens when the stream renders,
     * so this goes through render() rather than reading the bytes that
     * were appended -- the same route PageBuilderTest takes.
     */
    private function contentOf(Page $page): string
    {
        $streams = $page->contentStreams();

        if ($streams === []) {
            return '';
        }

        preg_match('/stream\n(.*)\nendstream/s', $streams[0]->render(true), $matches);

        return (string) gzuncompress($matches[1] ?? '');
    }

    // -- Coordinates ----------------------------------------------------

    /**
     * The flip from top-left/Y-down to PDF's bottom-left/Y-up, which is
     * the conversion every consumer was writing by hand.
     */
    public function testMillimetresFromTheTopLeftBecomePointsFromTheBottomLeft(): void
    {
        $flow = $this->flow();

        self::assertEqualsWithDelta(0.0, $flow->toPointsX(0.0), 1e-9);
        self::assertEqualsWithDelta(28.3465, $flow->toPointsX(10.0), 1e-4);

        // A4 is 841.89pt tall, so 10mm down from the top is 813.5pt up.
        self::assertEqualsWithDelta(841.89, $flow->toPointsY(0.0), 1e-9);
        self::assertEqualsWithDelta(813.54, $flow->toPointsY(10.0), 1e-2);
    }

    public function testPageAndContentGeometryAreReportedInTheFlowsUnit(): void
    {
        $flow = $this->flow(margins: new Margins(20.0, 15.0, 25.0, 10.0));

        self::assertEqualsWithDelta(210.0, $flow->pageWidth(), 1e-2);
        self::assertEqualsWithDelta(297.0, $flow->pageHeight(), 1e-2);
        self::assertEqualsWithDelta(185.0, $flow->contentWidth(), 1e-2);
        self::assertEqualsWithDelta(252.0, $flow->contentHeight(), 1e-2);
    }

    public function testInchesAndPointsAreTheSameGeometryStatedDifferently(): void
    {
        $inches = new Flow(new Document(), PageSize::Letter, Margins::uniform(1.0), Unit::Inches);
        $points = new Flow(new Document(), PageSize::Letter, Margins::uniform(72.0), Unit::Points);

        self::assertEqualsWithDelta($inches->toPointsY(2.0), $points->toPointsY(144.0), 1e-9);
        self::assertEqualsWithDelta($inches->contentWidth() * 72.0, $points->contentWidth(), 1e-9);
    }

    // -- Cursor ---------------------------------------------------------

    public function testTheCursorStartsAtTheTopLeftOfTheContentArea(): void
    {
        $flow = $this->flow(margins: new Margins(20.0, 15.0, 25.0, 10.0));

        self::assertSame(10.0, $flow->x());
        self::assertSame(20.0, $flow->y());
    }

    public function testCellsAdvanceAcrossAndNewLineReturnsToTheMargin(): void
    {
        $flow = $this->flow();

        $flow->cell(40.0, 8.0, 'a')->cell(30.0, 8.0, 'b');
        self::assertSame(85.0, $flow->x());
        self::assertSame(15.0, $flow->y());

        $flow->newLine(8.0);
        self::assertSame(15.0, $flow->x());
        self::assertSame(23.0, $flow->y());
    }

    public function testMoveToPlacesTheCursorOutright(): void
    {
        $flow = $this->flow()->moveTo(40.0, 120.0);

        self::assertSame(40.0, $flow->x());
        self::assertSame(120.0, $flow->y());
    }

    // -- Page breaks ----------------------------------------------------

    public function testACellThatWouldCrossTheBottomMarginStartsANewPage(): void
    {
        $flow = $this->flow();

        // A4 body runs to y = 282mm; 40 rows of 8mm from y = 15 does not fit.
        for ($i = 0; $i < 40; $i++) {
            $flow->cell(50.0, 8.0, "row $i")->newLine(8.0);
        }

        self::assertSame(2, $flow->pageCount());
        self::assertSame(2, $flow->pageNumber());
    }

    public function testANewPageResetsTheCursorToTheTopMarginRatherThanKeepingY(): void
    {
        $flow = $this->flow();
        $flow->moveTo(15.0, 270.0);

        $flow->cell(50.0, 30.0, 'too tall for what is left');

        self::assertSame(2, $flow->pageCount());
        self::assertSame(15.0, $flow->y(), 'the cursor should be at the top margin of the new page');
    }

    /**
     * An element taller than the page body is drawn where it is rather
     * than breaking forever. It overflows visibly, which someone can
     * see; an infinite break is a hang they cannot diagnose.
     */
    public function testAnElementTallerThanThePageDoesNotBreakForever(): void
    {
        $flow = $this->flow();

        $flow->cell(50.0, 500.0, 'taller than A4');

        self::assertSame(1, $flow->pageCount());
    }

    public function testAutoPageBreakCanBeTurnedOff(): void
    {
        $flow = $this->flow(autoPageBreak: false);

        for ($i = 0; $i < 60; $i++) {
            $flow->cell(50.0, 8.0, "row $i")->newLine(8.0);
        }

        self::assertSame(1, $flow->pageCount());
    }

    public function testWillFitAnswersAgainstTheBottomMargin(): void
    {
        $flow = $this->flow();
        $flow->moveTo(15.0, 275.0);

        self::assertTrue($flow->willFit(7.0));
        self::assertFalse($flow->willFit(8.0));
    }

    /**
     * Exposed so a caller drawing something this layer knows nothing
     * about -- a chart, a signature block -- takes part in the same
     * decision instead of reimplementing it.
     */
    public function testBreakIfNeededLetsCustomDrawingJoinIn(): void
    {
        $flow = $this->flow();
        $flow->moveTo(15.0, 250.0);

        $flow->breakIfNeeded(60.0);

        self::assertSame(2, $flow->pageCount());
    }

    // -- Page sizes -----------------------------------------------------

    /**
     * The whole point of a per-page size: the geometry every other call
     * here is measured against follows the page being drawn on, so a
     * landscape insert has landscape margins and a landscape body.
     */
    public function testAPageOfItsOwnSizeMovesEveryMeasurementWithIt(): void
    {
        $flow = $this->flow();

        self::assertEqualsWithDelta(210.0, $flow->pageWidth(), 1e-2);

        $flow->newPage(PageSize::A4->landscape());

        self::assertEqualsWithDelta(297.0, $flow->pageWidth(), 1e-2);
        self::assertEqualsWithDelta(210.0, $flow->pageHeight(), 1e-2);
        self::assertEqualsWithDelta(267.0, $flow->contentWidth(), 1e-2);
        self::assertEqualsWithDelta(195.0, $flow->bottomLimit(), 1e-2);
    }

    /** The Y flip counts from the top of *this* sheet. */
    public function testCoordinatesConvertAgainstThePageBeingDrawnOn(): void
    {
        $flow = $this->flow();

        self::assertEqualsWithDelta(841.89, $flow->toPointsY(0.0), 1e-2);

        $flow->newPage(PageSize::A4->landscape());

        self::assertEqualsWithDelta(595.28, $flow->toPointsY(0.0), 1e-2);
    }

    public function testEachPageIsWrittenWithItsOwnMediaBox(): void
    {
        $document = new Document();
        $flow = $this->flow($document);

        $flow->newPage(PageSize::A5);

        $bytes = $flow->save();

        self::assertStringContainsString('/MediaBox [0 0 595.28 841.89]', $bytes);
        self::assertStringContainsString('/MediaBox [0 0 419.53 595.28]', $bytes);
    }

    /**
     * A run of rows that started on a wide sheet was measured against
     * it, so continuing on a narrower one would overflow columns that
     * were correct when they were sized.
     */
    public function testAnAutomaticBreakContinuesAtTheSizeOfThePageItLeaves(): void
    {
        $flow = $this->flow();

        $flow->newPage(PageSize::A4->landscape());

        for ($i = 0; $i < 40; $i++) {
            $flow->cell(50.0, 8.0, "row $i")->newLine(8.0);
        }

        self::assertSame(3, $flow->pageCount());
        self::assertEqualsWithDelta(297.0, $flow->pageWidth(), 1e-2, 'the break should stay landscape');
    }

    /** Where an explicit one goes back to the document's own default. */
    public function testAnExplicitNewPageWithNoSizeIsTheFlowsDefault(): void
    {
        $flow = $this->flow();

        $flow->newPage(PageSize::A4->landscape())->newPage();

        self::assertEqualsWithDelta(210.0, $flow->pageWidth(), 1e-2);
    }

    /** Hooks hop across pages, and each page's furniture is its own size. */
    public function testAPerPageHookSeesTheGeometryOfThePageItIsDrawingOn()
    : void {
        $flow = $this->flow();
        $flow->newPage(PageSize::A4->landscape());

        $widths = [];
        $flow->onEachPage(function (Flow $flow) use (&$widths): void {
            $widths[] = round($flow->pageWidth(), 1);
        });

        $flow->finish();

        self::assertSame([210.0, 297.0], $widths);
    }

    // -- Taking over the break ------------------------------------------

    /**
     * A page with two columns on it, which is the thing this hook exists
     * for: the first break moves across, the second lets the page turn.
     */
    public function testAPageBreakHookCanMoveAcrossInsteadOfTurningThePage(): void
    {
        $flow = $this->flow();
        $column = 0;

        $flow->onPageBreak(function (Flow $flow) use (&$column): bool {
            $column = 1 - $column;
            $left = $column === 0 ? 15.0 : 110.0;

            $flow->setMargins($flow->margins()->with(left: $left));

            if ($column === 0) {
                return true;
            }

            $flow->moveTo($left, $flow->margins()->top);

            return false;
        });

        for ($i = 0; $i < 40; $i++) {
            $flow->cell(80.0, 8.0, "row $i")->newLine(8.0);
        }

        self::assertSame(1, $flow->pageCount(), 'the first overflow should fill the second column');
        self::assertSame(110.0, $flow->x(), 'newLine() should return to the column, not the page margin');
    }

    /** Two columns and then the page, rather than two columns forever. */
    public function testASecondFullColumnLetsThePageTurnAndResetsToTheFirst(): void
    {
        $flow = $this->flow();
        $column = 0;

        $flow->onPageBreak(function (Flow $flow) use (&$column): bool {
            $column = 1 - $column;
            $left = $column === 0 ? 15.0 : 110.0;

            $flow->setMargins($flow->margins()->with(left: $left));

            if ($column === 0) {
                return true;
            }

            $flow->moveTo($left, $flow->margins()->top);

            return false;
        });

        // 33 rows to a column, so 80 rows is two full columns and a
        // little of a third -- which has to be on a second page.
        for ($i = 0; $i < 80; $i++) {
            $flow->cell(80.0, 8.0, "row $i")->newLine(8.0);
        }

        self::assertSame(2, $flow->pageCount());
        self::assertSame(15.0, $flow->x(), 'a new page starts in the first column again');
    }

    public function testAHookThatReturnsTrueBreaksExactlyAsBefore(): void
    {
        $flow = $this->flow();
        $seen = [];

        $flow->onPageBreak(function (Flow $flow, float $height) use (&$seen): bool {
            $seen[] = $height;

            return true;
        });

        $flow->moveTo(15.0, 275.0);
        $flow->cell(50.0, 20.0, 'over the limit');

        self::assertSame([20.0], $seen, 'the hook is told what would not fit');
        self::assertSame(2, $flow->pageCount());
    }

    /**
     * A hook positions itself by drawing as often as by moveTo() -- a
     * column heading, a rule -- and those call back into the same
     * decision. Without the guard, a hook that draws near the bottom of
     * the page asks itself whether to break, forever.
     */
    public function testAHookThatDrawsWhileDecidingDoesNotRecurse(): void
    {
        $flow = $this->flow();
        $calls = 0;

        $flow->onPageBreak(function (Flow $flow) use (&$calls): bool {
            $calls++;
            $flow->cell(50.0, 40.0, 'a column heading, drawn at the bottom of the page');

            return true;
        });

        $flow->moveTo(15.0, 275.0);
        $flow->cell(50.0, 20.0, 'over the limit');

        self::assertSame(1, $calls);
    }

    /** newPage() is an instruction, not a question. */
    public function testAHookIsNotConsultedAboutAnExplicitNewPage(): void
    {
        $flow = $this->flow();
        $calls = 0;

        $flow->onPageBreak(function () use (&$calls): bool {
            $calls++;

            return false;
        });

        $flow->newPage();

        self::assertSame(0, $calls);
        self::assertSame(2, $flow->pageCount());
    }

    /**
     * A footer describes the page. A document that ended halfway down a
     * second column would otherwise print every one of them against the
     * column's left edge, on pages that never had a column on them.
     */
    public function testPerPageHooksRunAgainstTheMarginsTheFlowWasBuiltWith(): void
    {
        $flow = $this->flow();
        $seen = [];

        $flow->setMargins($flow->margins()->with(left: 110.0));
        $flow->onEachPage(function (Flow $flow) use (&$seen): void {
            $seen[] = $flow->margins()->left;
        });

        $flow->finish();

        self::assertSame([15.0], $seen);
        self::assertSame(110.0, $flow->margins()->left, 'and the caller keeps what it set');
    }

    public function testAHookCanBeTakenBackOffAgain(): void
    {
        $flow = $this->flow();

        $flow->onPageBreak(fn (): bool => false);
        $flow->moveTo(15.0, 275.0);
        $flow->cell(50.0, 20.0, 'refused');

        self::assertSame(1, $flow->pageCount());

        $flow->onPageBreak(null);
        $flow->moveTo(15.0, 275.0);
        $flow->cell(50.0, 20.0, 'allowed');

        self::assertSame(2, $flow->pageCount());
    }

    // -- Per-page hooks -------------------------------------------------

    public function testAHookRunsOncePerPageInPageOrder(): void
    {
        $flow = $this->flow();
        $seen = [];

        $flow->onEachPage(function (Flow $flow, int $number, int $total) use (&$seen): void {
            $seen[] = [$number, $total];
        });

        $flow->newPage()->newPage();
        $flow->finish();

        self::assertSame([[1, 3], [2, 3], [3, 3]], $seen);
    }

    /**
     * The guarantee the layer exists to provide: a disclaimer lands on
     * pages the caller never asked for, because an automatic break made
     * them in the middle of a table.
     */
    public function testAHookCoversPagesTheAutoBreakCreated(): void
    {
        $flow = $this->flow();
        $decorated = [];

        $flow->onEachPage(function (Flow $flow, int $number) use (&$decorated): void {
            $decorated[] = $number;
        });

        for ($i = 0; $i < 80; $i++) {
            $flow->cell(50.0, 8.0, "row $i")->newLine(8.0);
        }

        $flow->finish();

        self::assertSame(3, $flow->pageCount());
        self::assertSame([1, 2, 3], $decorated);
    }

    /**
     * "Page 1 of 7" needs the total, which is not known while page one
     * is being drawn. Deferring the hooks to finish() is what makes it
     * simply true rather than a placeholder substituted afterwards.
     */
    public function testTheTotalIsFinalEvenForTheFirstPage(): void
    {
        $flow = $this->flow();
        $totals = [];

        $flow->onEachPage(function (Flow $flow, int $number, int $total) use (&$totals): void {
            $totals[] = $total;
        });

        $flow->newPage()->newPage()->newPage();
        $flow->finish();

        self::assertSame([4, 4, 4, 4], $totals);
    }

    public function testHooksRunInRegistrationOrderWithinEachPage(): void
    {
        $flow = $this->flow();
        $order = [];

        $flow->onEachPage(function (Flow $flow, int $number) use (&$order): void {
            $order[] = "header $number";
        });
        $flow->onEachPage(function (Flow $flow, int $number) use (&$order): void {
            $order[] = "footer $number";
        });

        $flow->newPage()->finish();

        self::assertSame(['header 1', 'footer 1', 'header 2', 'footer 2'], $order);
    }

    public function testAHookIsHandedAFlowPointedAtThePageBeingDecorated(): void
    {
        $flow = $this->flow();
        $numbers = [];

        $flow->onEachPage(function (Flow $flow, int $number) use (&$numbers): void {
            $numbers[] = $flow->pageNumber();
            // Drawn through the same millimetre space as everything else.
            $flow->cellAt(15.0, 282.0, 180.0, 5.0, "Page $number");
        });

        $flow->newPage()->finish();

        self::assertSame([1, 2], $numbers);

        foreach ($flow->document()->pages() as $index => $page) {
            self::assertStringContainsString('Page ' . ($index + 1), $this->contentOf($page));
        }
    }

    public function testTheCursorSurvivesTheHooks(): void
    {
        $flow = $this->flow();
        $flow->onEachPage(fn (Flow $flow) => $flow->moveTo(99.0, 99.0));

        $flow->moveTo(40.0, 60.0)->finish();

        self::assertSame(40.0, $flow->x());
        self::assertSame(60.0, $flow->y());
    }

    /** Otherwise save() after finish() would print a second footer over the first. */
    public function testFinishingTwiceDrawsTheFurnitureOnce(): void
    {
        $flow = $this->flow();
        $runs = 0;
        $flow->onEachPage(function () use (&$runs): void {
            $runs++;
        });

        $flow->finish();
        $flow->finish();
        $flow->save();

        self::assertSame(1, $runs);
    }

    /**
     * A page added by a hook would be undecorated, and every "of N"
     * already drawn would be wrong -- neither of which shows up until
     * someone reads the last page of a long document.
     */
    public function testAHookThatAddsAPageIsRefusedRatherThanHalfHonoured(): void
    {
        $flow = $this->flow();
        $flow->onEachPage(fn (Flow $flow) => $flow->newPage());

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('added a page');

        $flow->finish();
    }

    // -- Drawing --------------------------------------------------------

    /**
     * A cell places its text by the same rule as everything else, so
     * this checks the whole chain -- millimetres, padding, the Y flip
     * and TextPlacement -- lands on the baseline the maths says.
     */
    public function testACellPlacesItsTextOnTheBaselineTextPlacementGives(): void
    {
        $document = new Document();
        $flow = $this->flow($document);
        $style = new Style(StandardFont::Helvetica, 12.0, valign: VerticalAlign::CapMiddle, paddingPt: 0.0);

        $flow->cellAt(20.0, 30.0, 60.0, 10.0, 'Grade', $style);

        $boxBottomPt = $flow->toPointsY(40.0);
        $expected = TextPlacement::baselineY(
            StandardFont::Helvetica,
            12.0,
            $boxBottomPt,
            Unit::Millimetres->toPoints(10.0),
            VerticalAlign::CapMiddle,
        );

        self::assertMatchesRegularExpression(
            '/1 0 0 1 [\d.]+ ' . preg_quote(PdfNumberFormat::format($expected), '/') . ' Tm/',
            $this->contentOf($document->pages()[0]),
        );
    }

    public function testAFilledCellPaintsItsBackgroundAndItsRules(): void
    {
        $document = new Document();
        $flow = $this->flow($document);

        $flow->cell(50.0, 8.0, 'x', new Style(fill: Color::fromRgb255(255, 0, 0), border: Border::box(0.5)));

        $content = $this->contentOf($document->pages()[0]);

        self::assertStringContainsString('1 0 0 rg', $content, 'the fill colour');
        self::assertStringContainsString(' re', $content);
        self::assertStringContainsString('0.5 w', $content, 'the rule weight');
    }

    /** One "re S" rather than four strokes -- see Flow::rect(). */
    public function testAFullBoxBorderGoesOutAsOneRectangleRatherThanFourLines(): void
    {
        $document = new Document();
        $flow = $this->flow($document);

        $flow->cell(50.0, 8.0, '', new Style(border: Border::box()));

        self::assertSame(1, substr_count($this->contentOf($document->pages()[0]), 'S'));
    }

    public function testASingleEdgeBorderDrawsOnlyThatEdge(): void
    {
        $document = new Document();
        $flow = $this->flow($document);

        $flow->cell(50.0, 8.0, '', new Style(border: Border::bottom()));

        $content = $this->contentOf($document->pages()[0]);

        self::assertSame(1, substr_count($content, " l\n"), 'one lineTo, so one edge');
    }

    /**
     * A wrapped block and a single-line cell of the same geometry agree,
     * which is what could not be done before: drawParagraph() placed by
     * ascent while cell-style layout placed by box height, so the two
     * were never on the same baseline.
     */
    public function testAOneLineParagraphAndACellAgreeOnTheBaseline(): void
    {
        $style = new Style(StandardFont::Helvetica, 11.0, valign: VerticalAlign::Middle, paddingPt: 0.0);

        $cellDocument = new Document();
        $this->flow($cellDocument)->cellAt(20.0, 30.0, 80.0, 12.0, 'One line', $style);

        $paragraphDocument = new Document();
        $paragraph = $this->flow($paragraphDocument);
        $paragraph->moveTo(20.0, 30.0);
        $paragraph->paragraph(80.0, 'One line', $style, height: 12.0);

        self::assertSame(
            $this->textMatrices($this->contentOf($cellDocument->pages()[0])),
            $this->textMatrices($this->contentOf($paragraphDocument->pages()[0])),
        );
    }

    public function testParagraphHeightGrowsWithTheWrappedLineCount(): void
    {
        $flow = $this->flow();
        $style = new Style(StandardFont::Helvetica, 10.0, paddingPt: 0.0);

        $one = $flow->paragraphHeight(180.0, 'Short.', $style);
        $many = $flow->paragraphHeight(180.0, str_repeat('Rather more text than that. ', 20), $style);

        self::assertGreaterThan($one * 5, $many);
    }

    public function testParagraphAdvancesTheCursorByItsHeightAndReturnsToTheMargin(): void
    {
        $flow = $this->flow();
        $style = new Style(StandardFont::Helvetica, 10.0, paddingPt: 0.0);
        $text = str_repeat('Some flowing text. ', 10);

        $flow->cell(40.0, 8.0, 'lead-in');
        $height = $flow->paragraphHeight(180.0, $text, $style);
        $flow->paragraph(180.0, $text, $style);

        self::assertSame(15.0, $flow->x());
        self::assertEqualsWithDelta(15.0 + $height, $flow->y(), 1e-9);
    }

    public function testAlignmentReachesTheContentStream(): void
    {
        $document = new Document();
        $flow = $this->flow($document);
        $style = new Style(StandardFont::Courier, 10.0, align: HorizontalAlign::Right, paddingPt: 0.0);

        $flow->cellAt(0.0, 10.0, 20.0, 8.0, 'Hi', $style);

        // "Hi" is 12pt wide in Courier at 10pt; a 20mm (56.69pt) box puts
        // its left edge at 44.69pt.
        self::assertStringContainsString('1 0 0 1 44.69', $this->contentOf($document->pages()[0]));
    }

    // -- Output ---------------------------------------------------------

    /**
     * The property consumers snapshot-test against. Nothing in this
     * layer may consult the clock or a random source, and there is
     * deliberately no automatic /CreationDate anywhere in the library.
     */
    public function testTheSameDocumentBuiltTwiceIsTheSameBytes(): void
    {
        $build = function (): string {
            $flow = $this->flow();
            $flow->onEachPage(fn (Flow $flow, int $n, int $total) => $flow->cellAt(15.0, 282.0, 180.0, 5.0, "Page $n of $total"));

            for ($i = 0; $i < 50; $i++) {
                $flow->cell(60.0, 8.0, "row $i", new Style(fill: Color::gray(0.9)))->newLine(8.0);
            }

            return $flow->save();
        };

        self::assertSame($build(), $build());
    }

    public function testSaveFinishesTheDocumentFirst(): void
    {
        $flow = $this->flow();
        $flow->onEachPage(fn (Flow $flow) => $flow->cellAt(15.0, 282.0, 180.0, 5.0, 'DRAFT'));

        $bytes = $flow->save();

        self::assertStringContainsString('%PDF-1.7', $bytes);
        self::assertStringContainsString('DRAFT', $this->contentOf($flow->document()->pages()[0]));
    }

    public function testTheDocumentIsReachableForAnythingThisLayerDoesNotCover(): void
    {
        $document = new Document();
        $flow = $this->flow($document);

        self::assertSame($document, $flow->document());
        self::assertSame($document->pages()[0], $document->pages()[0]);
    }

    /**
     * Saving through the Document runs the per-page hooks too.
     *
     * document() is a documented escape hatch, so reaching it and saving
     * is a thing callers do. Before the hooks were registered with the
     * Document itself, that produced a file with no page numbers and no
     * footer -- silently, since a missing footer looks exactly like a
     * document that never had one.
     */
    public function testSavingThroughTheDocumentStillDecoratesEveryPage(): void
    {
        $viaDocument = $this->numberedFlow()->document()->save();
        $viaFlow = $this->numberedFlow()->save();

        self::assertSame($viaFlow, $viaDocument);
    }

    public function testSavingTwiceThroughEitherPathDrawsOneSetOfFooters(): void
    {
        $flow = $this->numberedFlow();

        $first = $flow->save();

        self::assertSame($first, $flow->save());
        self::assertSame($first, $flow->document()->save());
        self::assertSame(1, substr_count($this->contentOf($flow->document()->pages()[0]), 'Page 1 of 1'));
    }

    public function testFinishingExplicitlyBeforeSavingChangesNothing(): void
    {
        $flow = $this->numberedFlow();
        $flow->finish();

        self::assertSame($this->numberedFlow()->save(), $flow->document()->save());
    }

    /**
     * A hook that throws leaves finish() able to run again.
     *
     * The flag that says "already decorated" is set once the hooks have
     * all returned, not before: setting it first marks a half-decorated
     * document as done, and the caller's retry then hands back the file
     * it was trying not to produce.
     */
    public function testAHookThatThrowsLeavesTheDocumentUnfinished(): void
    {
        $calls = 0;
        $flow = $this->flow();
        $flow->onEachPage(function () use (&$calls): void {
            $calls++;

            if ($calls === 1) {
                throw new \RuntimeException('the hook failed');
            }
        });

        try {
            $flow->finish();
            self::fail('The hook should have thrown.');
        } catch (\RuntimeException) {
        }

        $flow->finish();

        self::assertSame(2, $calls);
    }

    /**
     * And keeps refusing: the page-count check runs before the document
     * is marked finished, so catching it and saving anyway throws again
     * rather than quietly returning the document it just refused.
     */
    public function testAHookThatAddsAPageIsRefusedEveryTime(): void
    {
        $flow = $this->flow();
        $flow->onEachPage(fn (Flow $flow, int $number) => $number === 1 ? $flow->newPage() : null);

        foreach (range(1, 2) as $attempt) {
            $this->expectExceptionOnAttempt($flow, $attempt);
        }
    }

    private function expectExceptionOnAttempt(Flow $flow, int $attempt): void
    {
        try {
            $flow->save();
            self::fail("Attempt $attempt should have been refused.");
        } catch (\LogicException $e) {
            self::assertStringContainsString('added a page', $e->getMessage());
        }
    }

    private function numberedFlow(): Flow
    {
        $flow = $this->flow();
        $flow->onEachPage(
            fn (Flow $flow, int $number, int $total) => $flow->textAt(15.0, 285.0, "Page $number of $total"),
        );

        return $flow;
    }

    /**
     * A media box written with its corners the other way round is the
     * same rectangle -- §7.9.5 -- and readers normalize it. This layer
     * reads the corners rather than the extent, so it has to as well, or
     * a document laid out on one lands off the sheet.
     */
    public function testAnInvertedMediaBoxLaysOutTheSameWayUpAsAnUprightOne(): void
    {
        $upright = new Flow(new Document(), new PdfRectangle(0.0, 0.0, 595.28, 841.89));
        $inverted = new Flow(new Document(), new PdfRectangle(595.28, 841.89, 0.0, 0.0));

        self::assertEqualsWithDelta($upright->toPointsY(20.0), $inverted->toPointsY(20.0), 1e-9);
        self::assertEqualsWithDelta($upright->toPointsX(20.0), $inverted->toPointsX(20.0), 1e-9);
        self::assertEqualsWithDelta($upright->pageHeight(), $inverted->pageHeight(), 1e-9);
    }

    /**
     * paragraph() sizes its box from the string it is going to draw.
     *
     * It substitutes the text once now rather than once to measure and
     * again to draw. Both passes produced the same string, so this was
     * never visibly wrong -- which is exactly why it is worth pinning:
     * the two are the same string by construction rather than by
     * coincidence, and the cursor lands where the measurement said.
     */
    public function testAParagraphIsSizedFromTheTextItActuallyDraws(): void
    {
        $flow = new Flow(
            new Document(),
            PageSize::A4,
            new Margins(15.0, 15.0, 15.0, 15.0),
            Unit::Millimetres,
            missingGlyphs: MissingGlyphs::Substitute,
        );

        $style = new Style(self::narrowFont(), 12.0);
        $expected = $flow->paragraphHeight(100.0, 'AB ÁB AB', $style);

        $top = $flow->y();
        $flow->paragraph(100.0, 'AB ÁB AB', $style);

        self::assertEqualsWithDelta($expected, $flow->y() - $top, 1e-9);

        // "Á" reaches the page as "A" -- the font has no acute, so the
        // fallback ladder gets there through ASCII//TRANSLIT. Written
        // out as glyph ids, that is A B space A B space A B.
        self::assertStringContainsString(
            '<00410042002000410042002000410042>',
            $this->contentOf($flow->document()->pages()[0]),
        );
    }

    // -- Text a font cannot set -----------------------------------------

    /**
     * The default is the library's default: an embedded font refuses to
     * draw a character it has no glyph for, rather than drawing a blank
     * box for it.
     */
    public function testByDefaultAFontThatCannotSetTheTextStillRefuses(): void
    {
        $flow = $this->flow();
        $style = new Style(self::narrowFont(), 10.0);

        $this->expectException(FontException::class);

        $flow->cell(50.0, 8.0, 'Zoë', $style);
    }

    /**
     * The setting for a document assembled from names other people
     * typed: the character nobody anticipated degrades instead of
     * failing the whole request.
     */
    public function testSubstituteDrawsAnApproximationInsteadOfFailingTheDocument(): void
    {
        $document = new Document();
        $flow = new Flow(
            $document,
            PageSize::A4,
            Margins::uniform(15.0),
            Unit::Millimetres,
            missingGlyphs: MissingGlyphs::Substitute,
        );

        $flow->cell(50.0, 8.0, 'Zoë', new Style(self::narrowFont(), 10.0));

        self::assertNotSame('', $this->contentOf($document->pages()[0]));
    }

    /**
     * Widths have to be measured on what will actually be drawn.
     * Measuring the original would centre and right-align text by the
     * size of characters that were replaced.
     */
    public function testWidthIsMeasuredOnTheTextThatWillActuallyBeDrawn(): void
    {
        $style = new Style(self::narrowFont(), 10.0);

        $refusing = $this->flow();
        $substituting = new Flow(
            new Document(),
            PageSize::A4,
            Margins::uniform(15.0),
            Unit::Millimetres,
            missingGlyphs: MissingGlyphs::Substitute,
        );

        // "?" is a narrower glyph than "B" in the synthetic font, so the
        // substituted string measures differently from the original.
        self::assertNotEqualsWithDelta(
            $refusing->widthOf('BZB', $style),
            $substituting->widthOf('BZB', $style),
            1e-9,
        );
    }

    // -- Measurement and placement --------------------------------------

    public function testWidthOfAnswersInTheFlowsUnit(): void
    {
        $flow = $this->flow();
        $style = new Style(StandardFont::Courier, 10.0);

        // Courier is a flat 600/1000 em, so "Hi" is 12pt -- 4.233mm.
        self::assertEqualsWithDelta(4.233, $flow->widthOf('Hi', $style), 1e-3);
    }

    public function testRemainingWidthIsWhatIsLeftBeforeTheRightMargin(): void
    {
        $flow = $this->flow();

        self::assertEqualsWithDelta(180.0, $flow->remainingWidth(), 1e-2);

        $flow->cell(60.0, 8.0);

        self::assertEqualsWithDelta(120.0, $flow->remainingWidth(), 1e-2);
    }

    /** The case a box does not describe: a baseline pinned to a rule. */
    public function testTextAtPlacesABaselineOutright(): void
    {
        $document = new Document();
        $flow = $this->flow($document);

        $flow->textAt(20.0, 50.0, 'Pinned');

        self::assertStringContainsString(
            sprintf('1 0 0 1 %s %s Tm', PdfNumberFormat::format($flow->toPointsX(20.0)), PdfNumberFormat::format($flow->toPointsY(50.0))),
            $this->contentOf($document->pages()[0]),
        );
    }

    public function testTextAtLeavesTheCursorAlone(): void
    {
        $flow = $this->flow()->moveTo(40.0, 60.0);

        $flow->textAt(10.0, 10.0, 'elsewhere');

        self::assertSame(40.0, $flow->x());
        self::assertSame(60.0, $flow->y());
    }

    public function testAnImageWithAnUnknownExtensionIsRefusedClearly(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Cannot tell the format');

        $this->flow()->image('/tmp/logo.tiff', 10.0, 10.0, 20.0, 20.0);
    }

    /** @return list<string> */
    private function textMatrices(string $content): array
    {
        preg_match_all('/1 0 0 1 [\d.-]+ [\d.-]+ Tm/', $content, $matches);

        return $matches[0];
    }
}
