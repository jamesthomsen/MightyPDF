<?php

declare(strict_types=1);

namespace MightyPDF\Tests\Editor;

use MightyPDF\Assembler\Dictionary;
use MightyPDF\Assembler\Document;
use MightyPDF\Assembler\Stream;
use MightyPDF\Assembler\Types\PdfArray;
use MightyPDF\Assembler\Types\PdfReference;
use MightyPDF\Content\Font\EmbeddedFont;
use MightyPDF\Content\Font\StandardFont;
use MightyPDF\Content\PageBuilder;
use MightyPDF\Editor\EditedDocument;
use MightyPDF\Editor\PageOverlay;
use MightyPDF\Editor\PdfEditor;
use MightyPDF\Reader\ObjectStore;
use MightyPDF\Tests\Support\SyntheticTrueTypeFont;
use PHPUnit\Framework\TestCase;

final class PageOverlayTest extends TestCase
{
    public function testDrawsIntoAFormXObjectSizedLikeThePage(): void
    {
        // Same rectangle the page is measured in, so that what the caller
        // draws at (72, 720) lands where it would on a fresh page.
        [$editor, $overlay] = self::overlayOn(self::pageWithContent());
        $overlay->content()->drawText(StandardFont::Helvetica, 12.0, 40, 40, 'stamp');
        $overlay->apply();

        $xObject = self::overlayXObject($editor);

        self::assertSame('XObject', $xObject->get('Type')?->value());
        self::assertSame('Form', $xObject->get('Subtype')?->value());
        self::assertSame('[0 0 400 400]', $xObject->get('BBox')?->format());
    }

    public function testTheOverlaysResourcesAreItsOwn(): void
    {
        // Naming a font in the page's own /Resources would risk /F1
        // already meaning something else there.
        [$editor, $overlay] = self::overlayOn(self::pageWithContent());
        $overlay->content()->drawText(StandardFont::Helvetica, 12.0, 40, 40, 'stamp');
        $overlay->apply();

        $resources = self::overlayXObject($editor)->get('Resources');
        self::assertInstanceOf(Dictionary::class, $resources);
        self::assertInstanceOf(Dictionary::class, $resources->get('Font'));

        // The page's own /Font is untouched by the overlay's.
        $pageFonts = $editor->resolveDictionary(
            $editor->resolveDictionary(self::firstPage($editor)->get('Resources'))?->get('Font'),
        );
        self::assertSame('5 0 R', $pageFonts?->get('F1')?->format());
    }

    public function testCopiesInheritedResourcesOntoThePageRatherThanWritingThroughThem(): void
    {
        // /Resources here lives on the page tree root and is shared with a
        // sibling page. Adding to it in place would put the overlay's
        // XObject on every page in the document.
        [$editor, $overlay] = self::overlayOn(self::pageWithContent());
        $overlay->content()->drawText(StandardFont::Helvetica, 12.0, 40, 40, 'stamp');
        $overlay->apply();

        $page = self::firstPage($editor);
        $own = $editor->resolveDictionary($page->get('Resources'));

        self::assertNotNull($own?->get('XObject'), 'the page now has its own resources');
        self::assertSame('5 0 R', $editor->resolveDictionary($own->get('Font'))?->get('F1')?->format());

        $tree = $editor->resolveDictionary($editor->catalog()->get('Pages'));
        self::assertNull(
            $editor->resolveDictionary($tree?->get('Resources'))?->get('XObject'),
            'the shared ancestor must be left alone',
        );
    }

    public function testBracketsExistingContentInSaveAndRestore(): void
    {
        // Content that leaves the graphics state dirty -- an unmatched q,
        // a lingering clip or colour -- would otherwise apply to the
        // overlay drawn after it.
        [$editor, $overlay] = self::overlayOn(self::pageWithContent());
        $overlay->content()->drawText(StandardFont::Helvetica, 12.0, 40, 40, 'stamp');
        $overlay->apply();

        $contents = self::firstPage($editor)->get('Contents');
        self::assertInstanceOf(PdfArray::class, $contents);

        $parts = array_map(
            static fn ($item): string => $item instanceof PdfReference ? '<ref>' : '?',
            $contents->items(),
        );

        self::assertCount(4, $parts, 'q, the original, Q, and the invocation');
        self::assertSame("q\n", self::streamAt($editor, $contents, 0));
        self::assertSame("Q\n", self::streamAt($editor, $contents, 2));
        self::assertStringContainsString('Do', self::streamAt($editor, $contents, 3));
    }

