<?php

declare(strict_types=1);

namespace MightyPDF\Tests\Assembler;

use MightyPDF\Assembler\Destination;
use MightyPDF\Assembler\Document;
use MightyPDF\Assembler\ObjectStream;
use MightyPDF\Assembler\PageSize;
use MightyPDF\Assembler\Stream;
use MightyPDF\Assembler\Types\PdfName;
use MightyPDF\Assembler\Xref;
use MightyPDF\Content\Font\StandardFont;
use MightyPDF\Content\PageBuilder;
use MightyPDF\Editor\Form\FormFiller;
use MightyPDF\Editor\PdfEditor;
use MightyPDF\Layout\Flow;
use MightyPDF\Reader\Text\TextExtractor;
use MightyPDF\Tests\Support\SavedDocument;
use PHPUnit\Framework\TestCase;

final class ObjectStreamTest extends TestCase
{
    /** A document with plenty of small dictionaries to pack. */
    private function document(bool $compress): Document
    {
        $document = new Document();

        if ($compress) {
            $document->compressObjects();
        }

        $document->info()->setTitle('Packed');

        $flow = new Flow($document, PageSize::A4);

        for ($i = 1; $i <= 12; ++$i) {
            $flow->paragraph(150.0, "Section $i");
            $document->outline()->add("Section $i", Destination::of($document->pages()[0]));
        }

        $flow->finish();

        return $document;
    }

    public function testAPackedDocumentIsSmallerThanTheSameOneUnpacked(): void
    {
        $plain = strlen($this->document(false)->save());
        $packed = strlen($this->document(true)->save());

        self::assertLessThan($plain, $packed);
    }

    public function testAPackedDocumentWritesACrossReferenceStreamAndNoTable(): void
    {
        $bytes = $this->document(true)->save();

        self::assertStringContainsString('/Type /XRef', $bytes);
        self::assertStringContainsString('/Type /ObjStm', $bytes);
        self::assertStringNotContainsString("\nxref\n", $bytes);
        self::assertStringNotContainsString("trailer\n", $bytes);
    }

    public function testAnUnpackedDocumentStillWritesAClassicTable(): void
    {
        $bytes = $this->document(false)->save();

        self::assertStringContainsString("\nxref\n", $bytes);
        self::assertStringContainsString("trailer\n", $bytes);
        self::assertStringNotContainsString('/ObjStm', $bytes);
    }

    public function testThePackedObjectsAreNoLongerInTheFileAsPlainText(): void
    {
        // The point of the exercise: the page dictionaries are inside a
        // deflate stream rather than sitting in the file one at a time.
        self::assertStringNotContainsString('/Type /Page', $this->document(true)->save());
        self::assertStringContainsString('/Type /Page', $this->document(false)->save());
    }

    public function testAPackedDocumentReadsBack(): void
    {
        $editor = PdfEditor::fromBytes($this->document(true)->save());

        $catalog = $editor->catalog();
        self::assertInstanceOf(PdfName::class, $catalog->get('Type'));
        self::assertSame('Catalog', $catalog->get('Type')->value());
    }

    public function testTheTextOfAPackedDocumentComesBackOut(): void
    {
        $editor = PdfEditor::fromBytes($this->document(true)->save());
        $text = (new TextExtractor($editor))->text();

        self::assertStringContainsString('Section 1', $text);
        self::assertStringContainsString('Section 12', $text);
    }

    public function testAPackedDocumentHasTheSamePageCountAsAnUnpackedOne(): void
    {
        $packed = new TextExtractor(PdfEditor::fromBytes($this->document(true)->save()));
        $plain = new TextExtractor(PdfEditor::fromBytes($this->document(false)->save()));

        self::assertSame($plain->pageCount(), $packed->pageCount());
        self::assertSame($plain->text(), $packed->text());
    }

    public function testSavingTwiceGivesTheSameBytes(): void
    {
        // Container ids are derived rather than allocated, so a second
        // save must not renumber anything.
        $document = $this->document(true);

        self::assertSame($document->save(), $document->save());
    }

