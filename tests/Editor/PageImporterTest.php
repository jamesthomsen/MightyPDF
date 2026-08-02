<?php

declare(strict_types=1);

namespace MightyPDF\Tests\Editor;

use MightyPDF\Assembler\Dictionary;
use MightyPDF\Assembler\Document;
use MightyPDF\Assembler\Stream;
use MightyPDF\Assembler\Types\PdfArray;
use MightyPDF\Content\Font\StandardFont;
use MightyPDF\Content\PageBuilder;
use MightyPDF\Editor\Form\FormImporter;
use MightyPDF\Editor\PageImporter;
use MightyPDF\Editor\PdfEditor;
use MightyPDF\Editor\PdfMerger;
use MightyPDF\Reader\ObjectStore;
use PHPUnit\Framework\TestCase;

final class PageImporterTest extends TestCase
{
    public function testCopiesAPagesContentStreamVerbatim(): void
    {
        $source = PdfEditor::fromBytes(self::twoPageFixture());
        $target = new Document();
        $importer = new PageImporter($source, $target);

        $pages = iterator_to_array($importer->pages(), false);
        self::assertCount(2, $pages);

        $page = $importer->import($pages[0]);

        self::assertCount(1, $page->contentStreams());
        self::assertStringContainsString('(Page A)', $page->contentStreams()[0]->rawBytes());
    }

    public function testFlattensANestedPageTreeInOrderWithSequentialKeys(): void
    {
        // Two intermediate /Pages nodes, each with their own leaves --
        // unlike this library's own writer, which only ever builds a flat
        // tree, plenty of real files nest like this. Nested yield from
        // does not renumber an inner generator's own keys, so two sibling
        // branches that each start counting their own leaves from 0 would
        // collide if pages() forwarded walk()'s keys directly instead of
        // re-keying them itself.
        $source = PdfEditor::fromBytes(self::nestedPageTreeFixture());
        $target = new Document();
        $importer = new PageImporter($source, $target);

        $pages = [];
        foreach ($importer->pages() as $index => $sourcePage) {
            $pages[$index] = $sourcePage;
        }

        self::assertSame([0, 1, 2], array_keys($pages));

        $imported = array_map(fn (Dictionary $page): string => $importer->import($page)->contentStreams()[0]->rawBytes(), $pages);
        self::assertSame(['(Leaf 1)', '(Leaf 2)', '(Leaf 3)'], $imported);
    }

    public function testInheritedMediaBoxAndRotateCarryThrough(): void
    {
        $source = PdfEditor::fromBytes(self::twoPageFixture());
        $target = new Document();
        $importer = new PageImporter($source, $target);

        $page = $importer->import(iterator_to_array($importer->pages(), false)[0]);

        self::assertSame('[0 0 400 400]', $page->get('MediaBox')?->format());
        self::assertSame('90', $page->get('Rotate')?->format());
    }

    public function testTwoPagesFromTheSameSourceShareTheSameCopiedFontObject(): void
    {
        $source = PdfEditor::fromBytes(self::twoPageFixture());
        $target = new Document();
        $importer = new PageImporter($source, $target);

        $pages = iterator_to_array($importer->pages(), false);
        $pageA = $importer->import($pages[0]);
        $pageB = $importer->import($pages[1]);

        $fontA = $pageA->resources()->get('Font');
        $fontB = $pageB->resources()->get('Font');
        self::assertInstanceOf(Dictionary::class, $fontA);
        self::assertInstanceOf(Dictionary::class, $fontB);

        self::assertSame($fontA->get('F1')?->format(), $fontB->get('F1')?->format());
    }

    public function testCopiesAnImageXObjectWithItsBytesIntact(): void
    {
        $source = PdfEditor::fromBytes(self::twoPageFixture());
        $target = new Document();
        $importer = new PageImporter($source, $target);
        $importer->import(iterator_to_array($importer->pages(), false)[0]);

        // Through save()/reopen rather than poking at Document's internal
        // registry, matching how the rest of the test suite verifies a
        // written document (see PageOverlayTest's *survives saving* tests).
        $store = new ObjectStore($target->save());
        $page = self::firstPageOf($store);

        $xObjects = $store->resolveDictionary(
            $store->resolveDictionary($page->get('Resources'))?->get('XObject'),
        );
        $image = $store->resolve($xObjects?->get('Im0'));

        self::assertInstanceOf(Stream::class, $image);
        self::assertSame("\x80", $image->rawBytes());
    }