    public function testAPageWithNoContentGetsNoWrapper(): void
    {
        [$editor, $overlay] = self::overlayOn(self::pageWithContent(withContent: false));
        $overlay->content()->drawText(StandardFont::Helvetica, 12.0, 40, 40, 'stamp');
        $overlay->apply();

        $contents = self::firstPage($editor)->get('Contents');
        self::assertInstanceOf(PdfArray::class, $contents);
        self::assertCount(1, $contents->items(), 'nothing to bracket');
    }

    public function testPicksAResourceNameThePageIsNotAlreadyUsing(): void
    {
        [$editor, $overlay] = self::overlayOn(self::pageWithContent(existingXObject: true));
        $overlay->content()->drawText(StandardFont::Helvetica, 12.0, 40, 40, 'stamp');
        $overlay->apply();

        $xObjects = $editor->resolveDictionary(
            $editor->resolveDictionary(self::firstPage($editor)->get('Resources'))?->get('XObject'),
        );

        self::assertNotNull($xObjects?->get('MPOverlay0'), 'the existing entry survives');
        self::assertNotNull($xObjects->get('MPOverlay1'), 'and the overlay took the next free name');
    }

    public function testAnOverlayThatDrawsNothingChangesNothing(): void
    {
        $original = self::pageWithContent();
        [$editor, $overlay] = self::overlayOn($original);
        $overlay->apply();

        self::assertSame($original, $editor->save());
    }

    public function testReadsThePageGeometryThroughInheritance(): void
    {
        // /MediaBox and /Rotate live on the page tree root here, which is
        // where plenty of real files put them.
        [, $overlay] = self::overlayOn(self::pageWithContent());

        self::assertSame('[0 0 400 400]', $overlay->mediaBox()->format());
        self::assertSame(90, $overlay->rotation());
    }

    public function testNormalisesAnOddRotation(): void
    {
        [, $overlay] = self::overlayOn(self::pageWithContent(rotate: -90));

        self::assertSame(270, $overlay->rotation());
    }

    public function testRefusesToBeAppliedTwice(): void
    {
        [, $overlay] = self::overlayOn(self::pageWithContent());
        $overlay->content()->drawText(StandardFont::Helvetica, 12.0, 40, 40, 'stamp');
        $overlay->apply();

        $this->expectException(\LogicException::class);
        $overlay->apply();
    }

    public function testTheOverlaySurvivesSavingAndReopening(): void
    {
        $original = self::pageWithContent();
        [$editor, $overlay] = self::overlayOn($original);
        $overlay->content()->drawText(StandardFont::Helvetica, 12.0, 40, 40, 'A stamp');
        $overlay->apply();

        $saved = $editor->save();
        self::assertStringStartsWith($original, $saved);

        $store = new ObjectStore($saved);
        $page = self::firstPageOf($store);

        $contents = $page->get('Contents');
        self::assertInstanceOf(PdfArray::class, $contents);

        $xObjects = $store->resolveDictionary(
            $store->resolveDictionary($page->get('Resources'))?->get('XObject'),
        );
        $stamp = $store->resolve($xObjects?->get('MPOverlay0'));

        self::assertInstanceOf(Stream::class, $stamp);
        self::assertStringContainsString('(A stamp) Tj', $store->decodedStream($stamp));
    }

    public function testAddsAnnotationsToThePageWithoutLosingExistingOnes(): void
    {
        [$editor, $overlay] = self::overlayOn(self::pageWithContent(withAnnots: true));
        $overlay->addAnnotation(99);
        $overlay->apply();

        $annots = $editor->resolve(self::firstPage($editor)->get('Annots'));
        self::assertInstanceOf(PdfArray::class, $annots);
        self::assertCount(2, $annots->items());
        self::assertSame('99 0 R', $annots->items()[1]->format());
    }

