<?php

declare(strict_types=1);

namespace MightyPDF\Tests\Reader;

use MightyPDF\Assembler\Dictionary;
use MightyPDF\Assembler\Document;
use MightyPDF\Assembler\Stream;
use MightyPDF\Assembler\Types\PdfArray;
use MightyPDF\Assembler\Types\PdfName;
use MightyPDF\Content\Font\StandardFont;
use MightyPDF\Content\PageBuilder;
use MightyPDF\Reader\ObjectStore;
use MightyPDF\Reader\ParseException;
use PHPUnit\Framework\TestCase;

final class ObjectStoreTest extends TestCase
{
    public function testReadsBackADocumentThisLibraryWrote(): void
    {
        // The end-to-end check that matters most: writer and reader agree
        // about the same bytes, so anything the writer can produce is
        // something the reader can later edit.
        $store = new ObjectStore(self::writtenDocument('Round trip'));

        $catalog = $store->catalog();
        self::assertSame('Catalog', $catalog->get('Type')?->value());

        $pageTree = $store->resolveDictionary($catalog->get('Pages'));
        self::assertInstanceOf(Dictionary::class, $pageTree);
        self::assertSame('Pages', $pageTree->get('Type')?->value());

        $kids = $pageTree->get('Kids');
        self::assertInstanceOf(PdfArray::class, $kids);
        self::assertCount(1, $kids->items());

        $page = $store->resolveDictionary($kids->items()[0]);
        self::assertInstanceOf(Dictionary::class, $page);
        self::assertSame('Page', $page->get('Type')?->value());
    }

    public function testReadsBackACompressedContentStream(): void
    {
        $store = new ObjectStore(self::writtenDocument('Round trip'));

        $page = self::firstPage($store);
        $contents = $page->get('Contents');
        self::assertInstanceOf(PdfArray::class, $contents);

        $stream = $store->resolve($contents->items()[0]);
        self::assertInstanceOf(Stream::class, $stream);

        // rawBytes() on a parsed stream is the stored, still-encoded form
        // -- /Filter says how to read it, and nothing has re-encoded it.
        self::assertSame('FlateDecode', $stream->get('Filter')?->value());
        self::assertStringContainsString('(Round trip) Tj', (string) gzuncompress($stream->rawBytes()));
    }

    public function testResolvesReferencesTransparently(): void
    {
        $store = new ObjectStore(self::writtenDocument('x'));

        // Whether an entry is written direct or indirect is the writing
        // tool's choice, so callers must not have to care.
        self::assertInstanceOf(PdfName::class, $store->resolve($store->catalog()->get('Type')));
    }

    public function testRecoversObjectsWhenTheXrefOffsetsAreWrong(): void
    {
        // Stale offsets are one of the commonest defects in real PDFs;
        // trusting the xref absolutely would make the reader useless.
        $store = new ObjectStore(self::handWritten('0000009999', '0000009998'));

        self::assertSame('Catalog', $store->catalog()->get('Type')?->value());
        self::assertSame('Pages', $store->resolveDictionary($store->catalog()->get('Pages'))?->get('Type')?->value());
    }

    public function testRefusesAnObjectFoundAtAnOffsetThatHoldsADifferentOne(): void
    {
        // Pointing object 1's entry at object 2's header. Returning object
        // 2's contents under id 1 is corruption nothing downstream could
        // detect, so the id has to be verified before the value is used.
        $pdf = self::handWritten('0000009999', '0000009999');
        $secondObjectOffset = strpos($pdf, '2 0 obj');
        self::assertIsInt($secondObjectOffset);

        $store = new ObjectStore(self::handWritten(sprintf('%010d', $secondObjectOffset), '0000009999'));

        // The scanner still finds the real object 1, so the document opens.
        self::assertSame('Catalog', $store->catalog()->get('Type')?->value());
    }

    public function testReturnsNullForAnObjectTheFileDoesNotHave(): void
    {
        self::assertNull((new ObjectStore(self::writtenDocument('x')))->get(9999));
    }

    public function testRefusesAnEncryptedFile(): void
    {
        // Every string and stream in an encrypted file is ciphertext, so
        // reading on would yield a document full of binary noise and a
        // form fill that is silently wrong. Refusing is the safe answer.
        $body = "%PDF-1.7\n1 0 obj\n<< /Type /Catalog >>\nendobj\n";
        $offset = strlen($body);

        $pdf = $body
            . "xref\n0 2\n0000000000 65535 f \n0000000009 00000 n \n"
            . "trailer\n<< /Size 2 /Root 1 0 R /Encrypt 5 0 R >>\n"
            . "startxref\n{$offset}\n%%EOF\n";

        $this->expectException(ParseException::class);
        $this->expectExceptionMessage('encrypted');

        new ObjectStore($pdf);
    }

    public function testSurvivesAReferenceCycle(): void
    {
        $body = "%PDF-1.7\n"
            . "1 0 obj\n2 0 R\nendobj\n"
            . "2 0 obj\n1 0 R\nendobj\n";
        $offset = strlen($body);

        $pdf = $body
            . "xref\n0 3\n0000000000 65535 f \n0000000009 00000 n \n0000000031 00000 n \n"
            . "trailer\n<< /Size 3 /Root 1 0 R >>\n"
            . "startxref\n{$offset}\n%%EOF\n";

        $store = new ObjectStore($pdf);

        self::assertNull($store->resolve($store->get(1)));
    }

    public function testSurvivesAStreamWhoseLengthPointsBackAtItself(): void
    {
        $body = "%PDF-1.7\n"
            . "1 0 obj\n<< /Length 1 0 R >>\nstream\npayload\nendstream\nendobj\n";
        $offset = strlen($body);

        $pdf = $body
            . "xref\n0 2\n0000000000 65535 f \n0000000009 00000 n \n"
            . "trailer\n<< /Size 2 /Root 1 0 R >>\n"
            . "startxref\n{$offset}\n%%EOF\n";

        $stream = (new ObjectStore($pdf))->get(1);

        self::assertInstanceOf(Stream::class, $stream);
        self::assertSame('payload', $stream->rawBytes());
    }

    public function testReportsTheTrailerSizeForAllocatingNewObjectIds(): void
    {
        $store = new ObjectStore(self::writtenDocument('x'));

        // An incremental update allocates from here upwards.
        self::assertGreaterThan(1, $store->xref()->size());
    }

    private static function writtenDocument(string $text): string
    {
        $document = new Document();
        $page = $document->newPage();
        (new PageBuilder($document, $page))->drawText(StandardFont::Helvetica, 12.0, 72, 720, $text);

        return $document->save();
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

    /** A two-object file whose xref offsets are supplied by the caller. */
    private static function handWritten(string $firstOffset, string $secondOffset): string
    {
        $body = "%PDF-1.7\n"
            . "1 0 obj\n<< /Type /Catalog /Pages 2 0 R >>\nendobj\n"
            . "2 0 obj\n<< /Type /Pages /Count 0 /Kids [] >>\nendobj\n";

        $offset = strlen($body);

        return $body
            . "xref\n0 3\n"
            . "0000000000 65535 f \n"
            . "{$firstOffset} 00000 n \n"
            . "{$secondOffset} 00000 n \n"
            . "trailer\n<< /Size 3 /Root 1 0 R >>\n"
            . "startxref\n{$offset}\n%%EOF\n";
    }
}
