<?php

declare(strict_types=1);

namespace MightyPDF\Tests\Layout;

use MightyPDF\Assembler\Document;
use MightyPDF\Assembler\Page;
use MightyPDF\Assembler\PageSize;
use MightyPDF\Assembler\Types\PdfNumberFormat;
use MightyPDF\Content\CmykColor;
use MightyPDF\Content\Color;
use MightyPDF\Content\Dash;
use MightyPDF\Content\PathSink;
use MightyPDF\Content\SpotColor;
use MightyPDF\Content\Stroke;
use MightyPDF\Layout\Border;
use MightyPDF\Layout\Flow;
use MightyPDF\Layout\Margins;
use MightyPDF\Layout\Style;
use MightyPDF\Layout\Unit;
use PHPUnit\Framework\TestCase;

final class FlowGraphicsTest extends TestCase
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

    /**
     * A4 is 841.89pt tall, so 20mm (56.69pt) from the top is 785.2pt up
     * from the bottom -- the flip this layer exists to do, applied to a
     * shape rather than to a cell.
     */
    public function testShapesUseTheSameTopLeftCoordinatesAsCells(): void
    {
        $document = new Document();
        $flow = $this->flow($document);

        $flow->circle(30.0, 20.0, 5.0, fill: Color::black());

        $content = $this->contentOf($document->pages()[0]);

        // The path starts at the rightmost point of the circle, which is
        // centre x + radius = 35mm across and 20mm down.
        self::assertStringContainsString(
            $this->at($flow, 35.0, 20.0) . ' m',
            $content,
        );
    }

    public function testPolylineJoinsThePointsInFlowCoordinates(): void
    {
        $document = new Document();
        $flow = $this->flow($document);

        $flow->polyline([[10.0, 10.0], [20.0, 30.0]], new Stroke(Color::black(), 1.0));

        $content = $this->contentOf($document->pages()[0]);

        self::assertStringContainsString($this->at($flow, 10.0, 10.0) . ' m', $content);
        self::assertStringContainsString($this->at($flow, 20.0, 30.0) . ' l', $content);
        self::assertStringContainsString("S\n", $content);
    }

    public function testPathConvertsEveryCoordinateIncludingCurveControls(): void
    {
        $document = new Document();
        $flow = $this->flow($document);

        $flow->path(
            static fn (PathSink $path) => $path->moveTo(10.0, 10.0)->curveTo(20.0, 10.0, 30.0, 20.0, 40.0, 20.0),
            stroke: new Stroke(),
        );

        $content = $this->contentOf($document->pages()[0]);

        self::assertStringContainsString($this->at($flow, 10.0, 10.0) . ' m', $content);
        self::assertStringContainsString(
            implode(' ', [
                $this->at($flow, 20.0, 10.0),
                $this->at($flow, 30.0, 20.0),
                $this->at($flow, 40.0, 20.0),
            ]) . ' c',
            $content,
        );
    }

    /**
     * This layer measures down from the top-left, so a positive angle
     * turns clockwise -- the way it does in CSS and SVG, and the opposite
     * of the content layer underneath, which is Y-up.
     */
    public function testPositiveRotationIsClockwiseInAYDownLayer(): void
    {
        $document = new Document();
        $flow = $this->flow($document);

        $flow->rotated(90.0, 0.0, 0.0, static function (Flow $flow): void {
            $flow->rect(0.0, 0.0, 10.0, 10.0, Color::black());
        });

        // -90 degrees in PDF's Y-up space: [0 -1 1 0 ...].
        self::assertStringContainsString('0 -1 1 0 ', $this->contentOf($document->pages()[0]));
    }

    public function testRotatedTextTurnsAboutItsOwnBaselineOrigin(): void
    {
        $document = new Document();
        $flow = $this->flow($document);

        $flow->rotatedTextAt(20.0, 100.0, -90.0, 'Revenue');

        $content = $this->contentOf($document->pages()[0]);

        self::assertStringContainsString(
            '1 0 0 1 ' . $this->at($flow, 20.0, 100.0) . ' Tm',
            $content,
            'the baseline stays put',
        );
        self::assertStringContainsString('0 1 -1 0 ', $content, 'and reads bottom-to-top');
    }

    public function testFadedWrapsWhateverTheClosureDraws(): void
    {
        $document = new Document();
        $flow = $this->flow($document);

        $flow->faded(0.15, static function (Flow $flow): void {
            $flow->cell(50.0, 8.0, 'DRAFT');
        });

        self::assertStringContainsString('/GS1 gs', $this->contentOf($document->pages()[0]));
        self::assertStringContainsString('/ca 0.15', $document->save());
    }

    public function testClippedToBoxClipsInFlowCoordinates(): void
    {
        $document = new Document();
        $flow = $this->flow($document);

        $flow->clippedToBox(10.0, 10.0, 50.0, 20.0, static function (Flow $flow): void {
            $flow->cell(100.0, 8.0, 'a very long label indeed');
        });

        $content = $this->contentOf($document->pages()[0]);

        self::assertStringContainsString("W\nn\n", $content);
        // The box's bottom-left corner is 10mm across, 30mm down.
        self::assertStringContainsString(
            $this->at($flow, 10.0, 30.0) . ' '
            . PdfNumberFormat::format($flow->unit()->toPoints(50.0)) . ' '
            . PdfNumberFormat::format($flow->unit()->toPoints(20.0)) . ' re',
            $content,
        );
    }

    // -- Paints through the layout layer ---------------------------------

    public function testACellCanBeFilledWithAProcessColour(): void
    {
        $document = new Document();
        $flow = $this->flow($document);

        $flow->cell(50.0, 8.0, 'Total', new Style(fill: CmykColor::fromPercentages(0, 0, 0, 8)));

        self::assertStringContainsString('0 0 0 0.08 k', $this->contentOf($document->pages()[0]));
    }

    public function testTextCanBeSetInANamedInk(): void
    {
        $document = new Document();
        $flow = $this->flow($document);

        $brand = SpotColor::named('PANTONE 300 C', CmykColor::fromPercentages(100, 44, 0, 0));

        $flow->cell(50.0, 8.0, 'Heading', new Style(color: $brand));

        $content = $this->contentOf($document->pages()[0]);

        self::assertStringContainsString('/CS1 cs', $content);
        self::assertStringContainsString('1 scn', $content);
        self::assertStringContainsString('/Separation /PANTONE#20300#20C', $document->save());
    }

    public function testARuleCanBeDashed(): void
    {
        $document = new Document();
        $flow = $this->flow($document);

        $flow->cell(50.0, 8.0, '', new Style(border: Border::bottom(0.5, dash: Dash::dashed(2.0))));

        self::assertStringContainsString('[2] 0 d', $this->contentOf($document->pages()[0]));
    }

    public function testABorderInASpotColourReachesTheSeparation(): void
    {
        $document = new Document();
        $flow = $this->flow($document);

        $flow->rect(10.0, 10.0, 30.0, 10.0, border: Border::box(
            1.0,
            SpotColor::named('Dieline', CmykColor::fromPercentages(0, 100, 100, 0)),
        ));

        self::assertStringContainsString('/CS1 CS', $this->contentOf($document->pages()[0]));
    }

    /** Still one "re", per the reasoning in Flow::rect(). */
    public function testAFilledAndFullyBorderedBoxIsStillOneRectangle(): void
    {
        $document = new Document();
        $flow = $this->flow($document);

        $flow->rect(10.0, 10.0, 30.0, 10.0, Color::black(), Border::box(0.5));

        $content = $this->contentOf($document->pages()[0]);

        self::assertSame(1, substr_count($content, ' re'));
        self::assertStringContainsString("B\n", $content, 'filled and stroked in one operator');
    }

    /**
     * A point in this Flow's coordinates, formatted the way the content
     * stream writes it -- so the expectation is derived from the same
     * conversion rather than from a hand-computed constant that is wrong
     * in the last decimal place.
     */
    private function at(Flow $flow, float $x, float $y): string
    {
        return PdfNumberFormat::format($flow->toPointsX($x))
            . ' ' . PdfNumberFormat::format($flow->toPointsY($y));
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