    /**
     * An importer given no form has nowhere to put a field, so widgets
     * are left behind rather than copied into a page that references a
     * form the document does not have.
     */
    public function testWidgetsAreSkippedWithoutAFormToPutThemIn(): void
    {
        $source = PdfEditor::fromBytes(self::twoPageFixture());
        $target = new Document();
        $importer = new PageImporter($source, $target);

        $page = $importer->import(iterator_to_array($importer->pages(), false)[1]);

        $annots = $page->get('Annots');
        self::assertInstanceOf(PdfArray::class, $annots);
        self::assertCount(1, $annots->items(), 'only the Link annotation');
    }

    public function testWidgetsAreCarriedOverWhenThereIsAFormToPutThemIn(): void
    {
        $source = PdfEditor::fromBytes(self::twoPageFixture());
        $target = new Document();
        $importer = new PageImporter($source, $target, new FormImporter($target));

        $page = $importer->import(iterator_to_array($importer->pages(), false)[1]);

        $annots = $page->get('Annots');
        self::assertInstanceOf(PdfArray::class, $annots);
        self::assertCount(2, $annots->items(), 'the Link and the Widget');
        self::assertCount(1, $target->acroForm()->fieldObjectIds());
    }

    public function testDocumentMergeCombinesPagesFromMultipleFilesInOrder(): void
    {
        $first = new Document();
        (new PageBuilder($first, $first->newPage()))
            ->drawText(StandardFont::Helvetica, 12.0, 72, 720, 'First document');

        $second = new Document();
        (new PageBuilder($second, $second->newPage()))
            ->drawText(StandardFont::Helvetica, 12.0, 72, 720, 'Second document');
        $second->newPage();

        $pathA = tempnam(sys_get_temp_dir(), 'mightypdf-merge-a-');
        $pathB = tempnam(sys_get_temp_dir(), 'mightypdf-merge-b-');
        self::assertIsString($pathA);
        self::assertIsString($pathB);

        try {
            $first->saveToFile($pathA);
            $second->saveToFile($pathB);

            $merged = PdfMerger::merge($pathA, $pathB);
            self::assertCount(3, $merged->pages());

            // Through save()/reopen rather than reading rawBytes() directly
            // off the in-memory Page: PageBuilder's own content streams
            // compress by default, and a stream copied from an opened
            // source keeps whatever encoding it already had (see
            // PageImporter's doc comment) -- decodedStream() is the
            // encoding-agnostic way to get the operators back out, exactly
            // as PageOverlayTest already does for the same reason.
            $store = new ObjectStore($merged->save());
            $kids = $store->resolveDictionary($store->catalog()->get('Pages'))?->get('Kids');
            self::assertInstanceOf(PdfArray::class, $kids);
            self::assertCount(3, $kids->items());

            $pageA = $store->resolveDictionary($kids->items()[0]);
            $pageB = $store->resolveDictionary($kids->items()[1]);

            self::assertStringContainsString('(First document)', self::firstContentStreamOf($store, $pageA));
            self::assertStringContainsString('(Second document)', self::firstContentStreamOf($store, $pageB));
        } finally {
            unlink($pathA);
            unlink($pathB);
        }
    }

