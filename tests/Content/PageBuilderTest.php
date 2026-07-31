<?php

declare(strict_types=1);

namespace MightyPDF\Tests\Content;

use MightyPDF\Assembler\Document;
use MightyPDF\Assembler\Form\TextField;
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

    public function testDrawTextDefaultsToExplicitBlackFillColor(): void
    {
        $document = new Document();
        $page = $document->newPage();
        (new PageBuilder($document, $page))->drawText(StandardFont::Helvetica, 12.0, 0, 0, 'x');

        self::assertStringContainsString('0 0 0 rg', $this->decompressedContentStreamBytes($page));
    }

    public function testDrawTextSetsCustomFillColor(): void
    {
        $document = new Document();
        $page = $document->newPage();
        (new PageBuilder($document, $page))->drawText(StandardFont::Helvetica, 12.0, 0, 0, 'x', r: 1.0, g: 1.0, b: 1.0);

        self::assertStringContainsString('1 1 1 rg', $this->decompressedContentStreamBytes($page));
    }

    public function testDrawTextColorDoesNotLeakFromAPriorFillRectangleCall(): void
    {
        $document = new Document();
        $page = $document->newPage();
        $builder = new PageBuilder($document, $page);

        // A prior fill (e.g. a colored bar behind the text) sets the
        // shared content stream's fill color -- drawText() must set its
        // own regardless, immediately before its BT, rather than
        // silently inheriting whatever a previous, unrelated call left
        // in effect.
        $builder->fillRectangle(0, 0, 10, 10, r: 0.2, g: 0.2, b: 0.2);
        $builder->drawText(StandardFont::Helvetica, 12.0, 0, 0, 'label');

        self::assertStringContainsString("0 0 0 rg\nBT\n", $this->decompressedContentStreamBytes($page));
    }

    public function testDrawParagraphWrapsAcrossMultipleLinesInOrder(): void
    {
        $document = new Document();
        $page = $document->newPage();
        $builder = new PageBuilder($document, $page);

        // Courier is a fixed 600/1000 em per character (see StandardFont::metrics()),
        // i.e. 6pt/char at size 10 -- "Hello World" (11 chars) is 66pt, over the 40pt box.
        $builder->drawParagraph(StandardFont::Courier, 10.0, 0, 0, 40, 100, 'Hello World Foo');

        $bytes = $this->decompressedContentStreamBytes($page);

        self::assertStringContainsString('(Hello) Tj', $bytes);
        self::assertStringContainsString('(World) Tj', $bytes);
        self::assertStringContainsString('(Foo) Tj', $bytes);
        self::assertTrue(
            strpos($bytes, '(Hello) Tj') < strpos($bytes, '(World) Tj')
            && strpos($bytes, '(World) Tj') < strpos($bytes, '(Foo) Tj'),
            'lines should be drawn top to bottom, in source order',
        );
    }

    public function testDrawParagraphRightAlignPositionsTextAgainstTheBoxsRightEdge(): void
    {
        $document = new Document();
        $page = $document->newPage();
        $builder = new PageBuilder($document, $page);

        // "Hi" is 2 * 6 = 12pt wide; in a 40pt-wide box, right-aligned x = 0 + 40 - 12 = 28.
        $builder->drawParagraph(StandardFont::Courier, 10.0, 0, 0, 40, 100, 'Hi', align: 'R');

        self::assertStringContainsString('1 0 0 1 28 ', $this->decompressedContentStreamBytes($page));
    }

    public function testDrawParagraphValignTopAndBottomPositionTheBaselineDifferently(): void
    {
        $document = new Document();
        $topPage = $document->newPage();
        (new PageBuilder($document, $topPage))->drawParagraph(StandardFont::Courier, 10.0, 0, 0, 100, 100, 'Hi', valign: 'T');

        $bottomDocument = new Document();
        $bottomPage = $bottomDocument->newPage();
        (new PageBuilder($bottomDocument, $bottomPage))->drawParagraph(StandardFont::Courier, 10.0, 0, 0, 100, 100, 'Hi', valign: 'B');

        // T: baseline = (0 + 100) - ascent(8) = 92. B: baseline = (0 + lineHeight(11.5)) - ascent(8) = 3.5.
        self::assertStringContainsString('1 0 0 1 0 92 Tm', $this->decompressedContentStreamBytes($topPage));
        self::assertStringContainsString('1 0 0 1 0 3.5 Tm', $this->decompressedContentStreamBytes($bottomPage));
    }

    public function testDrawParagraphJustifyAddsWordSpacingToEveryNonLastLineOnly(): void
    {
        $document = new Document();
        $page = $document->newPage();
        $builder = new PageBuilder($document, $page);

        // "Hi you" (6 chars = 36pt) fits a 42pt box; "Hi you now" (10 chars = 60pt) doesn't,
        // so this wraps to ["Hi you", "now"]. Line 1 has one space and 6pt of slack to fill.
        $builder->drawParagraph(StandardFont::Courier, 10.0, 0, 0, 42, 100, 'Hi you now', align: 'J');

        $bytes = $this->decompressedContentStreamBytes($page);

        self::assertStringContainsString('6 Tw', $bytes);
        // Every line -- justified or not -- explicitly sets Tw (0 for the ragged last line),
        // so word spacing never leaks from one line into the next.
        self::assertSame(2, substr_count($bytes, ' Tw'));
    }

    public function testDrawParagraphOnEmptyBoxTextfits(): void
    {
        $document = new Document();
        $page = $document->newPage();
        (new PageBuilder($document, $page))->drawParagraph(StandardFont::Courier, 10.0, 0, 0, 100, 20, '');

        $objCount = preg_match_all('/\d+ 0 obj/', $document->save());
        self::assertGreaterThan(0, $objCount);
    }

    public function testDrawParagraphSetsCustomFillColorPerLineRegardlessOfPriorState(): void
    {
        $document = new Document();
        $page = $document->newPage();
        $builder = new PageBuilder($document, $page);

        $builder->fillRectangle(0, 0, 10, 10, r: 0.2, g: 0.2, b: 0.2);
        $builder->drawParagraph(StandardFont::Courier, 10.0, 0, 0, 100, 20, 'hi', r: 1.0, g: 1.0, b: 1.0);

        self::assertStringContainsString("1 1 1 rg\nBT\n", $this->decompressedContentStreamBytes($page));
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

    public function testDrawBarcodeFillsExactlyTheBarElementsAndSpansTheFullWidth(): void
    {
        $document = new Document();
        $page = $document->newPage();
        $builder = new PageBuilder($document, $page);

        $builder->drawBarcode('A', 0, 0, 100, 20);

        $bytes = $this->decompressedContentStreamBytes($page);

        // 'A' framed as *A* is 3 characters * 9 elements = 27, of which
        // ceil(9/2)=5 are bars per character -> 15 filled rectangles total.
        self::assertSame(15, substr_count($bytes, ' re'));
        self::assertSame(15, substr_count($bytes, 'f'));
        self::assertStringContainsString('0 0 0 rg', $bytes);

        // The last bar's right edge should reach (very close to) the
        // requested width -- confirms the module width was scaled to fit,
        // not left at some fixed unrelated size.
        preg_match_all('/([\d.]+) 0 ([\d.]+) 20 re/', $bytes, $matches);
        $rightEdges = array_map(
            static fn (string $x, string $w): float => (float) $x + (float) $w,
            $matches[1],
            $matches[2],
        );
        self::assertEqualsWithDelta(100.0, max($rightEdges), 0.01);
    }

    public function testDrawBarcodeRejectsUnknownSymbology(): void
    {
        $document = new Document();
        $page = $document->newPage();
        $builder = new PageBuilder($document, $page);

        $this->expectException(\InvalidArgumentException::class);
        $builder->drawBarcode('A', 0, 0, 100, 20, symbology: 'qr');
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

    public function testRepeatedImageAcrossPagesReusesOneXObject(): void
    {
        $document = new Document();
        $page1 = $document->newPage();
        $page2 = $document->newPage();

        (new PageBuilder($document, $page1))->drawJpeg(self::FIXTURES . '/sample.jpg', 0, 0, 100, 100);
        (new PageBuilder($document, $page2))->drawJpeg(self::FIXTURES . '/sample.jpg', 0, 0, 100, 100);

        $output = $document->save();

        self::assertSame(
            1,
            substr_count($output, '/Filter /DCTDecode'),
            'the same JPEG bytes drawn on two different pages should embed only one Image XObject',
        );
    }

    public function testRepeatedImageOnTheSamePageReusesOneXObject(): void
    {
        $document = new Document();
        $page = $document->newPage();
        $builder = new PageBuilder($document, $page);

        $builder->drawPng(self::FIXTURES . '/sample.png', 0, 0, 10, 10);
        $builder->drawPng(self::FIXTURES . '/sample.png', 20, 0, 10, 10);

        $output = $document->save();
        $bytes = $this->decompressedContentStreamBytes($page);

        // Two distinct resource names on the page, both pointing at the same underlying object.
        self::assertStringContainsString('/Im1 Do', $bytes);
        self::assertStringContainsString('/Im2 Do', $bytes);
        self::assertSame(1, substr_count($output, '/Subtype /Image'));
    }

    public function testDistinctImagesAreNotDeduplicated(): void
    {
        $document = new Document();
        $page = $document->newPage();
        $builder = new PageBuilder($document, $page);

        $builder->drawJpeg(self::FIXTURES . '/sample.jpg', 0, 0, 10, 10);
        $builder->drawPng(self::FIXTURES . '/sample.png', 20, 0, 10, 10);

        $output = $document->save();
        self::assertSame(2, substr_count($output, '/Subtype /Image'));
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

    /**
     * Regression: /DR is one document-wide dictionary, so form-font
     * resource names must be allocated document-wide too. When the
     * naming lived on the per-page PageBuilder the counter restarted
     * each page, so page 2's font was also named /F1 and overwrote
     * page 1's /DR entry -- leaving page 1's field rendering in page
     * 2's font.
     */
    public function testFormFieldsOnDifferentPagesGetDistinctDrFontNames(): void
    {
        $document = new Document();
        $page1 = $document->newPage();
        $page2 = $document->newPage();

        (new PageBuilder($document, $page1))
            ->addTextField('A', 0, 0, 50, 20, font: StandardFont::Helvetica);
        (new PageBuilder($document, $page2))
            ->addTextField('B', 0, 0, 50, 20, font: StandardFont::Courier);

        $output = $document->save();

        preg_match('/\/DR << \/Font << ([^>]*) >>/', $output, $dr);
        preg_match_all('/\/(F\d+) (\d+) 0 R/', $dr[1], $entries, PREG_SET_ORDER);

        // Two different fonts must occupy two different /DR slots...
        self::assertCount(2, $entries);
        self::assertSame(['F1', 'F2'], [$entries[0][1], $entries[1][1]]);

        // ...pointing at genuinely different font objects...
        self::assertNotSame($entries[0][2], $entries[1][2]);

        // ...and each field's /DA must name its own slot.
        preg_match_all('/\/DA \((\/F\d+) [^)]*\)/', $output, $das);
        self::assertSame(['/F1', '/F2'], $das[1]);

        // The /DR slots resolve to the fonts actually asked for.
        self::assertMatchesRegularExpression(
            '/' . $entries[0][2] . ' 0 obj\s*<< [^>]*\/BaseFont \/Helvetica/',
            $output,
        );
        self::assertMatchesRegularExpression(
            '/' . $entries[1][2] . ' 0 obj\s*<< [^>]*\/BaseFont \/Courier/',
            $output,
        );
    }

    public function testSameFormFontAcrossPagesReusesOneDrSlot(): void
    {
        $document = new Document();

        foreach (range(1, 3) as $i) {
            (new PageBuilder($document, $document->newPage()))
                ->addTextField("field$i", 0, 0, 50, 20, font: StandardFont::Helvetica);
        }

        $output = $document->save();

        preg_match('/\/DR << \/Font << ([^>]*) >>/', $output, $dr);
        self::assertSame(1, substr_count($dr[1], ' 0 R'));
        self::assertSame(3, substr_count($output, '/DA (/F1 '));
    }

    public function testRepeatedFontAcrossPagesReusesOneFontObject(): void
    {
        $document = new Document();

        foreach (range(1, 4) as $i) {
            (new PageBuilder($document, $document->newPage()))
                ->drawText(StandardFont::Helvetica, 12.0, 72.0, 700.0, "page $i")
                ->drawText(StandardFont::TimesRoman, 12.0, 72.0, 680.0, "also page $i");
        }

        $output = $document->save();

        // Two fonts used on four pages => two font objects, not eight.
        self::assertSame(2, substr_count($output, '/Type /Font'));
        self::assertSame(1, substr_count($output, '/BaseFont /Helvetica'));
        self::assertSame(1, substr_count($output, '/BaseFont /Times-Roman'));

        // Every page still names both fonts in its own /Resources.
        self::assertSame(4, substr_count($output, '/Font << /F1 '));
    }

    public function testDistinctFontsAreNotDeduplicated(): void
    {
        $document = new Document();
        $builder = new PageBuilder($document, $document->newPage());

        $builder->drawText(StandardFont::Helvetica, 12.0, 72.0, 700.0, 'a')
            ->drawText(StandardFont::HelveticaBold, 12.0, 72.0, 680.0, 'b');

        $output = $document->save();

        self::assertSame(2, substr_count($output, '/Type /Font'));
        self::assertStringContainsString('/BaseFont /Helvetica ', $output);
        self::assertStringContainsString('/BaseFont /Helvetica-Bold', $output);
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

    public function testAddCheckboxWithCustomExportValue(): void
    {
        $document = new Document();
        $page = $document->newPage();
        (new PageBuilder($document, $page))->addCheckbox('Term1', 72, 700, 12, checked: true, exportValue: 'Q1');

        $output = $document->save();

        self::assertStringContainsString('/AS /Q1', $output);
    }

    public function testAddTextFieldWithAlignMultilineAndReadonly(): void
    {
        $document = new Document();
        $page = $document->newPage();
        (new PageBuilder($document, $page))->addTextField(
            'Notes',
            72,
            700,
            200,
            60,
            align: TextField::ALIGN_CENTER,
            multiline: true,
            readonly: true,
        );

        $output = $document->save();

        self::assertStringContainsString('/Q 1', $output);
        self::assertStringContainsString('/Ff 4097', $output);
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
