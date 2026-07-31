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
use MightyPDF\Crypt\DecryptionException;
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

    public function testRefusesAFileWhoseEncryptDictionaryIsMissing(): void
    {
        // Reading on would decrypt nothing and yield a document full of
        // binary noise -- field names matching nothing, content streams
        // drawing nothing -- which is far worse than not opening it.
        $body = "%PDF-1.7\n1 0 obj\n<< /Type /Catalog >>\nendobj\n";
        $offset = strlen($body);

        $pdf = $body
            . "xref\n0 2\n0000000000 65535 f \n0000000009 00000 n \n"
            . "trailer\n<< /Size 2 /Root 1 0 R /Encrypt 5 0 R >>\n"
            . "startxref\n{$offset}\n%%EOF\n";

        $this->expectException(DecryptionException::class);
        $this->expectExceptionMessage('/Encrypt dictionary is missing');

        new ObjectStore($pdf);
    }

    public function testAnUnencryptedFileNeedsNoPassword(): void
    {
        self::assertFalse((new ObjectStore(self::writtenDocument('x')))->isEncrypted());
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

    public function testReadsAnObjectOutOfAnObjectStream(): void
    {
        $store = new ObjectStore(self::objectStreamPdf());

        // The catalog is compressed inside object stream 4 and has no
        // "1 0 obj" header anywhere in the file.
        self::assertSame('Catalog', $store->catalog()->get('Type')?->value());
        self::assertSame('Pages', $store->resolveDictionary($store->catalog()->get('Pages'))?->get('Type')?->value());
    }

    public function testDecompressesAnObjectStreamOnlyOnce(): void
    {
        // A file that compresses its whole page tree into one container
        // would otherwise pay the inflate again for every page in it.
        $store = new ObjectStore(self::objectStreamPdf());

        self::assertSame($store->get(1), $store->get(1));
        self::assertSame($store->get(2), $store->get(2));
    }

    public function testDecodesAStreamsContent(): void
    {
        $store = new ObjectStore(self::writtenDocument('Decoded'));
        $page = self::firstPage($store);

        $contents = $page->get('Contents');
        self::assertInstanceOf(PdfArray::class, $contents);

        $stream = $store->resolve($contents->items()[0]);
        self::assertInstanceOf(Stream::class, $stream);

        self::assertStringContainsString('(Decoded) Tj', $store->decodedStream($stream));
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

    /**
     * A PDF 1.5-style file: the catalog and page tree live compressed
     * inside an object stream, and a cross-reference stream says so.
     */
    private static function objectStreamPdf(): string
    {
        $members = ['<< /Type /Catalog /Pages 2 0 R >>', '<< /Type /Pages /Count 0 /Kids [] >>'];

        // The container starts with N pairs of "object id, offset", then
        // the objects themselves -- bare, with no "N 0 obj" wrapper.
        $header = '';
        $body = '';

        foreach ($members as $index => $member) {
            $header .= sprintf('%d %d ', $index + 1, strlen($body));
            $body .= $member . ' ';
        }

        $payload = gzcompress($header . $body);

        $pdf = "%PDF-1.5\n";
        $containerOffset = strlen($pdf);
        $pdf .= sprintf(
            "4 0 obj\n<< /Type /ObjStm /N %d /First %d /Filter /FlateDecode /Length %d >>\nstream\n%s\nendstream\nendobj\n",
            count($members),
            strlen($header),
            strlen($payload),
            $payload,
        );

        $xrefOffset = strlen($pdf);
        $rows = pack('Cnn', 0, 0, 65535)
            . pack('Cnn', 2, 4, 0)
            . pack('Cnn', 2, 4, 1)
            . pack('Cnn', 0, 0, 0)
            . pack('Cnn', 1, $containerOffset, 0)
            . pack('Cnn', 1, $xrefOffset, 0);
        $rowData = gzcompress($rows);

        $pdf .= sprintf(
            "5 0 obj\n<< /Type /XRef /Size 6 /Root 1 0 R /W [1 2 2] /Index [0 6]"
            . " /Filter /FlateDecode /Length %d >>\nstream\n%s\nendstream\nendobj\n",
            strlen($rowData),
            $rowData,
        );

        return $pdf . "startxref\n{$xrefOffset}\n%%EOF\n";
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
