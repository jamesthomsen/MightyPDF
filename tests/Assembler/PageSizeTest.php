<?php

declare(strict_types=1);

namespace MightyPDF\Tests\Assembler;

use MightyPDF\Assembler\Document;
use MightyPDF\Assembler\PageSize;
use MightyPDF\Assembler\Types\PdfRectangle;
use PHPUnit\Framework\TestCase;

final class PageSizeTest extends TestCase
{
    /**
     * The numbers everyone copies out of a README, so worth pinning to
     * what the rest of the world writes -- a document that says
     * 595.28 x 841.89 diffs cleanly against one produced by any other
     * tool, where a full-precision 595.2755905511811 would not.
     */
    public function testA4IsTheFamiliarPair(): void
    {
        self::assertSame(595.28, PageSize::A4->widthPt());
        self::assertSame(841.89, PageSize::A4->heightPt());
    }

    public function testTheUsSizesAreExactInInches(): void
    {
        self::assertSame(612.0, PageSize::Letter->widthPt());
        self::assertSame(792.0, PageSize::Letter->heightPt());
        self::assertSame(1008.0, PageSize::Legal->heightPt());
    }

    /** Each A size is the next one folded in half, give or take rounding. */
    public function testTheASeriesHalvesAsItGoes(): void
    {
        self::assertEqualsWithDelta(PageSize::A3->widthPt(), PageSize::A4->heightPt(), 0.5);
        self::assertEqualsWithDelta(PageSize::A4->widthPt(), PageSize::A5->heightPt(), 0.5);
    }

    public function testEverySizeIsTallerThanItIsWide(): void
    {
        foreach (PageSize::cases() as $size) {
            self::assertGreaterThan($size->widthPt(), $size->heightPt(), $size->name);
        }
    }

    public function testLandscapeSwapsTheTwo(): void
    {
        $landscape = PageSize::A4->landscape();

        self::assertSame(841.89, $landscape->width());
        self::assertSame(595.28, $landscape->height());
    }

    public function testTheMediaBoxStartsAtTheOrigin(): void
    {
        $box = PageSize::Letter->mediaBox();

        self::assertSame('[0 0 612 792]', $box->format());
    }

    /**
     * Widened rather than given a second method, so there is still one
     * way to add a page.
     */
    public function testNewPageTakesAPageSizeDirectly(): void
    {
        $document = new Document();
        $document->newPage(PageSize::A4);

        self::assertStringContainsString('[0 0 595.28 841.89]', $document->pages()[0]->render(true));
    }

    public function testNewPageStillTakesARectangleOrNothing(): void
    {
        $document = new Document();
        $document->newPage(new PdfRectangle(0, 0, 100, 200));
        $document->newPage();

        self::assertStringContainsString('[0 0 100 200]', $document->pages()[0]->render(true));
        self::assertStringContainsString('[0 0 612 792]', $document->pages()[1]->render(true));
    }
}