    /**
     * Catalog -> Pages(root) -> [Pages(A) -> [Leaf 1, Leaf 2], Pages(B) -> [Leaf 3]].
     */
    private static function nestedPageTreeFixture(): string
    {
        $contents = ['(Leaf 1)', '(Leaf 2)', '(Leaf 3)'];

        $objects = [
            1 => '<< /Type /Catalog /Pages 2 0 R >>',
            2 => '<< /Type /Pages /Kids [3 0 R 6 0 R] /Count 3 >>',
            3 => '<< /Type /Pages /Parent 2 0 R /Kids [4 0 R 5 0 R] /Count 2 >>',
            4 => '<< /Type /Page /Parent 3 0 R /Contents 8 0 R /MediaBox [0 0 200 200] >>',
            5 => '<< /Type /Page /Parent 3 0 R /Contents 9 0 R /MediaBox [0 0 200 200] >>',
            6 => '<< /Type /Pages /Parent 2 0 R /Kids [7 0 R] /Count 1 >>',
            7 => '<< /Type /Page /Parent 6 0 R /Contents 10 0 R /MediaBox [0 0 200 200] >>',
            8 => '<< /Length ' . strlen($contents[0]) . " >>\nstream\n" . $contents[0] . 'endstream',
            9 => '<< /Length ' . strlen($contents[1]) . " >>\nstream\n" . $contents[1] . 'endstream',
            10 => '<< /Length ' . strlen($contents[2]) . " >>\nstream\n" . $contents[2] . 'endstream',
        ];

        return self::assemble($objects);
    }

    /** @param array<int, string> $objects */
    private static function assemble(array $objects): string
    {
        $out = "%PDF-1.7\n";
        $offsets = [];

        foreach ($objects as $id => $body) {
            $offsets[$id] = strlen($out);
            $out .= "$id 0 obj\n$body\nendobj\n";
        }

        $xrefOffset = strlen($out);
        $count = count($objects) + 1;
        $out .= "xref\n0 $count\n0000000000 65535 f \n";

        foreach (array_keys($objects) as $id) {
            $out .= sprintf("%010d 00000 n \n", $offsets[$id]);
        }

        return $out . "trailer\n<< /Size $count /Root 1 0 R >>\nstartxref\n{$xrefOffset}\n%%EOF\n";
    }

    private static function firstContentStreamOf(ObjectStore $store, ?Dictionary $page): string
    {
        // Page::addContentStream() always wraps /Contents in a PdfArray,
        // even for a single stream (see Page::syncContents()).
        $contents = $store->resolve($page?->get('Contents'));
        self::assertInstanceOf(PdfArray::class, $contents);

        $stream = $store->resolve($contents->items()[0]);
        self::assertInstanceOf(Stream::class, $stream);

        return $store->decodedStream($stream);
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

    /**
     * Two pages sharing /Resources, /MediaBox and /Rotate inherited from
     * the page tree root. Page A carries the image XObject; page B carries
     * one non-form annotation (Link) and one form-widget annotation, to
     * confirm only the Widget is excluded from an otherwise-mixed list.
     */
    private static function twoPageFixture(): string
    {
        $contentA = "BT /F1 14 Tf 1 0 0 1 30 350 Tm (Page A) Tj ET\nq /Im0 Do Q\n";
        $contentB = "BT /F1 14 Tf 1 0 0 1 30 350 Tm (Page B) Tj ET\n";
        $imageBytes = "\x80";

        $objects = [
            1 => '<< /Type /Catalog /Pages 2 0 R >>',
            2 => '<< /Type /Pages /Count 2 /Kids [3 0 R 6 0 R] /MediaBox [0 0 400 400]'
                . ' /Rotate 90 /Resources << /Font << /F1 5 0 R >> /XObject << /Im0 9 0 R >> >> >>',
            3 => '<< /Type /Page /Parent 2 0 R /Contents 4 0 R >>',
            4 => '<< /Length ' . strlen($contentA) . " >>\nstream\n" . $contentA . 'endstream',
            5 => '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>',
            6 => '<< /Type /Page /Parent 2 0 R /Contents 10 0 R /Annots [7 0 R 8 0 R] >>',
            7 => '<< /Type /Annot /Subtype /Link /Rect [0 0 10 10] >>',
            8 => '<< /Type /Annot /Subtype /Widget /Rect [0 0 10 10] >>',
            9 => '<< /Type /XObject /Subtype /Image /Width 1 /Height 1 /ColorSpace /DeviceGray'
                . ' /BitsPerComponent 8 /Length ' . strlen($imageBytes) . " >>\nstream\n" . $imageBytes . 'endstream',
            10 => '<< /Length ' . strlen($contentB) . " >>\nstream\n" . $contentB . 'endstream',
        ];

        return self::assemble($objects);
    }
}
