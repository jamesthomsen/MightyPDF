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
    private const string FIXTURES = __DIR__ . '/../fixtures/images';

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

    public function testDrawJpegRegistersAnXObjectResourceAndPlacementOperators(): void
    {
        $document = new Document();
        $page = $document->newPage();
        $builder = new PageBuilder($document, $page);

        $builder->drawJpeg(self::FIXTURES . '/sample.jpg', 10, 20, 100, 200);

        $output = $document->save();
        self::assertStringContainsString('/Filter /DCTDecode', $output);
        self::assertStringContainsString('/XObject <<', $output);

        $bytes = $this->decompressedContentStreamBytes($page);
        self::assertStringContainsString('q', $bytes);
        self::assertStringContainsString('100 0 0 200 10 20 cm', $bytes);
        self::assertStringContainsString('/Im1 Do', $bytes);
        self::assertStringContainsString('Q', $bytes);
    }

    public function testDrawPngAndDrawGifGetDistinctImageResourceNames(): void
    {
        $document = new Document();
        $page = $document->newPage();
        $builder = new PageBuilder($document, $page);

        $builder->drawPng(self::FIXTURES . '/sample.png', 0, 0, 10, 10);
        $builder->drawGif(self::FIXTURES . '/sample.gif', 20, 0, 10, 10);

        $bytes = $this->decompressedContentStreamBytes($page);
        self::assertStringContainsString('/Im1 Do', $bytes);
        self::assertStringContainsString('/Im2 Do', $bytes);
    }

    public function testImagesAndTextShareTheSamePageContentStream(): void
    {
        $document = new Document();
        $page = $document->newPage();
        $builder = new PageBuilder($document, $page);

        $builder->drawText(StandardFont::Helvetica, 12.0, 0, 0, 'caption')
            ->drawJpeg(self::FIXTURES . '/sample.jpg', 0, 20, 50, 50);

        self::assertCount(1, $page->contentStreams());
    }

    public function testResultingPdfWithImagesIsStructurallyValid(): void
    {
        $document = new Document();
        $page = $document->newPage();
        (new PageBuilder($document, $page))->drawJpeg(self::FIXTURES . '/sample.jpg', 0, 0, 100, 100);

        $output = $document->save();

        $objCount = preg_match_all('/\d+ 0 obj/', $output);
        $endobjCount = preg_match_all('/endobj/', $output);
        self::assertSame($objCount, $endobjCount);
    }

    public function testAddTextFieldWiresFontIntoAcroFormDrNotPageResources(): void
    {
        $document = new Document();
        $page = $document->newPage();
        $builder = new PageBuilder($document, $page);

        $builder->addTextField('FirstName', 72, 700, 200, 20, value: 'Jane');

        $output = $document->save();

        self::assertStringContainsString('/AcroForm', $output);
        self::assertStringContainsString('/FT /Tx', $output);
        self::assertStringContainsString('/T (FirstName)', $output);
        self::assertStringContainsString('/V (Jane)', $output);
        self::assertStringContainsString('/NeedsAppearances true', $output);

        // The field's /DA references a font resource that must resolve
        // via AcroForm's /DR, not the page's own /Resources.
        self::assertStringContainsString('/DR << /Font <<', $output);
    }

    public function testAddTextFieldAppearsInPageAnnotsAndAcroFormFields(): void
    {
        $document = new Document();
        $page = $document->newPage();
        (new PageBuilder($document, $page))->addTextField('Name', 0, 0, 100, 20);

        $output = $document->save();

        self::assertStringContainsString('/Annots [', $output);
        self::assertStringContainsString('/Fields [', $output);
    }

    public function testMultipleFieldsAcrossPagesShareOneAcroForm(): void
    {
        $document = new Document();
        $page1 = $document->newPage();
        $page2 = $document->newPage();

        (new PageBuilder($document, $page1))->addTextField('A', 0, 0, 50, 20);
        (new PageBuilder($document, $page2))->addTextField('B', 0, 0, 50, 20);

        $output = $document->save();

        // Exactly one AcroForm object (one /NeedsAppearances declaration),
        // and its /Fields array lists both pages' fields together.
        self::assertSame(1, preg_match_all('/\/NeedsAppearances true/', $output));
        preg_match('/\/Fields \[([^\]]*)\]/', $output, $matches);
        self::assertSame(2, substr_count($matches[1], ' 0 R'));
    }

    public function testAddCheckboxUncheckedByDefault(): void
    {
        $document = new Document();
        $page = $document->newPage();
        (new PageBuilder($document, $page))->addCheckbox('Agree', 72, 700, 12);

        $output = $document->save();

        self::assertStringContainsString('/FT /Btn', $output);
        self::assertStringContainsString('/AS /Off', $output);
        self::assertStringContainsString('/AP <<', $output);
    }

    public function testAddCheckboxChecked(): void
    {
        $document = new Document();
        $page = $document->newPage();
        (new PageBuilder($document, $page))->addCheckbox('Agree', 72, 700, 12, checked: true);

        $output = $document->save();

        self::assertStringContainsString('/AS /Yes', $output);
    }

    public function testAddRadioGroupCreatesParentFieldWithRadioFlagAndKids(): void
    {
        $document = new Document();
        $page = $document->newPage();
        (new PageBuilder($document, $page))->addRadioGroup('Color', [
            ['exportValue' => 'Red', 'x' => 72, 'y' => 700, 'size' => 10],
            ['exportValue' => 'Blue', 'x' => 100, 'y' => 700, 'size' => 10],
        ], checkedExportValue: 'Blue');

        $output = $document->save();

        self::assertStringContainsString('/FT /Btn', $output);
        self::assertStringContainsString('/Ff 32768', $output);
        self::assertStringContainsString('/V /Blue', $output);
        self::assertStringContainsString('/Subtype /Widget', $output);
        self::assertStringContainsString('/AS /Blue', $output);
        self::assertStringContainsString('/AS /Off', $output);
    }

    public function testAddRadioGroupKidsAreOnPageAnnotsAndOnlyParentIsAnAcroFormField(): void
    {
        $document = new Document();
        $page = $document->newPage();
        (new PageBuilder($document, $page))->addRadioGroup('Color', [
            ['exportValue' => 'Red', 'x' => 72, 'y' => 700, 'size' => 10],
            ['exportValue' => 'Blue', 'x' => 100, 'y' => 700, 'size' => 10],
        ]);

        $output = $document->save();

        preg_match('/\/Annots \[([^\]]*)\]/', $output, $annots);
        self::assertSame(2, substr_count($annots[1], ' 0 R'), 'both radio widgets should be page annotations');

        preg_match('/\/Fields \[([^\]]*)\]/', $output, $fields);
        self::assertSame(1, substr_count($fields[1], ' 0 R'), 'only the parent field should be listed in AcroForm /Fields');
    }

    public function testAddRadioGroupIsStructurallyValid(): void
    {
        $document = new Document();
        $page = $document->newPage();
        (new PageBuilder($document, $page))->addRadioGroup('Color', [
            ['exportValue' => 'Red', 'x' => 72, 'y' => 700, 'size' => 10],
            ['exportValue' => 'Blue', 'x' => 100, 'y' => 700, 'size' => 10],
            ['exportValue' => 'Green', 'x' => 128, 'y' => 700, 'size' => 10],
        ], checkedExportValue: 'Green');

        $output = $document->save();

        $objCount = preg_match_all('/\d+ 0 obj/', $output);
        $endobjCount = preg_match_all('/endobj/', $output);
        self::assertSame($objCount, $endobjCount);

        preg_match_all('/^(\d{10}) \d{5} n \n/m', $output, $matches);
        foreach ($matches[1] as $offsetString) {
            self::assertMatchesRegularExpression('/^\d+ 0 obj/', substr($output, (int) $offsetString, 20));
        }
    }

    public function testResultingPdfWithFormFieldsIsStructurallyValid(): void
    {
        $document = new Document();
        $page = $document->newPage();
        (new PageBuilder($document, $page))
            ->addTextField('Name', 72, 700, 200, 20, value: 'Jane Doe')
            ->addCheckbox('Agree', 72, 670, 12, checked: true);

        $output = $document->save();

        $objCount = preg_match_all('/\d+ 0 obj/', $output);
        $endobjCount = preg_match_all('/endobj/', $output);
        self::assertSame($objCount, $endobjCount);

        preg_match_all('/^(\d{10}) \d{5} n \n/m', $output, $matches);
        foreach ($matches[1] as $offsetString) {
            self::assertMatchesRegularExpression('/^\d+ 0 obj/', substr($output, (int) $offsetString, 20));
        }
    }

    public function testDrawSvgRendersIntoThePageContentStream(): void
    {
        $document = new Document();
        $page = $document->newPage();
        $builder = new PageBuilder($document, $page);

        $builder->drawSvg(__DIR__ . '/../fixtures/svg/sample.svg', 100, 100, 200, 200);

        $bytes = $this->decompressedContentStreamBytes($page);
        self::assertStringContainsString('cm', $bytes);
        self::assertStringContainsString('re', $bytes);
        self::assertStringContainsString('1 0 0 rg', $bytes); // red rect
    }

    public function testDrawSvgAndOtherDrawingShareOnePageContentStream(): void
    {
        $document = new Document();
        $page = $document->newPage();
        $builder = new PageBuilder($document, $page);

        $builder->drawText(StandardFont::Helvetica, 12.0, 0, 0, 'caption')
            ->drawSvg(__DIR__ . '/../fixtures/svg/sample.svg', 0, 0, 100, 100);

        self::assertCount(1, $page->contentStreams());
    }

    public function testResultingPdfWithSvgIsStructurallyValid(): void
    {
        $document = new Document();
        $page = $document->newPage();
        (new PageBuilder($document, $page))->drawSvg(__DIR__ . '/../fixtures/svg/sample.svg', 0, 0, 200, 200);

        $output = $document->save();

        $objCount = preg_match_all('/\d+ 0 obj/', $output);
        $endobjCount = preg_match_all('/endobj/', $output);
        self::assertSame($objCount, $endobjCount);
    }

    private function decompressedContentStreamBytes(Page $page): string
    {
        $rendered = $page->contentStreams()[0]->render(true);
        preg_match('/stream\n(.*)\nendstream/s', $rendered, $matches);

        return gzuncompress($matches[1]);
    }
}
