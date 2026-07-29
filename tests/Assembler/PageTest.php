<?php

declare(strict_types=1);

namespace MightyPDF\Tests\Assembler;

use MightyPDF\Assembler\Page;
use MightyPDF\Assembler\Stream;
use MightyPDF\Assembler\Types\PdfRectangle;
use PHPUnit\Framework\TestCase;

final class PageTest extends TestCase
{
    public function testBlankPageHasNoContentsEntry(): void
    {
        // Per spec, an empty Contents array is invalid -- a page with no
        // content should omit /Contents entirely.
        $page = new Page(1, new PdfRectangle(0, 0, 612, 792));

        self::assertStringNotContainsString('/Contents', $page->render(false));
    }

    public function testBlankPageHasNoAnnotsEntry(): void
    {
        $page = new Page(1, new PdfRectangle(0, 0, 612, 792));

        self::assertStringNotContainsString('/Annots', $page->render(false));
    }

    public function testDeclaresTypeMediaBoxAndEmptyResources(): void
    {
        $page = new Page(1, new PdfRectangle(0, 0, 612, 792));

        $rendered = $page->render(false);
        self::assertStringContainsString('/Type /Page', $rendered);
        self::assertStringContainsString('/MediaBox [0 0 612 792]', $rendered);
        self::assertStringContainsString('/Resources <<>>', $rendered);
    }

    public function testSetParentAddsReference(): void
    {
        $page = new Page(1, new PdfRectangle(0, 0, 612, 792));
        $page->setParent(9);

        self::assertStringContainsString('/Parent 9 0 R', $page->render(false));
    }

    public function testAddingAContentStreamPopulatesContents(): void
    {
        $page = new Page(1, new PdfRectangle(0, 0, 612, 792));
        $stream = new Stream(5, 'BT ET', compress: false);
        $page->addContentStream($stream);

        self::assertStringContainsString('/Contents [5 0 R]', $page->render(false));
        self::assertSame([$stream], $page->contentStreams());
    }

    public function testAddingMultipleContentStreamsPreservesOrder(): void
    {
        $page = new Page(1, new PdfRectangle(0, 0, 612, 792));
        $page->addContentStream(new Stream(5, 'a', compress: false));
        $page->addContentStream(new Stream(6, 'b', compress: false));

        self::assertStringContainsString('/Contents [5 0 R 6 0 R]', $page->render(false));
    }

    public function testAddingAnAnnotationPopulatesAnnots(): void
    {
        $page = new Page(1, new PdfRectangle(0, 0, 612, 792));
        $page->addAnnotation(11);
        $page->addAnnotation(12);

        self::assertStringContainsString('/Annots [11 0 R 12 0 R]', $page->render(false));
    }

    public function testResourcesAccessorReturnsTheLiveResourcesDictionary(): void
    {
        $page = new Page(1, new PdfRectangle(0, 0, 612, 792));
        self::assertSame('<<>>', $page->resources()->render(false));
    }
}
