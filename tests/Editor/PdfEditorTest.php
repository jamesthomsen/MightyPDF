<?php

declare(strict_types=1);

namespace MightyPDF\Tests\Editor;

use MightyPDF\Assembler\Dictionary;
use MightyPDF\Assembler\Document;
use MightyPDF\Assembler\Stream;
use MightyPDF\Assembler\Types\PdfArray;
use MightyPDF\Assembler\Types\PdfBoolean;
use MightyPDF\Assembler\Types\PdfInteger;
use MightyPDF\Assembler\Types\PdfName;
use MightyPDF\Content\Font\StandardFont;
use MightyPDF\Content\PageBuilder;
use MightyPDF\Editor\PdfEditor;
use MightyPDF\Reader\ObjectStore;
use PHPUnit\Framework\TestCase;

final class PdfEditorTest extends TestCase
{
    public function testSavingAnUnchangedDocumentReturnsTheOriginalBytes(): void
    {
        // Opening a file and saving it must not alter a single byte. An
        // update section saying nothing is noise -- and for a signed
        // document, noise that costs the signature its "no revisions" status.
        $original = self::writtenDocument();

        self::assertSame($original, PdfEditor::fromBytes($original)->save());
    }

    public function testTheOriginalBytesAreAByteExactPrefixOfTheUpdate(): void
    {
        // The whole safety argument for incremental update rests on this:
        // nothing that was already in the file gets regenerated, so
        // nothing that was already in the file can be damaged.
        $original = self::writtenDocument();

        self::assertStringStartsWith($original, self::touchCatalog($original));
    }

    public function testAChangedObjectReadsBackWithItsNewValue(): void
    {
        $store = new ObjectStore(self::touchCatalog(self::writtenDocument()));

        self::assertTrue($store->catalog()->get('MightyPDFTouched')?->value());
    }

    public function testObjectsTheUpdateDidNotTouchStillResolve(): void
    {
        $store = new ObjectStore(self::touchCatalog(self::writtenDocument()));

        $page = self::firstPage($store);
        self::assertSame('Page', $page->get('Type')?->value());

        $contents = $page->get('Contents');
        self::assertInstanceOf(PdfArray::class, $contents);

        $stream = $store->resolve($contents->items()[0]);
        self::assertInstanceOf(Stream::class, $stream);
        self::assertStringContainsString('(Original) Tj', (string) gzuncompress($stream->rawBytes()));
    }

    public function testNewObjectsAreAllocatedAboveEverythingTheFileUses(): void
    {
        $original = self::writtenDocument();

        $editor = PdfEditor::fromBytes($original);
        $existing = $editor->store()->xref()->nextFreeObjectId();

        $id = $editor->allocate();
        $editor->register((new Dictionary($id))->set('Type', new PdfName('Mine')));

        self::assertSame($existing, $id);

        $reopened = new ObjectStore($editor->save());
        $added = $reopened->get($id);

        self::assertInstanceOf(Dictionary::class, $added);
        self::assertSame('Mine', $added->get('Type')?->value());
    }

    public function testTheUpdateTrailerPointsBackAtThePreviousSection(): void
    {
        $original = self::writtenDocument();
        $previousStartXref = PdfEditor::fromBytes($original)->store()->xref()->startXrefOffset();

        self::assertStringContainsString("/Prev {$previousStartXref}", self::touchCatalog($original));
    }

    public function testTheUpdateTrailerCarriesEverythingTheOriginalSaid(): void
    {
        // An update trailer that dropped /Root would be unopenable, and
        // one that dropped /ID would break every identity check over the
        // file. Copy-then-override is the only version that cannot
        // quietly lose a key this library has never heard of.
        $body = "%PDF-1.7\n1 0 obj\n<< /Type /Catalog >>\nendobj\n";
        $offset = strlen($body);
        $original = $body
            . "xref\n0 2\n0000000000 65535 f \n0000000009 00000 n \n"
            . "trailer\n<< /Size 2 /Root 1 0 R /Info 9 0 R /ID [<AB> <CD>] /Custom /Kept >>\n"
            . "startxref\n{$offset}\n%%EOF\n";

        $trailer = (new ObjectStore(self::touchCatalog($original)))->trailer();

        self::assertSame(1, $trailer->get('Root')?->objectId());
        self::assertSame(9, $trailer->get('Info')?->objectId());
        self::assertSame('[<ab> <cd>]', $trailer->get('ID')?->format());
        self::assertSame('Kept', $trailer->get('Custom')?->value());
    }