    public function testCreatesAFormForADocumentThatHasNone(): void
    {
        $editor = PdfEditor::fromBytes(self::pageWithContent());
        $form = (new EditedDocument($editor))->acroForm();

        self::assertSame(
            $form->objectId(),
            $editor->catalog()->get('AcroForm')?->objectId(),
            'the catalog must point at it',
        );
    }

    public function testGivesTheSameFormBackOnEveryCall(): void
    {
        // Two forms would mean the catalog pointing at one of them and
        // the fields in the other simply not being there.
        $document = new EditedDocument(PdfEditor::fromBytes(self::pageWithContent()));

        self::assertSame($document->acroForm(), $document->acroForm());
    }

    public function testDrawsOntoADocumentThisLibraryWrote(): void
    {
        // End to end against a real page rather than a hand-built fixture.
        $document = new Document();
        $page = $document->newPage();
        (new PageBuilder($document, $page))->drawText(StandardFont::Helvetica, 12.0, 72, 720, 'Original');

        $editor = PdfEditor::fromBytes($document->save());
        $overlay = new PageOverlay($editor, self::firstPage($editor));
        $overlay->content()->fillRectangle(x: 40, y: 40, width: 100, height: 20, r: 1.0, g: 0.0, b: 0.0);
        $overlay->apply();

        $store = new ObjectStore($editor->save());
        $xObjects = $store->resolveDictionary(
            $store->resolveDictionary(self::firstPageOf($store)->get('Resources'))?->get('XObject'),
        );

        self::assertInstanceOf(Stream::class, $store->resolve($xObjects?->get('MPOverlay0')));
    }

    /**
     * A drawing is a form XObject of its own, and an overlay is another
     * one, so stamping an SVG onto an existing page nests the two -- with
     * the drawing named in the overlay's resources rather than in those
     * of a page that has never heard of it.
     *
     * The gradient is what makes this worth pinning: it is painted
     * through a pattern, which has to be reachable from the drawing's
     * own /Resources for a reader to find it two levels down.
     */
    public function testAnSvgStampedOntoAnExistingPageNestsInsideTheOverlay(): void
    {
        $document = new Document();
        (new PageBuilder($document, $document->newPage()))
            ->drawText(StandardFont::Helvetica, 12.0, 72, 720, 'Original');

        $editor = PdfEditor::fromBytes($document->save());
        $overlay = new PageOverlay($editor, self::firstPage($editor));
        $overlay->content()->drawSvg(__DIR__ . '/../fixtures/svg/gradient.svg', 100, 500, 150, 150);
        $overlay->apply();

        $store = new ObjectStore($editor->save());
        $overlayForm = $store->resolve(
            $store->resolveDictionary(
                $store->resolveDictionary(self::firstPageOf($store)->get('Resources'))?->get('XObject'),
            )?->get('MPOverlay0'),
        );

        self::assertInstanceOf(Stream::class, $overlayForm);

        $drawing = $store->resolve(
            $store->resolveDictionary(
                $store->resolveDictionary($overlayForm->get('Resources'))?->get('XObject'),
            )?->get('Im1'),
        );

        self::assertInstanceOf(Stream::class, $drawing, 'the drawing is not inside the overlay');
        self::assertNotNull(
            $store->resolveDictionary($drawing->get('Resources'))?->get('Pattern'),
            'the drawing does not carry the gradient it paints with',
        );
    }

    /** @return array{0: PdfEditor, 1: PageOverlay} */
    /**
     * An embedded font's program cannot be built until the document has
     * stopped growing, so it is filled in during a finalize pass at save
     * time. An incremental update is a second, separate writer with its
     * own save path, and forgetting the pass there does not fail loudly:
     * it writes a font whose /FontFile2 is an empty stream, which some
     * readers show as blank text and others as nothing at all.
     */
    public function testAnEmbeddedFontStampedOntoAnExistingPageIsWrittenComplete(): void
    {
        [$editor, $overlay] = self::overlayOn(self::pageWithContent());
        $overlay->content()->drawText(
            EmbeddedFont::fromBytes(SyntheticTrueTypeFont::build()),
            12.0,
            40,
            40,
            'AB',
        );
        $overlay->apply();

        $update = $editor->save();

        self::assertStringContainsString('/Subtype /CIDFontType2', $update);
        self::assertStringContainsString('/W [1 [600 700]]', $update);
        self::assertSame(1, preg_match('/\/Length1 (\d+)/', $update, $matches));
        self::assertGreaterThan(0, (int) $matches[1], 'the font program was never written');
    }

