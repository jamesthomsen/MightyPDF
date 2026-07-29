<?php

declare(strict_types=1);

namespace MightyPDF\Tests\Content;

use MightyPDF\Assembler\Document;
use MightyPDF\Assembler\Page;
use MightyPDF\Content\ContentStream;
use MightyPDF\Content\Font\StandardFont;
use MightyPDF\Content\PageBuilder;
use PHPUnit\Framework\TestCase;

final class PageBuilderTest extends TestCase
{
    public function testDrawTextRegistersAFontResourceAndContentStream(): void
    {
        $document = new Document();
        $page = $document->newPage();
        $builder = new PageBuilder($document, $page);

        $builder->drawText(StandardFont::Helvetica, 24.0, 72.0, 720.0, 'Hello, world!');

        $output = $document->save();

        self::assertStringContainsString('/BaseFont /Helvetica', $output);
        self::assertStringContainsString('/Encoding /WinAnsiEncoding', $output);
        self::assertStringContainsString('/Font <<', $output);
        self::assertCount(1, $page->contentStreams(), 'all drawing should share one content stream');

        // Content streams are FlateDecode-compressed by default, so the
        // operator text isn't literally present in $output -- decompress
        // the page's own content stream to check it.
        self::assertStringContainsString('(Hello, world!) Tj', $this->decompressedContentStreamBytes($page));
    }

    public function testSymbolFontDoesNotDeclareWinAnsiEncoding(): void
    {
        $document = new Document();
        $page = $document->newPage();
        $builder = new PageBuilder($document, $page);

        $builder->drawText(StandardFont::Symbol, 12.0, 0, 0, 'x');

        $output = $document->save();
        self::assertStringNotContainsString('/Encoding /WinAnsiEncoding', $output);
    }

    public function testReusingTheSameFontReusesTheSameResourceName(): void
    {
        $document = new Document();
        $page = $document->newPage();
        $builder = new PageBuilder($document, $page);

        $builder->drawText(StandardFont::Helvetica, 12.0, 0, 0, 'one');
        $builder->drawText(StandardFont::Helvetica, 12.0, 0, 20, 'two');

        $output = $document->save();

        // Only one Font dictionary should have been registered for Helvetica.
        self::assertSame(1, preg_match_all('/\/BaseFont \/Helvetica\b/', $output));
    }

    public function testTwoDifferentFontsGetDistinctResourceNames(): void
    {
        $document = new Document();
        $page = $document->newPage();
        $builder = new PageBuilder($document, $page);

        $builder->drawText(StandardFont::Helvetica, 12.0, 0, 0, 'one');
        $builder->drawText(StandardFont::TimesBold, 12.0, 0, 20, 'two');

        $output = $document->save();

        self::assertStringContainsString('/BaseFont /Helvetica', $output);
        self::assertStringContainsString('/BaseFont /Times-Bold', $output);
        self::assertStringContainsString('/F1', $output);
        self::assertStringContainsString('/F2', $output);
    }

    public function testMultiplePagesEachGetTheirOwnContentStream(): void
    {
        $document = new Document();
        $page1 = $document->newPage();
        $page2 = $document->newPage();

        (new PageBuilder($document, $page1))->drawText(StandardFont::Helvetica, 12.0, 0, 0, 'first');
        (new PageBuilder($document, $page2))->drawText(StandardFont::Helvetica, 12.0, 0, 0, 'second');

        self::assertCount(1, $page1->contentStreams());
        self::assertCount(1, $page2->contentStreams());
        self::assertNotSame($page1->contentStreams()[0]->objectId(), $page2->contentStreams()[0]->objectId());
    }

    public function testResultingPdfIsStructurallyValid(): void
    {
        $document = new Document();
        $page = $document->newPage();
        (new PageBuilder($document, $page))->drawText(StandardFont::Helvetica, 24.0, 72.0, 720.0, 'Hello, world!');

        $output = $document->save();

        $objCount = preg_match_all('/\d+ 0 obj/', $output);
        $endobjCount = preg_match_all('/endobj/', $output);
        self::assertSame($objCount, $endobjCount);

        preg_match_all('/^(\d{10}) \d{5} n \n/m', $output, $matches);
        foreach ($matches[1] as $offsetString) {
            self::assertMatchesRegularExpression('/^\d+ 0 obj/', substr($output, (int) $offsetString, 20));
        }
    }

    public function testDrawLineAppendsToTheSamePageContentStream(): void
    {
        $document = new Document();
        $page = $document->newPage();
        $builder = new PageBuilder($document, $page);

        $builder->drawLine(0, 0, 100, 100, 2.0, 1.0, 0.0, 0.0);

        self::assertCount(1, $page->contentStreams());
        $bytes = $this->decompressedContentStreamBytes($page);
        self::assertStringContainsString('2 w', $bytes);
        self::assertStringContainsString('1 0 0 RG', $bytes);
        self::assertStringContainsString('0 0 m', $bytes);
        self::assertStringContainsString('100 100 l', $bytes);
        self::assertStringContainsString('S', $bytes);
    }

    public function testFillRectangle(): void
    {
        $document = new Document();
        $page = $document->newPage();
        $builder = new PageBuilder($document, $page);

        $builder->fillRectangle(10, 10, 200, 100, 0.0, 1.0, 0.0);

        $bytes = $this->decompressedContentStreamBytes($page);
        self::assertStringContainsString('0 1 0 rg', $bytes);
        self::assertStringContainsString('10 10 200 100 re', $bytes);
        self::assertStringContainsString('f', $bytes);
    }

    public function testStrokeRectangle(): void
    {
        $document = new Document();
        $page = $document->newPage();
        $builder = new PageBuilder($document, $page);

        $builder->strokeRectangle(0, 0, 50, 50);

        $bytes = $this->decompressedContentStreamBytes($page);
        self::assertStringContainsString('0 0 50 50 re', $bytes);
        self::assertStringContainsString('S', $bytes);
    }

    public function testTextAndShapesShareOnePageContentStream(): void
    {
        $document = new Document();
        $page = $document->newPage();
        $builder = new PageBuilder($document, $page);

        $builder->drawText(StandardFont::Helvetica, 12.0, 0, 0, 'label')
            ->drawLine(0, 0, 10, 10)
            ->fillRectangle(0, 0, 5, 5);

        self::assertCount(1, $page->contentStreams());
    }

    public function testDrawCustomAppendsRawOperatorsToThePageStream(): void
    {
        $document = new Document();
        $page = $document->newPage();
        $builder = new PageBuilder($document, $page);

        $custom = (new ContentStream())->moveTo(1, 1)->lineTo(2, 2)->stroke();
        $builder->drawCustom($custom);

        $bytes = $this->decompressedContentStreamBytes($page);
        self::assertStringContainsString('1 1 m', $bytes);
        self::assertStringContainsString('2 2 l', $bytes);
    }

    private function decompressedContentStreamBytes(Page $page): string
    {
        $rendered = $page->contentStreams()[0]->render(true);
        preg_match('/stream\n(.*)\nendstream/s', $rendered, $matches);

        return gzuncompress($matches[1]);
    }
}