    public function testSuccessiveUpdatesChainCorrectly(): void
    {
        // The real exercise of the /Prev walk: three revisions deep, with
        // each one superseding part of the last and leaving the rest.
        $first = self::writtenDocument();

        $editor = PdfEditor::fromBytes($first);
        $editor->register($editor->catalog()->set('Revision', new PdfInteger(2)));
        $second = $editor->save();

        $editor = PdfEditor::fromBytes($second);
        $editor->register($editor->catalog()->set('Revision', new PdfInteger(3)));
        $third = $editor->save();

        self::assertStringStartsWith($second, $third);

        $store = new ObjectStore($third);

        self::assertSame(3, $store->catalog()->get('Revision')?->value());
        self::assertSame('Page', self::firstPage($store)->get('Type')?->value());
    }

    public function testAnObjectKeepsItsGenerationWhenRewritten(): void
    {
        // A generation is part of an object's identity: rewriting "5 3 obj"
        // as "5 0 obj" leaves every "5 3 R" reference in the file pointing
        // at something that no longer answers to that name.
        $body = "%PDF-1.7\n"
            . "1 0 obj\n<< /Type /Catalog /Thing 5 3 R >>\nendobj\n"
            . "5 3 obj\n<< /Type /Thing >>\nendobj\n";
        $offset = strlen($body);
        $original = $body
            . "xref\n0 2\n0000000000 65535 f \n0000000009 00000 n \n"
            . "5 1\n0000000058 00003 n \n"
            . "trailer\n<< /Size 6 /Root 1 0 R >>\n"
            . "startxref\n{$offset}\n%%EOF\n";

        $editor = PdfEditor::fromBytes($original);
        $thing = $editor->resolveDictionary($editor->catalog()->get('Thing'));
        self::assertInstanceOf(Dictionary::class, $thing);
        self::assertSame(3, $thing->generation());

        $editor->register($thing->set('Edited', new PdfBoolean(true)));
        $saved = $editor->save();

        self::assertStringContainsString("5 3 obj\n<< /Type /Thing /Edited true >>", $saved);
        self::assertStringContainsString('00003 n', substr($saved, strlen($original)));

        $reopened = new ObjectStore($saved);
        self::assertTrue($reopened->resolveDictionary($reopened->catalog()->get('Thing'))?->get('Edited')?->value());
    }

    public function testAppendsALineBreakWhenTheFileEndsFlushAgainstEof(): void
    {
        // "%" starts a comment running to end of line, so appending
        // straight onto "%%EOF" would produce "%%EOF1 0 obj" and comment
        // the new object out of existence -- parsing cleanly, and simply
        // not being there.
        $original = self::writtenDocument();
        self::assertStringEndsWith('%%EOF', $original, 'precondition: the writer emits no trailing newline');

        $store = new ObjectStore(self::touchCatalog($original));

        self::assertTrue($store->catalog()->get('MightyPDFTouched')?->value());
    }

    public function testEditsARealFileWrittenByAnotherTool(): void
    {
        $path = __DIR__ . '/../fixtures/pdf/blank.pdf';

        self::assertSame(file_get_contents($path), PdfEditor::open($path)->save());

        $editor = PdfEditor::open($path);
        $editor->register($editor->catalog()->set('MightyPDFTouched', new PdfBoolean(true)));
        $saved = $editor->save();

        self::assertStringStartsWith((string) file_get_contents($path), $saved);

        $store = new ObjectStore($saved);
        self::assertTrue($store->catalog()->get('MightyPDFTouched')?->value());
        self::assertSame('Page', self::firstPage($store)->get('Type')?->value());
    }

