<?php

declare(strict_types=1);

namespace MightyPDF\Tests\Layout;

use MightyPDF\Assembler\Destination;
use MightyPDF\Assembler\Document;
use MightyPDF\Assembler\Page;
use MightyPDF\Assembler\PageSize;
use MightyPDF\Assembler\Types\PdfNumberFormat;
use MightyPDF\Content\Color;
use MightyPDF\Content\Font\StandardFont;
use MightyPDF\Content\Text\TextPlacement;
use MightyPDF\Content\Text\VerticalAlign;
use MightyPDF\Layout\Flow;
use MightyPDF\Layout\Margins;
use MightyPDF\Layout\Style;
use MightyPDF\Layout\Unit;
use PHPUnit\Framework\TestCase;

/**
 * write() -- text that starts where the cursor is and leaves it at the
 * end of the last line, which is what a run of mixed styles in one
 * sentence is made of. See Flow::write().
 */
final class FlowWriteTest extends TestCase
{
    private function flow(?Document $document = null): Flow
    {
        return new Flow(
            $document ?? new Document(),
            PageSize::A4,
            new Margins(15.0, 15.0, 15.0, 15.0),
            Unit::Millimetres,
        );
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

    /** Where each Tm put the text, as [x, y] pairs in points. */
    private function textOrigins(Page $page): array
    {
        preg_match_all('/1 0 0 1 (\S+) (\S+) Tm/', $this->contentOf($page), $matches, PREG_SET_ORDER);

        return array_map(static fn (array $m): array => [(float) $m[1], (float) $m[2]], $matches);
    }

    // -- The run --------------------------------------------------------

    /**
     * The whole reason this exists next to paragraph(): a phrase in a
     * second font in the middle of a sentence, without measuring
     * anything by hand.
     */
    public function testRunsContinueOneAnotherOnTheSameLine(): void
    {
        $document = new Document();
        $flow = $this->flow($document);
        $style = new Style(StandardFont::Helvetica, 10.0);

        $flow->write('Signed under the ', $style);
        $after = $flow->x();
        $flow->write('licence', $style->with(font: StandardFont::HelveticaBold));

        $origins = $this->textOrigins($document->pages()[0]);

        self::assertCount(2, $origins);
        self::assertEqualsWithDelta($origins[0][1], $origins[1][1], 1e-5, 'one baseline, two runs');
        self::assertEqualsWithDelta($flow->toPointsX($after), $origins[1][0], 1e-5);
    }

    public function testTheCursorIsLeftAtTheEndOfTheTextRatherThanOnTheNextLine(): void
    {
        $flow = $this->flow();
        $style = new Style(StandardFont::Helvetica, 10.0);

        $flow->write('Hello', $style);

        self::assertSame(15.0, $flow->y(), 'still on the first line');
        self::assertEqualsWithDelta(
            15.0 + $flow->widthOf('Hello', $style),
            $flow->x(),
            1e-9,
        );
    }

    /**
     * preg_split() drops the spaces at the ends of a wrapped line, which
     * is right within a paragraph and would silently glue two runs
     * together.
     */
    public function testTheSpaceBetweenTwoRunsSurvives(): void
    {
        $flow = $this->flow();
        $style = new Style(StandardFont::Helvetica, 10.0);

        $flow->write('a ', $style);
        $withSpace = $flow->x();

        $bare = $this->flow();
        $bare->write('a', $style);

        self::assertEqualsWithDelta(
            $bare->x() + $flow->widthOf(' ', $style),
            $withSpace,
            1e-9,
        );
    }

    /** A line does not begin with a space, whoever asked for one. */
    public function testALeadingSpaceIsDroppedAtTheStartOfALine(): void
    {
        $flow = $this->flow();
        $style = new Style(StandardFont::Helvetica, 10.0);

        $flow->write('   indented?', $style);

        self::assertEqualsWithDelta(
            15.0 + $flow->widthOf('indented?', $style),
            $flow->x(),
            1e-9,
        );
    }

    public function testTextOfNothingButSpacesIsWidthAndNoMarks(): void
    {
        $document = new Document();
        $flow = $this->flow($document);
        $style = new Style(StandardFont::Helvetica, 10.0);

        $flow->write('word', $style);
        $before = $flow->x();
        $flow->write('  ', $style);

        self::assertEqualsWithDelta($before + 2 * $flow->widthOf(' ', $style), $flow->x(), 1e-9);
        self::assertCount(1, $this->textOrigins($document->pages()[0]));
    }

    // -- Wrapping -------------------------------------------------------

    public function testARunWrapsAtTheRightMarginAndContinuesAtTheLeft(): void
    {
        $document = new Document();
        $flow = $this->flow($document);
        $style = new Style(StandardFont::Helvetica, 10.0);

        $flow->write(str_repeat('wrap this text ', 40), $style);

        $origins = $this->textOrigins($document->pages()[0]);

        self::assertGreaterThan(1, count($origins));

        // Every line after the first starts at the left margin, and each
        // is one line height below the one before it.
        foreach (array_slice($origins, 1) as $index => $origin) {
            self::assertEqualsWithDelta($flow->toPointsX(15.0), $origin[0], 1e-5);
            self::assertEqualsWithDelta(
                $origins[$index][1] - Unit::Millimetres->toPoints(
                    Unit::Millimetres->fromPoints($style->lineHeightPt()),
                ),
                $origin[1],
                1e-5,
            );
        }
    }

    /**
     * The ragged first line: a word that does not fit what is left of a
     * line, but would fit a whole one, takes the whole one rather than
     * running past the margin.
     */
    public function testAWordThatDoesNotFitTheSpaceLeftStartsTheNextLine(): void
    {
        $document = new Document();
        $flow = $this->flow($document);
        $style = new Style(StandardFont::Helvetica, 10.0);

        // 180mm of body: park the cursor 20mm from the right margin and
        // write a word wider than that but narrower than the body.
        $flow->moveTo(175.0, 15.0);
        $flow->write('unmistakably', $style);

        $origins = $this->textOrigins($document->pages()[0]);

        self::assertCount(1, $origins);
        self::assertEqualsWithDelta($flow->toPointsX(15.0), $origins[0][0], 1e-5, 'moved down, not past the margin');
        self::assertSame(15.0, round($flow->y() - Unit::Millimetres->fromPoints($style->lineHeightPt()), 6));
    }

    public function testANewlineStartsALineAtTheLeftMarginWhereverTheCursorWas(): void
    {
        $document = new Document();
        $flow = $this->flow($document);
        $style = new Style(StandardFont::Helvetica, 10.0);

        $flow->write("first\nsecond", $style);

        $origins = $this->textOrigins($document->pages()[0]);

        self::assertCount(2, $origins);
        self::assertEqualsWithDelta($flow->toPointsX(15.0), $origins[1][0], 1e-5);
    }

    /**
     * A run and a cell of the same height sit on one baseline, because
     * both ask TextPlacement rather than either guessing.
     */
    public function testARunSitsOnTheSameBaselineAsACellOfTheSameHeight(): void
    {
        $document = new Document();
        $flow = $this->flow($document);
        $style = new Style(StandardFont::Helvetica, 12.0, valign: VerticalAlign::CapMiddle, paddingPt: 0.0);
        $height = Unit::Millimetres->fromPoints($style->lineHeightPt());

        $flow->write('run', $style);

        $expected = TextPlacement::baselineY(
            StandardFont::Helvetica,
            12.0,
            $flow->toPointsY(15.0 + $height),
            $style->lineHeightPt(),
            VerticalAlign::CapMiddle,
        );

        self::assertEqualsWithDelta($expected, $this->textOrigins($document->pages()[0])[0][1], 1e-5);
    }

    // -- Page breaks ----------------------------------------------------

    public function testARunBreaksThePageBetweenItsLines(): void
    {
        $flow = $this->flow();
        $style = new Style(StandardFont::Helvetica, 10.0);

        $flow->moveTo(15.0, 275.0);
        $flow->write(str_repeat('over the edge ', 20), $style);

        self::assertSame(2, $flow->pageCount());
    }

    // -- Links ----------------------------------------------------------

    public function testALinkedRunIsClickableOnEveryLineItWrapsOnto(): void
    {
        $flow = $this->flow();
        $style = new Style(StandardFont::Helvetica, 10.0, Color::fromHex('#1d4ed8'));

        $flow->write(str_repeat('a linked phrase ', 30), $style, link: 'https://example.com/terms');

        $bytes = $flow->save();

        self::assertGreaterThan(
            1,
            substr_count($bytes, '(https://example.com/terms)'),
            'one rectangle per line',
        );
    }

    public function testARunCanPointIntoTheDocumentInstead(): void
    {
        $document = new Document();
        $flow = $this->flow($document);

        $flow->newPage();
        $target = $document->pages()[1];
        $flow->moveTo(15.0, 15.0);

        $flow->write('see appendix', destination: Destination::fitPage($target));

        self::assertStringContainsString('/Dest', $flow->save());
    }

    /**
     * The layout layer's own link: the same rectangle the content layer
     * has always taken, in millimetres from the top-left.
     */
    public function testALinkRectangleIsConvertedFromTheFlowsCoordinates(): void
    {
        $flow = $this->flow();

        $flow->link(20.0, 30.0, 60.0, 10.0, 'https://example.com/');

        $rect = sprintf(
            '/Rect [%s %s %s %s]',
            PdfNumberFormat::format($flow->toPointsX(20.0)),
            PdfNumberFormat::format($flow->toPointsY(40.0)),
            PdfNumberFormat::format($flow->toPointsX(80.0)),
            PdfNumberFormat::format($flow->toPointsY(30.0)),
        );

        self::assertStringContainsString($rect, $flow->save());
    }

    public function testACellCanBeClickableWholeRatherThanItsTextOnly(): void
    {
        $flow = $this->flow();

        $flow->cell(60.0, 8.0, 'Terms of service', link: 'https://example.com/tos');

        self::assertStringContainsString('(https://example.com/tos)', $flow->save());
    }

    /** Nothing here consults the clock, this included. */
    public function testTheSameRunsBuiltTwiceAreTheSameBytes(): void
    {
        $build = function (): string {
            $flow = $this->flow();
            $style = new Style(StandardFont::Helvetica, 10.0);

            $flow->write('Signed under the terms of the ', $style)
                ->write('licence', $style->with(color: Color::fromHex('#1d4ed8')), link: 'https://example.com/')
                ->write(', which is incorporated by reference. ', $style)
                ->write(str_repeat('And a good deal more text besides. ', 30), $style);

            return $flow->save();
        };

        self::assertSame($build(), $build());
    }
}