    public function testStreamsStayOutsideObjectStreams(): void
    {
        // A content stream has to be findable by byte offset without
        // decoding anything first.
        $document = new Document();
        $document->compressObjects();

        $page = $document->newPage(PageSize::A4);
        (new PageBuilder($document, $page))->drawText(StandardFont::Helvetica, 12.0, 50.0, 50.0, 'Hello');

        $bytes = $document->save();

        // The content stream is still an object of its own, so its
        // "N 0 obj" wrapper is in the file.
        self::assertMatchesRegularExpression('/\d+ 0 obj\n<<[^>]*\/Length/', $bytes);
    }

    public function testAnEncryptedDocumentPacksAndOpensWithItsPassword(): void
    {
        $document = new Document();
        $document->compressObjects();
        $document->info()->setTitle('Secret');
        $document->newPage(PageSize::A4);
        $document->encrypt(ownerPassword: 'owner', userPassword: 'user');

        $bytes = $document->save();

        self::assertStringContainsString('/Type /ObjStm', $bytes);

        $editor = PdfEditor::fromBytes($bytes, 'user');

        self::assertSame('Catalog', $editor->catalog()->get('Type')->value());
    }

    public function testTheEncryptionDictionaryStaysOutOfAnObjectStream(): void
    {
        // A reader cannot decrypt the object stream that would hold it
        // without having read it first.
        $document = new Document();
        $document->compressObjects();
        $document->newPage(PageSize::A4);
        $document->encrypt(ownerPassword: 'owner');

        // The encryption dictionary has to stay outside the object
        // streams -- a reader cannot decrypt the container holding it
        // without having read it first -- so it must resolve straight
        // from the trailer.
        $saved = SavedDocument::of($document);
        $encrypt = $saved->editor()->resolveDictionary($saved->editor()->store()->trailer()->get('Encrypt'));

        self::assertSame('Standard', $encrypt?->get('Filter')?->value());
    }

    public function testAPackedDocumentCanStillBeEdited(): void
    {
        // The editor appends plain objects onto a file whose original
        // objects are packed, and chains its update onto the
        // cross-reference *stream* the packed file ends with -- a classic
        // table there would be a chain no reader is obliged to follow.
        $document = new Document();
        $document->compressObjects();
        $page = $document->newPage(PageSize::A4);
        (new PageBuilder($document, $page))
            ->addTextField('first_name', x: 200, y: 700, width: 250, height: 20, value: 'Ada');

        $editor = PdfEditor::fromBytes($document->save());
        (new FormFiller($editor))->set('first_name', 'Grace');

        $updated = $editor->save();

        self::assertSame('Grace', (new FormFiller(PdfEditor::fromBytes($updated)))->values()['first_name']);
        self::assertStringNotContainsString("\nxref\n", $updated);
    }

    public function testPackRefusesAStream(): void
    {
        $this->expectException(\LogicException::class);

        ObjectStream::pack(9, [4 => new Stream(4, 'data')]);
    }

    public function testPackRefusesAnEmptySet(): void
    {
        $this->expectException(\LogicException::class);

        ObjectStream::pack(9, []);
    }

    public function testAClassicTableRefusesCompressedEntries(): void
    {
        $xref = new Xref();
        $xref->addEntry(1, 17);
        $xref->addCompressedEntry(2, 40, 0);

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('classic cross-reference table');

        $xref->build();
    }

    public function testTheHeaderOffsetsPointAtTheObjectBodies(): void
    {
        $document = new Document();
        $first = $document->newPage(PageSize::A4);
        $second = $document->newPage(PageSize::A4);

        $stream = ObjectStream::pack(99, [
            $first->objectId() => $first,
            $second->objectId() => $second,
        ]);

        $bytes = $stream->rawBytes();
        $firstOffset = (int) $stream->get('First')->value();

        // The header is "id offset id offset ", and offset 0 is the first
        // body, sitting exactly at /First.
        self::assertSame(
            "{$first->objectId()} 0 ",
            substr($bytes, 0, strlen((string) $first->objectId()) + 3),
        );
        self::assertSame('<<', substr($bytes, $firstOffset, 2));
        self::assertSame(2, (int) $stream->get('N')->value());
    }
}
