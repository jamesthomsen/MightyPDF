<?php

declare(strict_types=1);

namespace MightyPDF\Tests\Layout;

use MightyPDF\Assembler\Document;
use MightyPDF\Assembler\PageSize;
use MightyPDF\Layout\Flow;
use MightyPDF\Layout\Margins;
use MightyPDF\Layout\Unit;
use PHPUnit\Framework\TestCase;

final class FlowBleedTest extends TestCase
{
    private const float THREE_MM = 8.5039;

    private function flow(Document $document): Flow
    {
        return new Flow(
            $document,
            PageSize::A4->withBleed(self::THREE_MM),
            Margins::uniform(15.0),
        );
    }

    public function testEveryPageGetsATrimAndBleedBox(): void
    {
        $document = new Document();
        $flow = $this->flow($document);
        $flow->setBleed(3.0);
        $flow->newPage();

        self::assertCount(2, $document->pages());

        foreach ($document->pages() as $page) {
            self::assertEqualsWithDelta(595.28, $page->trimBox()->width(), 0.05);
            self::assertEqualsWithDelta(841.89, $page->trimBox()->height(), 0.05);
            self::assertEqualsWithDelta(self::THREE_MM, $page->trimBox()->x1, 0.05);
            self::assertEqualsWithDelta($page->mediaBox()->width(), $page->bleedBox()->width(), 0.05);
        }
    }

    public function testPagesMadeBeforeSetBleedGetTheBoxesToo(): void
    {
        // The first page exists from construction, so it is always one of
        // these -- if setBleed() only reached future pages, page 1 of
        // every job would be the odd one out.
        $document = new Document();
        $flow = $this->flow($document);
        $flow->setBleed(3.0);

        self::assertEqualsWithDelta(595.28, $document->pages()[0]->trimBox()->width(), 0.05);
    }

    public function testMarginsMoveInByTheBleed(): void
    {
        $document = new Document();
        $flow = $this->flow($document);

        self::assertSame(15.0, $flow->margins()->left);

        $flow->setBleed(3.0);

        // 15mm from the cut, which is 18mm from the edge of the sheet.
        self::assertEqualsWithDelta(18.0, $flow->margins()->left, 0.001);
        self::assertEqualsWithDelta(18.0, $flow->margins()->top, 0.001);
        self::assertEqualsWithDelta(18.0, $flow->margins()->right, 0.001);
        self::assertEqualsWithDelta(18.0, $flow->margins()->bottom, 0.001);
    }

    public function testTheContentWidthIsStillTheFinishedPageLessItsMargins(): void
    {
        $document = new Document();
        $flow = $this->flow($document);
        $flow->setBleed(3.0);

        // A4 is 210mm; 15mm of margin each side leaves 180mm, and the
        // bleed must not have changed that -- the sheet got wider and the
        // margins moved in by exactly as much.
        self::assertEqualsWithDelta(180.0, $flow->contentWidth(), 0.01);
    }

    public function testTheCursorStartsAgainstTheNewMargin(): void
    {
        $document = new Document();
        $flow = $this->flow($document);
        $flow->setBleed(3.0);

        self::assertEqualsWithDelta(18.0, $flow->x(), 0.001);
        self::assertEqualsWithDelta(18.0, $flow->y(), 0.001);
    }

    public function testBleedIsGivenInTheFlowsOwnUnit(): void
    {
        $document = new Document();
        $flow = new Flow(
            $document,
            PageSize::A4->withBleed(9.0),
            Margins::uniform(36.0),
            Unit::Points,
        );
        $flow->setBleed(9.0);

        self::assertEqualsWithDelta(45.0, $flow->margins()->left, 0.001);
        self::assertEqualsWithDelta(9.0, $document->pages()[0]->trimBox()->x1, 0.01);
    }

    public function testASecondBleedIsRefused(): void
    {
        $flow = $this->flow(new Document());
        $flow->setBleed(3.0);

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('already has a bleed');

        $flow->setBleed(3.0);
    }

    public function testANegativeBleedIsRefused(): void
    {
        $flow = $this->flow(new Document());

        $this->expectException(\InvalidArgumentException::class);

        $flow->setBleed(-3.0);
    }

    public function testASheetTooSmallToTrimIsRefusedWithNothingMoved(): void
    {
        $document = new Document();
        // A4 exactly, with a bleed of half the page asked for on top.
        $flow = new Flow($document, PageSize::A4, Margins::uniform(15.0));

        try {
            $flow->setBleed(150.0);
            self::fail('Expected a bleed of 150mm on an A4 sheet to be refused.');
        } catch (\InvalidArgumentException) {
            // The margins must be exactly where they were: a Flow that
            // half-applied a bleed it then rejected is worse than one
            // that rejected it.
            self::assertSame(15.0, $flow->margins()->left);
        }
    }

    public function testAFlowWithNoBleedWritesNoTrimBox(): void
    {
        $document = new Document();
        new Flow($document, PageSize::A4, Margins::uniform(15.0));

        self::assertStringNotContainsString('/TrimBox', $document->pages()[0]->render(false));
    }
}
