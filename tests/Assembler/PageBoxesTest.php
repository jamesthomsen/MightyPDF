<?php

declare(strict_types=1);

namespace MightyPDF\Tests\Assembler;

use MightyPDF\Assembler\Page;
use MightyPDF\Assembler\PageSize;
use MightyPDF\Assembler\Types\PdfRectangle;
use PHPUnit\Framework\TestCase;

final class PageBoxesTest extends TestCase
{
    private function page(float $width = 612.0, float $height = 792.0): Page
    {
        return new Page(1, new PdfRectangle(0, 0, $width, $height));
    }

    public function testAPageWithNoBoxesWritesOnlyItsMediaBox(): void
    {
        $rendered = $this->page()->render(false);

        self::assertStringContainsString('/MediaBox', $rendered);
        self::assertStringNotContainsString('/CropBox', $rendered);
        self::assertStringNotContainsString('/BleedBox', $rendered);
        self::assertStringNotContainsString('/TrimBox', $rendered);
        self::assertStringNotContainsString('/ArtBox', $rendered);
    }

    public function testEachBoxIsWritten(): void
    {
        $page = $this->page();
        $page->setCropBox(new PdfRectangle(10, 10, 602, 782));
        $page->setBleedBox(new PdfRectangle(5, 5, 607, 787));
        $page->setTrimBox(new PdfRectangle(20, 20, 592, 772));
        $page->setArtBox(new PdfRectangle(30, 30, 582, 762));

        $rendered = $page->render(false);

        self::assertStringContainsString('/CropBox [10 10 602 782]', $rendered);
        self::assertStringContainsString('/BleedBox [5 5 607 787]', $rendered);
        self::assertStringContainsString('/TrimBox [20 20 592 772]', $rendered);
        self::assertStringContainsString('/ArtBox [30 30 582 762]', $rendered);
    }

    public function testAnUnsetCropBoxDefaultsToTheMediaBox(): void
    {
        $page = $this->page();

        self::assertSame(612.0, $page->cropBox()->width());
        self::assertSame(792.0, $page->cropBox()->height());
    }

    public function testTheOtherBoxesDefaultToTheCropBoxRatherThanTheMediaBox(): void
    {
        // Section 14.11.2: the crop box defaults to the media box, and
        // bleed/trim/art default to the *crop* box -- so setting a crop
        // box moves all three.
        $page = $this->page();
        $page->setCropBox(new PdfRectangle(10, 10, 602, 782));

        foreach ([$page->bleedBox(), $page->trimBox(), $page->artBox()] as $box) {
            self::assertSame(592.0, $box->width());
            self::assertSame(772.0, $box->height());
        }
    }

    public function testASetBoxIsReturnedRatherThanTheDefault(): void
    {
        $page = $this->page();
        $page->setCropBox(new PdfRectangle(10, 10, 602, 782));
        $page->setTrimBox(new PdfRectangle(20, 20, 592, 772));

        self::assertSame(572.0, $page->trimBox()->width());
        self::assertSame(592.0, $page->bleedBox()->width());
    }

    public function testABoxIsNormalizedOnTheWayIn(): void
    {
        $page = $this->page();
        $page->setTrimBox(new PdfRectangle(592, 772, 20, 20));

        self::assertStringContainsString('/TrimBox [20 20 592 772]', $page->render(false));
        self::assertSame(20.0, $page->trimBox()->x1);
    }

    public function testABoxLargerThanTheMediaBoxIsRefused(): void
    {
        $page = $this->page();

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('/TrimBox [-1 0 612 792] does not fit');

        $page->setTrimBox(new PdfRectangle(-1, 0, 612, 792));
    }

    public function testABoxExactlyTheMediaBoxIsAccepted(): void
    {
        $page = $this->page();
        $page->setBleedBox(new PdfRectangle(0, 0, 612, 792));

        self::assertSame(612.0, $page->bleedBox()->width());
    }

    public function testSetBleedTrimsTheSheetAndBleedsToItsEdge(): void
    {
        $page = new Page(1, PageSize::A4->withBleed(8.5));
        $page->setBleed(8.5);

        // The sheet is A4 plus 8.5pt all round; the trim box is the A4
        // back out of it, sitting 8.5pt in from each edge.
        self::assertEqualsWithDelta(595.28, $page->trimBox()->width(), 0.01);
        self::assertEqualsWithDelta(841.89, $page->trimBox()->height(), 0.01);
        self::assertEqualsWithDelta(8.5, $page->trimBox()->x1, 0.01);

        // The bleed box is the whole sheet.
        self::assertEqualsWithDelta($page->mediaBox()->width(), $page->bleedBox()->width(), 0.01);
        self::assertEqualsWithDelta(0.0, $page->bleedBox()->x1, 0.01);
    }

    public function testSetBleedOnASheetThatWasNeverGrownEatsIntoTheFinishedSize(): void
    {
        // The classic mistake: an A4 media box with 3mm of bleed asked for
        // on top of it, which trims to something smaller than A4 rather
        // than to A4.
        $page = new Page(1, PageSize::A4->mediaBox());
        $page->setBleed(8.5);

        // Not an error in itself -- it is a legal, if unusual, request --
        // so what it must not do is silently produce an A4 trim box.
        self::assertEqualsWithDelta(578.28, $page->trimBox()->width(), 0.01);
    }

    public function testABleedBiggerThanTheSheetIsRefused(): void
    {
        $page = $this->page(100, 100);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('leaves nothing of a 100 x 100 sheet');

        $page->setBleed(60.0);
    }

    public function testANegativeBleedIsRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->page()->setBleed(-1.0);
    }

    public function testWithBleedGrowsTheSheetOnEverySide(): void
    {
        $box = PageSize::A4->withBleed(10.0);

        // At the origin, not A4 expanded in place -- see withBleed().
        self::assertSame(0.0, $box->x1);
        self::assertSame(0.0, $box->y1);
        self::assertEqualsWithDelta(615.28, $box->width(), 0.01);
        self::assertEqualsWithDelta(861.89, $box->height(), 0.01);
    }

    public function testMediaBoxIsNormalizedEvenWhenGivenInverted(): void
    {
        $page = new Page(1, new PdfRectangle(612, 792, 0, 0));

        self::assertSame(0.0, $page->mediaBox()->x1);
        self::assertSame(612.0, $page->mediaBox()->x2);

        // What is *written* is still exactly what the caller gave.
        self::assertStringContainsString('/MediaBox [612 792 0 0]', $page->render(false));
    }
}