    public function testMatchesTheSourcesCrossReferenceFormat(): void
    {
        // A classic table whose /Prev points at a cross-reference stream
        // is not a conforming chain: Ghostscript reports "xref table was
        // repaired", having thrown the cross-reference information away
        // and rebuilt it by scanning. The update must match the source.
        $classic = self::touchCatalog(self::writtenDocument());
        self::assertStringContainsString("\nxref\n", substr($classic, -400));
        self::assertStringContainsString("\ntrailer\n", $classic);

        $stream = self::touchCatalog(self::crossReferenceStreamPdf());
        self::assertStringNotContainsString("\ntrailer\n", $stream);
        self::assertStringContainsString('/Type /XRef', $stream);
    }

    public function testAnUpdateToAnXrefStreamFileReadsBack(): void
    {
        $original = self::crossReferenceStreamPdf();
        $saved = self::touchCatalog($original);

        self::assertStringStartsWith($original, $saved);

        $store = new ObjectStore($saved);

        self::assertTrue($store->catalog()->get('MightyPDFTouched')?->value());
        self::assertTrue($store->xref()->usesCrossReferenceStreams());
    }

    public function testTheNewXrefStreamRecordsItsOwnPosition(): void
    {
        // A reader that has just found the section via startxref still
        // expects the section to say where it is.
        $saved = self::touchCatalog(self::crossReferenceStreamPdf());

        $startXref = (int) (preg_match('/startxref\s+(\d+)\s+%%EOF\s*$/', $saved, $m) === 1 ? $m[1] : -1);
        self::assertGreaterThan(0, $startXref);

        $store = new ObjectStore($saved);
        $xrefStreamId = $store->xref()->size() - 1;

        self::assertSame($startXref, $store->xref()->entry($xrefStreamId)?->offset);
    }

    public function testSaveIsIdempotent(): void
    {
        // save() reads the allocator without consuming it, so calling it
        // twice must not shuffle object numbers underneath the caller.
        $editor = PdfEditor::fromBytes(self::crossReferenceStreamPdf());
        $editor->register($editor->catalog()->set('MightyPDFTouched', new PdfBoolean(true)));

        self::assertSame($editor->save(), $editor->save());
    }

    /** A minimal file whose only section is a cross-reference stream. */
    private static function crossReferenceStreamPdf(): string
    {
        $body = "%PDF-1.5\n1 0 obj\n<< /Type /Catalog >>\nendobj\n";
        $offset = strlen($body);

        $rows = pack('Cnn', 0, 0, 65535) . pack('Cnn', 1, 9, 0) . pack('Cnn', 1, $offset, 0);
        $data = gzcompress($rows);

        return $body
            . "2 0 obj\n<< /Type /XRef /Size 3 /Root 1 0 R /W [1 2 2] /Index [0 3]"
            . " /Filter /FlateDecode /Length " . strlen($data) . " >>\n"
            . "stream\n" . $data . "\nendstream\nendobj\n"
            . "startxref\n{$offset}\n%%EOF\n";
    }

    private static function writtenDocument(): string
    {
        $document = new Document();
        $page = $document->newPage();
        (new PageBuilder($document, $page))->drawText(StandardFont::Helvetica, 12.0, 72, 720, 'Original');

        return $document->save();
    }

    private static function touchCatalog(string $original): string
    {
        $editor = PdfEditor::fromBytes($original);
        $editor->register($editor->catalog()->set('MightyPDFTouched', new PdfBoolean(true)));

        return $editor->save();
    }

    private static function firstPage(ObjectStore $store): Dictionary
    {
        $pageTree = $store->resolveDictionary($store->catalog()->get('Pages'));
        self::assertInstanceOf(Dictionary::class, $pageTree);

        $kids = $pageTree->get('Kids');
        self::assertInstanceOf(PdfArray::class, $kids);

        $page = $store->resolveDictionary($kids->items()[0]);
        self::assertInstanceOf(Dictionary::class, $page);

        return $page;
    }
}
