<?php

declare(strict_types=1);

namespace MightyPDF\Tests\Content;

use MightyPDF\Assembler\Dictionary;
use MightyPDF\Assembler\Document;
use MightyPDF\Assembler\Form\TextField;
use MightyPDF\Assembler\Page;
use MightyPDF\Assembler\Types\PdfNumberFormat;
use MightyPDF\Assembler\Types\PdfReference;
use MightyPDF\Content\ContentStream;
use MightyPDF\Content\Font\EmbeddedFont;
use MightyPDF\Content\Font\StandardFont;
use MightyPDF\Content\Font\TrueType\FontException;
use MightyPDF\Content\Text\HorizontalAlign;
use MightyPDF\Content\Text\VerticalAlign;
use MightyPDF\Content\PageBuilder;
use MightyPDF\Tests\Support\SyntheticTrueTypeFont;
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

    /**
     * Both ends of the box, stated in the font's own metrics rather than
     * in numbers: the top baseline hangs the ascent from the top edge,
     * and the bottom one stands the descent on the bottom edge, so a
     * descender just touches it. The bottom used to be placed a whole
     * line height up from the edge instead, which left a single line
     * floating clear of the box it was aligned to.
     */
    public function testDrawParagraphValignTopAndBottomPositionTheBaselineDifferently(): void
    {
        $font = StandardFont::Courier;

        $document = new Document();
        $topPage = $document->newPage();
        (new PageBuilder($document, $topPage))->drawParagraph($font, 10.0, 0, 0, 100, 100, 'Hi', valign: 'T');

        $bottomDocument = new Document();
        $bottomPage = $bottomDocument->newPage();
        (new PageBuilder($bottomDocument, $bottomPage))->drawParagraph($font, 10.0, 0, 0, 100, 100, 'Hi', valign: 'B');

        self::assertStringContainsString(
            sprintf('1 0 0 1 0 %s Tm', PdfNumberFormat::format(100.0 - $font->ascentPt(10.0))),
            $this->decompressedContentStreamBytes($topPage),
        );
        self::assertStringContainsString(
            sprintf('1 0 0 1 0 %s Tm', PdfNumberFormat::format($font->descentPt(10.0))),
            $this->decompressedContentStreamBytes($bottomPage),
        );
    }

    /**
     * The README tells callers mixing drawParagraph() with drawText()
     * to place the latter's baseline at `y + height - ascentPt()`.
     * Pinned here against the font's own ascent rather than a hardcoded
     * number, so the documented formula cannot drift away from what the
     * method does.
     */
    public function testDrawParagraphFirstBaselineIsTheBoxTopLessTheFontsAscent(): void
    {
        $font = StandardFont::Helvetica;
        $document = new Document();
        $page = $document->newPage();

        (new PageBuilder($document, $page))->drawParagraph($font, 12.0, 20, 100, 200, 50, 'Hello');

        $expected = 100 + 50 - $font->ascentPt(12.0);

        self::assertStringContainsString(
            "1 0 0 1 20 $expected Tm",
            $this->decompressedContentStreamBytes($page),
        );
    }

    /**
     * The call that made "centre this in that box" something the library
     * does. Checked against the geometry rather than against the formula:
     * cap-middle means equal air above the capitals and below the
     * baseline, which is true of exactly one placement.
     */
    public function testDrawTextInBoxCentresOnTheCapHeightWhenAsked(): void
    {
        $font = StandardFont::Helvetica;
        $document = new Document();
        $page = $document->newPage();

        (new PageBuilder($document, $page))
            ->drawTextInBox($font, 48.0, 0, 100, 200, 90, 'B', valign: VerticalAlign::CapMiddle);

        preg_match('/1 0 0 1 [\d.]+ ([\d.]+) Tm/', $this->decompressedContentStreamBytes($page), $matches);
        $baseline = (float) $matches[1];

        self::assertEqualsWithDelta(
            $baseline - 100.0,
            190.0 - ($baseline + $font->capHeightPt(48.0)),
            1e-4,
        );
    }

    public function testDrawTextInBoxAlignsHorizontallyWithinTheBox(): void
    {
        $document = new Document();
        $page = $document->newPage();

        // "Hi" is 12pt wide in Courier at 10pt, so a 100pt box centres it at 44.
        (new PageBuilder($document, $page))
            ->drawTextInBox(StandardFont::Courier, 10.0, 0, 0, 100, 20, 'Hi', HorizontalAlign::Center);

        self::assertStringContainsString('1 0 0 1 44 ', $this->decompressedContentStreamBytes($page));
    }

    /**
     * The gap that forced hand-wrapping before: a wrapped single line
     * and an unwrapped one could not be lined up, because one was placed
     * by ascent and the other by box height. Both now ask TextPlacement.
     */
    public function testDrawTextInBoxAndDrawParagraphAgreeOnASingleLine(): void
    {
        foreach (VerticalAlign::cases() as $valign) {
            $boxDocument = new Document();
            $boxPage = $boxDocument->newPage();
            (new PageBuilder($boxDocument, $boxPage))
                ->drawTextInBox(StandardFont::Helvetica, 14.0, 20, 100, 200, 60, 'One line', valign: $valign);

            $paragraphDocument = new Document();
            $paragraphPage = $paragraphDocument->newPage();
            (new PageBuilder($paragraphDocument, $paragraphPage))
                ->drawParagraph(StandardFont::Helvetica, 14.0, 20, 100, 200, 60, 'One line', valign: $valign);

            self::assertSame(
                $this->firstTextMatrix($boxPage),
                $this->firstTextMatrix($paragraphPage),
                $valign->name,
            );
        }
    }

    public function testDrawParagraphStillAcceptsItsOriginalStringAlignments(): void
    {
        $stringDocument = new Document();
        $stringPage = $stringDocument->newPage();
        (new PageBuilder($stringDocument, $stringPage))
            ->drawParagraph(StandardFont::Courier, 10.0, 0, 0, 40, 100, 'Hi', align: 'R', valign: 'B');

        $enumDocument = new Document();
        $enumPage = $enumDocument->newPage();
        (new PageBuilder($enumDocument, $enumPage))->drawParagraph(
            StandardFont::Courier,
            10.0,
            0,
            0,
            40,
            100,
            'Hi',
            align: HorizontalAlign::Right,
            valign: VerticalAlign::Bottom,
        );

        self::assertSame($this->firstTextMatrix($stringPage), $this->firstTextMatrix($enumPage));
    }

    public function testDrawTextAtTheDocumentedOffsetSharesDrawParagraphsFirstBaseline(): void
    {
        $font = StandardFont::Helvetica;

        $paragraphDocument = new Document();
        $paragraphPage = $paragraphDocument->newPage();
        (new PageBuilder($paragraphDocument, $paragraphPage))
            ->drawParagraph($font, 12.0, 20, 100, 200, 50, 'Hello');

        $textDocument = new Document();
        $textPage = $textDocument->newPage();
        (new PageBuilder($textDocument, $textPage))
            ->drawText($font, 12.0, 20, 100 + 50 - $font->ascentPt(12.0), 'Hello');

        self::assertSame(
            $this->firstTextMatrix($paragraphPage),
            $this->firstTextMatrix($textPage),
        );
    }

    /**
     * The offset is the *font's* ascent, not a fraction of the size --
     * which is why the README warns that a row mixing the two kinds of
     * font drifts. Asserted as the same formula over an embedded font
     * rather than as a difference between two: the synthetic fixture
     * font happens to declare 0.8 of the em, the very ratio
     * StandardFont assumes, so a difference test would prove nothing
     * about either.
     */
    public function testTheFirstBaselineOffsetIsTheFontsOwnAscentForAnEmbeddedFontToo(): void
    {
        $font = self::embeddedFont();
        $document = new Document();
        $page = $document->newPage();

        // 'AB' rather than prose: the synthetic font's cmap covers only
        // a handful of code points (see SyntheticTrueTypeFont).
        (new PageBuilder($document, $page))->drawParagraph($font, 12.0, 20, 100, 200, 50, 'AB');

        $expected = 100 + 50 - $font->ascentPt(12.0);

        self::assertStringContainsString(
            "1 0 0 1 20 $expected Tm",
            $this->decompressedContentStreamBytes($page),
        );
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
        self::assertStringContainsString('/NeedAppearances true', $output);

        // The field's /DA references a font resource that must resolve
        // via AcroForm's /DR, not the page's own /Resources.
        self::assertStringContainsString('/DR << /Font <<', $output);
    }

    /**
     * A rejected image used to consume an object id it never registered,
     * so anything allocated afterwards left that id stranded mid-range and
     * every later save() died with "Xref has a gap" -- an error naming
     * nothing to do with the image the caller already handled.
     */
    public function testRejectedImageDoesNotBreakTheRestOfTheDocument(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'mightypdf-bad-image');
        file_put_contents($path, 'this is not a JPEG at all');

        $document = new Document();
        $builder = new PageBuilder($document, $document->newPage());
        $builder->drawText(StandardFont::Helvetica, 12.0, 50.0, 700.0, 'Report');

        try {
            $builder->drawJpeg($path, 0.0, 0.0, 100.0, 100.0);
            self::fail('Expected the malformed JPEG to be rejected.');
        } catch (\InvalidArgumentException) {
            // Expected -- the caller handles it and keeps building.
        } finally {
            unlink($path);
        }

        // Allocates a later object id, which is what strands the leaked one.
        $builder->addTextField('email', 50.0, 600.0, 200.0, 20.0);

        $output = $document->save();

        self::assertStringContainsString('/T (email)', $output);
        self::assertStringContainsString('%%EOF', $output);
    }

    public function testDrawingTheSameFileAsADifferentFormatStillValidatesIt(): void
    {
        $document = new Document();
        $builder = new PageBuilder($document, $document->newPage());

        $builder->drawGif(self::FIXTURES . '/sample.gif', 0.0, 0.0, 50.0, 50.0);

        // A cache hit must not stand in for the format check: this file is
        // a GIF, so asking for it as a PNG has to be rejected.
        $this->expectException(\InvalidArgumentException::class);
        $builder->drawPng(self::FIXTURES . '/sample.gif', 0.0, 60.0, 50.0, 50.0);
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

        // Exactly one AcroForm object (one /NeedAppearances declaration),
        // and its /Fields array lists both pages' fields together.
        self::assertSame(1, preg_match_all('/\/NeedAppearances true/', $output));
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

    public function testAddListBoxListsOptionsAndSelectedValue(): void
    {
        $document = new Document();
        $page = $document->newPage();
        (new PageBuilder($document, $page))->addListBox(
            'Country',
            ['USA', 'Canada', 'Mexico'],
            72,
            700,
            150,
            60,
            value: 'Canada',
        );

        $output = $document->save();

        self::assertStringContainsString('/FT /Ch', $output);
        self::assertStringContainsString('/Opt [(USA) (Canada) (Mexico)]', $output);
        self::assertStringContainsString('/V (Canada)', $output);
        self::assertStringNotContainsString('/Ff ', $output, 'a list box carries no flags unless read-only');
    }

    public function testAddDropdownSetsTheComboFlag(): void
    {
        $document = new Document();
        $page = $document->newPage();
        (new PageBuilder($document, $page))->addDropdown(
            'Shipping',
            ['Standard', 'Express'],
            72,
            700,
            150,
            20,
            value: 'Express',
        );

        $output = $document->save();

        self::assertStringContainsString('/FT /Ch', $output);
        // Table 230 bit 18, "Combo" -- 1 << 17.
        self::assertStringContainsString('/Ff 131072', $output);
        self::assertStringContainsString('/V (Express)', $output);
    }

    public function testChoiceFieldWiresFontIntoAcroFormDrNotPageResources(): void
    {
        $document = new Document();
        $page = $document->newPage();
        (new PageBuilder($document, $page))->addListBox('Country', ['USA', 'Canada'], 72, 700, 150, 60);

        $output = $document->save();

        self::assertStringContainsString('/DR << /Font <<', $output);
    }

    /**
     * A field's font need not be one of the standard 14 -- what it must
     * be is complete, since the reader lays out text typed into it that
     * this document never drew.
     */
    public function testAFieldCanUseAnEmbeddedFont(): void
    {
        $document = new Document();
        $page = $document->newPage();
        $font = EmbeddedFont::fromBytes(SyntheticTrueTypeFont::build(), subset: false);

        (new PageBuilder($document, $page))->addTextField('name', 72, 700, 200, 20, font: $font);

        $output = $document->save();

        self::assertStringContainsString('/DA (/F1 ', $output);
        self::assertStringContainsString('/Subtype /Type0', $output);
        self::assertStringContainsString('/BaseFont /SyntheticTest', $output);

        // The /DR entry has to point at the font object itself, which is
        // the one thing a field's /DA cannot resolve without.
        preg_match('/\/DR << \/Font << \/F1 (\d+) 0 R/', $output, $dr);
        self::assertNotEmpty($dr, 'the embedded font was not registered in /DR');
        self::assertMatchesRegularExpression(
            '/' . $dr[1] . ' 0 obj\s*<< \/Type \/Font \/Subtype \/Type0/',
            $output,
        );
    }

    /**
     * A subset holds only what the document drew, so a field pointed at
     * one is missing exactly the characters someone types into it. The
     * failure would otherwise appear when the form is filled in, long
     * after the document was written.
     */
    public function testAFieldRefusesASubsetFont(): void
    {
        $document = new Document();
        $builder = new PageBuilder($document, $document->newPage());

        $this->expectException(FontException::class);
        $this->expectExceptionMessageMatches('/subset.*subset: false/s');

        $builder->addTextField('name', 72, 700, 200, 20, font: EmbeddedFont::fromBytes(SyntheticTrueTypeFont::build()));
    }

    public function testAnEmbeddedFieldFontIsSharedWithTheTextDrawnInIt(): void
    {
        $document = new Document();
        $font = EmbeddedFont::fromBytes(SyntheticTrueTypeFont::build(), subset: false);

        (new PageBuilder($document, $document->newPage()))
            ->drawText($font, 12.0, 72, 720, 'AB')
            ->addTextField('name', 72, 700, 200, 20, font: $font);

        // One font object, named in both the page's /Resources and the
        // form's /DR -- the two namings are separate, the object is not.
        self::assertSame(1, substr_count($document->save(), '/Subtype /Type0'));
    }

    public function testAddSignatureFieldHasNoValueAndIsAnAcroFormField(): void
    {
        $document = new Document();
        $page = $document->newPage();
        (new PageBuilder($document, $page))->addSignatureField('Signature', 72, 700, 200, 40);

        $output = $document->save();

        self::assertStringContainsString('/FT /Sig', $output);
        self::assertStringNotContainsString('/V ', $output);

        preg_match('/\/Fields \[([^\]]*)\]/', $output, $fields);
        self::assertSame(1, substr_count($fields[1], ' 0 R'));
    }

    public function testResultingPdfWithChoiceAndSignatureFieldsIsStructurallyValid(): void
    {
        $document = new Document();
        $page = $document->newPage();
        (new PageBuilder($document, $page))
            ->addListBox('Country', ['USA', 'Canada'], 72, 700, 150, 60, value: 'USA')
            ->addDropdown('Shipping', ['Standard', 'Express'], 72, 660, 150, 20, value: 'Standard')
            ->addSignatureField('Signature', 72, 600, 200, 40);

        $output = $document->save();

        $objCount = preg_match_all('/\d+ 0 obj/', $output);
        $endobjCount = preg_match_all('/endobj/', $output);
        self::assertSame($objCount, $endobjCount);

        preg_match_all('/^(\d{10}) \d{5} n \n/m', $output, $matches);
        foreach ($matches[1] as $offsetString) {
            self::assertMatchesRegularExpression('/^\d+ 0 obj/', substr($output, (int) $offsetString, 20));
        }
    }

    public function testDrawSvgRendersIntoAFormXObjectThePageInvokes(): void
    {
        $document = new Document();
        $page = $document->newPage();
        $builder = new PageBuilder($document, $page);

        $builder->drawSvg(__DIR__ . '/../fixtures/svg/sample.svg', 100, 100, 200, 200);

        $output = $document->save();

        // All the page says is "draw that one".
        self::assertStringContainsString('/Im1 Do', $this->decompressedContentStreamBytes($page));

        $bytes = $this->svgFormBytes($page, $output);
        self::assertStringContainsString('cm', $bytes);
        self::assertStringContainsString('re', $bytes);
        self::assertStringContainsString('1 0 0 rg', $bytes); // red rect
    }

    /**
     * The case the XObject exists for: the same drawing placed the same
     * way on page after page -- a logo, a letterhead -- is one drawing,
     * not one per page. It used to be re-read, re-parsed and re-rendered
     * every time, and to register a fresh set of gradient objects with
     * each placement.
     */
    public function testTheSameSvgPlacedTheSameWayIsDrawnOnce(): void
    {
        $document = new Document();
        $builder = null;

        for ($i = 0; $i < 5; $i++) {
            $builder = new PageBuilder($document, $document->newPage());
            $builder->drawSvg(__DIR__ . '/../fixtures/svg/gradient.svg', 20, 20, 100, 100);
        }

        $output = $document->save();

        self::assertSame(1, substr_count($output, '/Subtype /Form'), 'one drawing for five placements');
        self::assertSame(1, substr_count($output, '/ShadingType 2'), 'and one gradient with it');
    }

    /**
     * Reuse is on the placement as well as the file. A gradient is
     * painted through a pattern, and pattern space is fixed to the page
     * rather than to the CTM, so the placement is folded into the
     * pattern matrices inside the drawing -- two placements that differ
     * are two different drawings, and sharing them would put the second
     * one's gradient where the first one's was.
     */
    public function testTheSameSvgPlacedElsewhereIsDrawnAgain(): void
    {
        $document = new Document();
        $builder = new PageBuilder($document, $document->newPage());

        $builder->drawSvg(__DIR__ . '/../fixtures/svg/gradient.svg', 20, 20, 100, 100);
        $builder->drawSvg(__DIR__ . '/../fixtures/svg/gradient.svg', 300, 400, 100, 100);

        self::assertSame(2, substr_count($document->save(), '/Subtype /Form'));
    }

    /**
     * A drawing carries its own /Resources, which is what lets one
     * XObject be placed on a page that never named the fonts, gradients
     * or images inside it. The page names nothing but the drawing.
     */
    public function testAnSvgCarriesItsOwnResourcesRatherThanThePages(): void
    {
        $document = new Document();
        $page = $document->newPage();

        (new PageBuilder($document, $page))->drawSvg(__DIR__ . '/../fixtures/svg/gradient.svg', 0, 0, 200, 200);

        $document->save();

        $pageResources = $page->resources();
        self::assertNotNull($pageResources->get('XObject'), 'the page names the drawing');
        self::assertNull($pageResources->get('Pattern'), 'and nothing from inside it');
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

    /**
     * Two-byte character codes go out as a hex string: a literal string
     * would need every byte that happens to be "(", ")" or "\" escaped,
     * and half of every code is a high byte where those values are
     * ordinary.
     */
    public function testTextInAnEmbeddedFontIsShownAsAHexString(): void
    {
        $document = new Document();
        $page = $document->newPage();

        (new PageBuilder($document, $page))->drawText(self::embeddedFont(), 12.0, 72.0, 700.0, 'AB');

        self::assertStringContainsString('<00010002> Tj', $this->decompressedContentStreamBytes($page));
    }

    /**
     * Word spacing (Tw) has no effect on two-byte codes at all, so
     * justified text in an embedded font is spaced with TJ adjustments
     * instead. Emitting Tw there would not be an error -- it would
     * silently produce unjustified text.
     */
    public function testJustifiedParagraphsInAnEmbeddedFontUseTjAdjustmentsRatherThanWordSpacing(): void
    {
        $document = new Document();
        $page = $document->newPage();

        (new PageBuilder($document, $page))->drawParagraph(
            self::embeddedFont(),
            12.0,
            72.0,
            600.0,
            60.0,
            60.0,
            "A B A B A\nB",
            align: 'J',
        );

        $content = $this->decompressedContentStreamBytes($page);

        self::assertStringContainsString('] TJ', $content);
        self::assertStringNotContainsString('Tw', $content);
    }

    public function testJustifiedParagraphsInAStandardFontStillUseWordSpacing(): void
    {
        $document = new Document();
        $page = $document->newPage();

        (new PageBuilder($document, $page))->drawParagraph(
            StandardFont::Helvetica,
            12.0,
            72.0,
            600.0,
            60.0,
            60.0,
            "one two three four five six\nseven",
            align: 'J',
        );

        self::assertStringContainsString('Tw', $this->decompressedContentStreamBytes($page));
    }

    public function testAnSvgGradientBecomesAPatternResourceOnThePage(): void
    {
        $document = new Document();
        $page = $document->newPage();

        (new PageBuilder($document, $page))->drawSvg(self::FIXTURES . '/../svg/gradient.svg', 0, 0, 200, 200);

        $output = $document->save();

        self::assertStringContainsString('/Pattern << /P1', $output);
        self::assertStringContainsString('/PatternType 2', $output);
        self::assertStringContainsString('/ShadingType 2', $output);
        self::assertStringContainsString('/Pattern cs', $this->svgFormBytes($page, $output));
    }

    public function testAnSvgPatternBecomesATilingPatternResourceOnThePage(): void
    {
        $document = new Document();
        $page = $document->newPage();

        (new PageBuilder($document, $page))->drawSvg(self::FIXTURES . '/../svg/pattern.svg', 0, 0, 200, 200);

        $output = $document->save();

        self::assertStringContainsString('/PatternType 1', $output);
        self::assertStringContainsString('/XStep 20', $output);
        self::assertStringContainsString('/Pattern cs', $this->svgFormBytes($page, $output));
    }

    /**
     * A pattern is painted per shape, and a tiling pattern object says
     * only what its tile, its placement and its content are -- so shapes
     * agreeing on all three want the one object rather than one each.
     * Building one per shape cost an object and a copy of the page's
     * resources every time: a thousand pattern-filled shapes made a
     * seven-megabyte document out of fifty-seven kilobytes of SVG.
     */
    public function testShapesPaintedTheSameWayShareOneTilingPattern(): void
    {
        $document = new Document();
        $page = $document->newPage();

        // Three shapes, one pattern, measured in user space -- so the
        // tile is the same wherever the shape is and whatever size it is.
        $svg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 100 100"><defs>'
            . '<pattern id="dots" width="10" height="10" patternUnits="userSpaceOnUse">'
            . '<circle cx="5" cy="5" r="4" fill="#3b5bdb"/></pattern></defs>'
            . '<rect x="0" y="0" width="30" height="30" fill="url(#dots)"/>'
            . '<rect x="40" y="0" width="50" height="20" fill="url(#dots)"/>'
            . '<circle cx="50" cy="70" r="25" fill="url(#dots)"/></svg>';

        $path = tempnam(sys_get_temp_dir(), 'mightypdf') . '.svg';
        file_put_contents($path, $svg);

        try {
            (new PageBuilder($document, $page))->drawSvg($path, 0, 0, 200, 200);
        } finally {
            unlink($path);
        }

        $output = $document->save();

        self::assertSame(1, substr_count($output, '/PatternType 1'), 'one object for three identical fills');
        self::assertSame(3, substr_count($this->svgFormBytes($page, $output), '/P1 scn'));
    }

    /**
     * The sharing is on what the tile *is*, not on which pattern it came
     * from: in objectBoundingBox units the content is measured against
     * the shape, so two shapes of different sizes need two tiles.
     */
    public function testShapesOfDifferentSizesStillGetTheirOwnTile(): void
    {
        $document = new Document();
        $page = $document->newPage();

        $svg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 100 100"><defs>'
            . '<pattern id="dots" width="0.25" height="0.25" patternContentUnits="objectBoundingBox">'
            . '<circle cx="0.1" cy="0.1" r="0.05" fill="#3b5bdb"/></pattern></defs>'
            . '<rect x="0" y="0" width="30" height="30" fill="url(#dots)"/>'
            . '<rect x="40" y="0" width="50" height="20" fill="url(#dots)"/></svg>';

        $path = tempnam(sys_get_temp_dir(), 'mightypdf') . '.svg';
        file_put_contents($path, $svg);

        try {
            (new PageBuilder($document, $page))->drawSvg($path, 0, 0, 200, 200);
        } finally {
            unlink($path);
        }

        self::assertSame(2, substr_count($document->save(), '/PatternType 1'));
    }

    /**
     * A tiling pattern's operators live in a stream of their own, so the
     * names they use have to resolve in that stream's /Resources rather
     * than in the drawing's -- and the copy must not include the pattern
     * itself, which would be a dictionary containing itself. Ghostscript
     * reports that as a circular reference; poppler renders it and says
     * nothing.
     *
     * What comes across is the *drawing's* resources, not the page's:
     * the tile was drawn inside a form XObject with a scope of its own,
     * and the page's own fonts and images were never in scope to be
     * copied. That is both narrower and more correct than copying the
     * page -- a tile can only ever name what the drawing named.
     */
    public function testATilingPatternCarriesTheDrawingsResourcesWithoutContainingItself(): void
    {
        $png = base64_encode((string) file_get_contents(self::FIXTURES . '/sample.png'));

        // The tile itself uses an image, so there is something in the
        // drawing's resources that the tile genuinely needs.
        $svg = '<svg xmlns="http://www.w3.org/2000/svg" xmlns:xlink="http://www.w3.org/1999/xlink" viewBox="0 0 100 100"><defs>'
            . '<pattern id="tiles" x="0" y="0" width="20" height="20" patternUnits="userSpaceOnUse">'
            . '<image x="0" y="0" width="20" height="20" xlink:href="data:image/png;base64,' . $png . '"/>'
            . '</pattern></defs>'
            . '<rect x="10" y="10" width="80" height="80" fill="url(#tiles)"/></svg>';

        $path = tempnam(sys_get_temp_dir(), 'mightypdf') . '.svg';
        file_put_contents($path, $svg);

        $document = new Document();
        $page = $document->newPage();
        $builder = new PageBuilder($document, $page);

        // An unrelated image on the page, which the tile must *not*
        // inherit just by being drawn on the same page.
        $builder->drawPng(self::FIXTURES . '/sample.png', 0, 0, 10, 10);

        try {
            $builder->drawSvg($path, 0, 0, 200, 200);
        } finally {
            unlink($path);
        }

        $output = $document->save();

        preg_match('/\/PatternType 1[^>]*\/Resources << (.*?) >> \/Matrix/s', $output, $resources);
        self::assertNotEmpty($resources, 'the tiling pattern has no /Resources');
        self::assertStringContainsString('/XObject', $resources[1], 'the image the tile draws came across');
        self::assertStringNotContainsString('/Pattern <<', $resources[1], 'the pattern lists itself');
    }

    /**
     * A PDF colour carries no transparency, so a gradient with a
     * transparent stop is drawn twice: in colour as a shading pattern,
     * and in greyscale as a luminosity mask on the graphics state.
     */
    public function testAFadingSvgGradientGetsASoftMaskOnThePage(): void
    {
        $svg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 10 10"><defs>'
            . '<linearGradient id="fade"><stop offset="0" stop-color="#000000" stop-opacity="1"/>'
            . '<stop offset="1" stop-color="#000000" stop-opacity="0"/></linearGradient></defs>'
            . '<rect x="0" y="0" width="10" height="10" fill="url(#fade)"/></svg>';

        $path = tempnam(sys_get_temp_dir(), 'mightypdf-svg') . '.svg';
        file_put_contents($path, $svg);

        try {
            $document = new Document();
            $page = $document->newPage();
            (new PageBuilder($document, $page))->drawSvg($path, 0, 0, 200, 200);

            $output = $document->save();

            self::assertStringContainsString('/SMask << /Type /Mask /S /Luminosity', $output);
            self::assertStringContainsString('/CS /DeviceGray', $output);

            // Set inside the shape's own graphics state, so the mask does
            // not survive into whatever is drawn next.
            $bytes = $this->svgFormBytes($page, $output);
            self::assertStringContainsString("q\n/GS1 gs", $bytes);
            self::assertStringContainsString("f\nQ\n", $bytes);
        } finally {
            unlink($path);
        }
    }

    public function testARasterImageInsideAnSvgIsEmbeddedAsAnXObject(): void
    {
        $png = base64_encode((string) file_get_contents(self::FIXTURES . '/sample.png'));

        $svg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 100 100">'
            . '<image x="0" y="0" width="100" height="100" href="data:image/png;base64,' . $png . '"/></svg>';

        $path = tempnam(sys_get_temp_dir(), 'mightypdf-svg') . '.svg';
        file_put_contents($path, $svg);

        try {
            $document = new Document();
            $page = $document->newPage();
            (new PageBuilder($document, $page))->drawSvg($path, 0, 0, 200, 200);

            self::assertStringContainsString('/Subtype /Image', $document->save());
            self::assertStringContainsString('/Im1 Do', $this->decompressedContentStreamBytes($page));
        } finally {
            unlink($path);
        }
    }

    private static function embeddedFont(): EmbeddedFont
    {
        return EmbeddedFont::fromBytes(SyntheticTrueTypeFont::build());
    }

    /** The `x y Tm` of the first text-showing block on the page. */
    private function firstTextMatrix(Page $page): string
    {
        preg_match('/1 0 0 1 ([\d.-]+ [\d.-]+) Tm/', $this->decompressedContentStreamBytes($page), $matches);

        return $matches[1] ?? '';
    }

    private function decompressedContentStreamBytes(Page $page): string
    {
        $rendered = $page->contentStreams()[0]->render(true);
        preg_match('/stream\n(.*)\nendstream/s', $rendered, $matches);

        return gzuncompress($matches[1]);
    }

    /**
     * The operators of the form XObject a drawSvg() call produced.
     *
     * A drawing is placed as an XObject rather than appended to the page
     * (see PageBuilder::drawSvg(), and the reasons in its doc comment),
     * so what a drawing put on the page is a single "Do" and everything
     * worth asserting about is in here.
     *
     * Found by following the page's own /XObject rather than by looking
     * for the first "/Subtype /Form" in the file, which is not
     * necessarily this one -- a fading gradient's soft mask is a form
     * XObject too, and is written before the drawing that uses it.
     */
    private function svgFormBytes(Page $page, string $output, string $name = 'Im1'): string
    {
        $xObjects = $page->resources()->get('XObject');
        self::assertInstanceOf(Dictionary::class, $xObjects, 'the page names no XObject');

        $reference = $xObjects->get($name);
        self::assertInstanceOf(PdfReference::class, $reference, "the page has no /$name");

        preg_match(
            '/(?:^|\n)' . $reference->objectId() . ' 0 obj\n.*?stream\n(.*?)\nendstream/s',
            $output,
            $matches,
        );
        self::assertNotEmpty($matches, 'the drawing is not in the document');

        return gzuncompress($matches[1]);
    }
}