    private static function overlayOn(string $pdf): array
    {
        $editor = PdfEditor::fromBytes($pdf);

        return [$editor, new PageOverlay($editor, self::firstPage($editor))];
    }

    private static function firstPage(PdfEditor $editor): Dictionary
    {
        $tree = $editor->resolveDictionary($editor->catalog()->get('Pages'));
        self::assertNotNull($tree);

        $kids = $tree->get('Kids');
        self::assertInstanceOf(PdfArray::class, $kids);

        $page = $editor->resolveDictionary($kids->items()[0]);
        self::assertNotNull($page);

        return $page;
    }

    private static function firstPageOf(ObjectStore $store): Dictionary
    {
        $tree = $store->resolveDictionary($store->catalog()->get('Pages'));
        $kids = $tree?->get('Kids');
        self::assertInstanceOf(PdfArray::class, $kids);

        $page = $store->resolveDictionary($kids->items()[0]);
        self::assertNotNull($page);

        return $page;
    }

    private static function overlayXObject(PdfEditor $editor): Stream
    {
        $xObjects = $editor->resolveDictionary(
            $editor->resolveDictionary(self::firstPage($editor)->get('Resources'))?->get('XObject'),
        );

        $stream = $editor->resolve($xObjects?->get('MPOverlay0'));
        self::assertInstanceOf(Stream::class, $stream);

        return $stream;
    }

    private static function streamAt(PdfEditor $editor, PdfArray $contents, int $index): string
    {
        $stream = $editor->resolve($contents->items()[$index]);
        self::assertInstanceOf(Stream::class, $stream);

        return $stream->rawBytes();
    }

    /**
     * Two pages sharing /Resources, /MediaBox and /Rotate inherited from
     * the page tree root -- the arrangement that makes writing through to
     * an ancestor destructive.
     */
    private static function pageWithContent(
        bool $withContent = true,
        bool $existingXObject = false,
        bool $withAnnots = false,
        int $rotate = 90,
    ): string {
        $content = "BT /F1 14 Tf 1 0 0 1 30 350 Tm (Existing) Tj ET\n";

        $xObjectEntry = $existingXObject ? ' /XObject << /MPOverlay0 9 0 R >>' : '';
        $pageEntries = $withContent ? ' /Contents 4 0 R' : '';
        $pageEntries .= $withAnnots ? ' /Annots [7 0 R]' : '';

        $objects = [
            1 => '<< /Type /Catalog /Pages 2 0 R >>',
            2 => '<< /Type /Pages /Count 2 /Kids [3 0 R 6 0 R] /MediaBox [0 0 400 400]'
                . " /Rotate $rotate /Resources << /Font << /F1 5 0 R >>$xObjectEntry >> >>",
            3 => "<< /Type /Page /Parent 2 0 R$pageEntries >>",
            4 => '<< /Length ' . strlen($content) . " >>\nstream\n" . $content . 'endstream',
            5 => '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>',
            6 => '<< /Type /Page /Parent 2 0 R /Contents 4 0 R >>',
            7 => '<< /Type /Annot /Subtype /Widget /Rect [0 0 10 10] >>',
        ];

        $out = "%PDF-1.7\n";
        $offsets = [];

        foreach ($objects as $id => $body) {
            $offsets[$id] = strlen($out);
            $out .= "$id 0 obj\n$body\nendobj\n";
        }

        $xrefOffset = strlen($out);
        $out .= "xref\n0 8\n0000000000 65535 f \n";

        for ($id = 1; $id <= 7; ++$id) {
            $out .= sprintf("%010d 00000 n \n", $offsets[$id]);
        }

        return $out . "trailer\n<< /Size 8 /Root 1 0 R >>\nstartxref\n{$xrefOffset}\n%%EOF\n";
    }
}
