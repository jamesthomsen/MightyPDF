<?php

declare(strict_types=1);

namespace MightyPDF\Tests\Reader;

use MightyPDF\Assembler\Types\PdfInteger;
use MightyPDF\Assembler\Types\PdfReference;
use MightyPDF\Reader\Lexer;
use MightyPDF\Reader\ParseException;
use MightyPDF\Reader\XrefTable;
use PHPUnit\Framework\TestCase;

final class XrefTableTest extends TestCase
{
    public function testReadsASingleSection(): void
    {
        $xref = self::read(self::singleSection());

        self::assertSame(17, $xref->entry(1)?->offset);
        self::assertSame(0, $xref->entry(1)?->generation);
        self::assertSame(62, $xref->entry(2)?->offset);
        self::assertSame(3, $xref->size());
    }

    public function testIgnoresFreeEntries(): void
    {
        // Object 0 is always the free-list head. A freed object is simply
        // an object that is not there, which is what "no entry" means.
        self::assertNull(self::read(self::singleSection())->entry(0));
    }

    public function testReadsMultipleSubsections(): void
    {
        $body = "%PDF-1.7\n";
        $offset = strlen($body);

        $pdf = $body
            . "xref\n"
            . "0 1\n0000000000 65535 f \n"
            . "4 2\n0000000100 00000 n \n0000000200 00007 n \n"
            . "trailer\n<< /Size 6 /Root 4 0 R >>\n"
            . "startxref\n{$offset}\n%%EOF\n";

        $xref = self::read($pdf);

        self::assertSame([4, 5], array_keys($xref->entries()));
        self::assertSame(200, $xref->entry(5)?->offset);
        self::assertSame(7, $xref->entry(5)?->generation);
    }

    public function testAnUpdatedObjectSupersedesTheOriginal(): void
    {
        $xref = self::read(self::incrementallyUpdated());

        // Object 1 was rewritten by the second section; object 2 was not
        // mentioned there at all and must survive from the first.
        self::assertSame(999, $xref->entry(1)?->offset);
        self::assertSame(62, $xref->entry(2)?->offset);
    }

    public function testTheNewestTrailerWinsButOlderKeysAreInherited(): void
    {
        // Incremental-update writers vary in how much of the trailer they
        // repeat, so a key the newest section omits has to be found in an
        // older one -- otherwise a perfectly good file has no /Root.
        $trailer = self::read(self::incrementallyUpdated())->trailer();

        self::assertSame(9, $trailer->get('Size')?->value());

        $root = $trailer->get('Root');
        self::assertInstanceOf(PdfReference::class, $root);
        self::assertSame(1, $root->objectId());
    }

    public function testTheMergedTrailerDropsPrev(): void
    {
        // /Prev describes one section, not the document, and the chain it
        // points into has already been walked.
        self::assertNull(self::read(self::incrementallyUpdated())->trailer()->get('Prev'));
    }

    public function testFallsBackToTheHighestObjectIdWhenSizeIsMissing(): void
    {
        $body = "%PDF-1.7\n";
        $offset = strlen($body);

        $pdf = $body
            . "xref\n0 3\n0000000000 65535 f \n0000000017 00000 n \n0000000062 00000 n \n"
            . "trailer\n<< /Root 1 0 R >>\n"
            . "startxref\n{$offset}\n%%EOF\n";

        self::assertSame(3, self::read($pdf)->size());
    }

    public function testRejectsAFileWithNoStartXref(): void
    {
        $this->expectException(ParseException::class);
        $this->expectExceptionMessage('does not look like a PDF');

        self::read('just some bytes');
    }

    public function testRejectsAnOffsetOutsideTheFile(): void
    {
        $this->expectException(ParseException::class);
        $this->expectExceptionMessage('outside the file');

        self::read("%PDF-1.7\nstartxref\n99999\n%%EOF\n");
    }

    public function testReadsACrossReferenceStream(): void
    {
        $xref = self::read(self::crossReferenceStreamPdf());

        self::assertSame(9, $xref->entry(1)?->offset);
        self::assertSame(3, $xref->size());
    }

    public function testACrossReferenceStreamsDictionaryIsItsTrailer(): void
    {
        // There is no separate "trailer <<...>>" to find in such a file:
        // /Root and friends live in the stream's own dictionary.
        $root = self::read(self::crossReferenceStreamPdf())->trailer()->get('Root');

        self::assertInstanceOf(PdfReference::class, $root);
        self::assertSame(1, $root->objectId());
    }

    public function testTheMergedTrailerDropsKeysThatDescribeTheStreamItself(): void
    {
        // These would otherwise be copied forward into an incremental
        // update's trailer, leaving a classic table announcing itself as
        // a cross-reference stream.
        $trailer = self::read(self::crossReferenceStreamPdf())->trailer();

        foreach (['Type', 'W', 'Index', 'Filter', 'Length'] as $key) {
            self::assertNull($trailer->get($key), "/$key should not survive into the document trailer");
        }
    }

    public function testReadsCompressedEntriesPointingIntoAnObjectStream(): void
    {
        // The one thing a classic table cannot express at all.
        $entry = self::read(self::crossReferenceStreamPdf())->entry(2);

        self::assertTrue($entry?->isCompressed());
        self::assertSame(4, $entry->containerObjectId);
        self::assertSame(7, $entry->indexInContainer);
    }

    public function testAZeroWidthTypeFieldDefaultsToTypeOne(): void
    {
        // A saving files take when nothing in them is free or compressed.
        $rows = pack('nn', 9, 0) . pack('nn', 40, 0);
        $pdf = self::wrapXrefStream($rows, '/W [0 2 2] /Index [1 2]', 3);

        $xref = self::read($pdf);

        self::assertSame(9, $xref->entry(1)?->offset);
        self::assertFalse($xref->entry(1)?->isCompressed());
    }

    public function testReadsAPredictedCrossReferenceStream(): void
    {
        // PNG "Up" prediction is the conventional way these are written,
        // so a reader without it cannot open a modern PDF at all.
        $rows = [
            pack('Cnn', 0, 0, 65535),
            pack('Cnn', 1, 9, 0),
            pack('Cnn', 1, 40, 0),
        ];

        $predicted = '';
        $previous = str_repeat("\x00", 5);

        foreach ($rows as $row) {
            $delta = '';

            for ($i = 0; $i < 5; ++$i) {
                $delta .= chr((ord($row[$i]) - ord($previous[$i])) & 0xFF);
            }

            $predicted .= "\x02" . $delta;
            $previous = $row;
        }

        $pdf = self::wrapXrefStream(
            $predicted,
            '/W [1 2 2] /Index [0 3] /DecodeParms << /Predictor 12 /Columns 5 >>',
            3,
        );

        self::assertSame(40, self::read($pdf)->entry(2)?->offset);
    }

    public function testReportsWhichSectionFormatTheFileUses(): void
    {
        // An incremental update has to match it -- see
        // XrefTable::usesCrossReferenceStreams().
        self::assertTrue(self::read(self::crossReferenceStreamPdf())->usesCrossReferenceStreams());
        self::assertFalse(self::read(self::singleSection())->usesCrossReferenceStreams());
    }

    public function testReadsAHybridFilesXRefStmEntries(): void
    {
        // A hybrid file keeps a classic table for old readers and puts the
        // entries that table cannot express in a stream beside it.
        $body = "%PDF-1.5\n1 0 obj\n<< /Type /Catalog >>\nendobj\n";

        $streamOffset = strlen($body);
        $rows = pack('Cnn', 2, 4, 7);
        $data = gzcompress($rows);
        $body .= "9 0 obj\n<< /Type /XRef /Size 10 /Root 1 0 R /W [1 2 2] /Index [2 1] /Filter /FlateDecode /Length "
            . strlen($data) . " >>\nstream\n" . $data . "\nendstream\nendobj\n";

        $tableOffset = strlen($body);
        $pdf = $body
            . "xref\n0 2\n0000000000 65535 f \n0000000009 00000 n \n"
            . "trailer\n<< /Size 10 /Root 1 0 R /XRefStm {$streamOffset} >>\n"
            . "startxref\n{$tableOffset}\n%%EOF\n";

        $xref = self::read($pdf);

        self::assertSame(9, $xref->entry(1)?->offset, 'the classic table still governs what it covers');
        self::assertTrue($xref->entry(2)?->isCompressed(), 'and the stream supplies what it cannot');
        self::assertFalse($xref->usesCrossReferenceStreams(), 'the newest section is still the table');
    }

    public function testRejectsAnXrefStreamWithNoFieldWidths(): void
    {
        $this->expectException(ParseException::class);
        $this->expectExceptionMessage('/W array');

        self::read(self::wrapXrefStream('', '/Index [0 1]', 1));
    }

    /**
     * A file whose only section is a cross-reference stream describing
     * three objects: the free head, the catalog, and one object living
     * inside object stream 4.
     */
    private static function crossReferenceStreamPdf(): string
    {
        $rows = pack('Cnn', 0, 0, 65535)
            . pack('Cnn', 1, 9, 0)
            . pack('Cnn', 2, 4, 7);

        return self::wrapXrefStream($rows, '/W [1 2 2] /Index [0 3]', 3);
    }

    /** Wraps binary xref rows into a complete single-section PDF. */
    private static function wrapXrefStream(string $rows, string $extraEntries, int $size): string
    {
        $body = "%PDF-1.5\n1 0 obj\n<< /Type /Catalog >>\nendobj\n";
        $offset = strlen($body);
        $data = gzcompress($rows);

        return $body
            . "8 0 obj\n<< /Type /XRef /Size {$size} /Root 1 0 R {$extraEntries}"
            . " /Filter /FlateDecode /Length " . strlen($data) . " >>\n"
            . "stream\n" . $data . "\nendstream\nendobj\n"
            . "startxref\n{$offset}\n%%EOF\n";
    }

    public function testACrossReferenceStreamCannotForceAHugeAllocation(): void
    {
        // The cross-reference stream is decoded at open time, before any
        // object is requested and before any password -- so a predictor
        // with an enormous /Columns here is a pre-authentication
        // denial-of-service. It must surface as a catchable ParseException,
        // not a fatal out-of-memory. The stream body is a handful of bytes;
        // the /Columns is what would have driven the allocation.
        $rows = "\x02" . pack('Cnn', 1, 9, 0);
        $pdf = self::wrapXrefStream(
            $rows,
            '/W [1 2 2] /Index [1 1] /DecodeParms << /Predictor 12 /Columns 900000000 >>',
            2,
        );

        $this->expectException(ParseException::class);
        $this->expectExceptionMessage('does not describe this data');

        self::read($pdf);
    }

    public function testDetectsALoopInThePrevChain(): void
    {
        $body = "%PDF-1.7\n";
        $offset = strlen($body);

        $pdf = $body
            . "xref\n0 1\n0000000000 65535 f \n"
            . "trailer\n<< /Size 1 /Root 1 0 R /Prev {$offset} >>\n"
            . "startxref\n{$offset}\n%%EOF\n";

        $this->expectException(ParseException::class);
        $this->expectExceptionMessage('loop');

        self::read($pdf);
    }

    private static function read(string $pdf): XrefTable
    {
        return XrefTable::read(new Lexer($pdf));
    }

    private static function singleSection(): string
    {
        $body = "%PDF-1.7\n"
            . "1 0 obj\n<< /Type /Catalog /Pages 2 0 R >>\nendobj\n"
            . "2 0 obj\n<< /Type /Pages /Count 0 /Kids [] >>\nendobj\n";

        $offset = strlen($body);

        return $body
            . "xref\n0 3\n"
            . "0000000000 65535 f \n"
            . "0000000017 00000 n \n"
            . "0000000062 00000 n \n"
            . "trailer\n<< /Size 3 /Root 1 0 R >>\n"
            . "startxref\n{$offset}\n%%EOF\n";
    }

    /**
     * The single-section file above, plus a second section that rewrites
     * object 1 and repeats only /Size and /Prev in its trailer.
     */
    private static function incrementallyUpdated(): string
    {
        $first = self::singleSection();
        $firstXrefOffset = strpos($first, "xref\n0 3");
        self::assertIsInt($firstXrefOffset);

        $withUpdate = $first . "1 0 obj\n<< /Type /Catalog /Pages 2 0 R /Updated true >>\nendobj\n";
        $secondXrefOffset = strlen($withUpdate);

        return $withUpdate
            . "xref\n0 1\n0000000000 65535 f \n"
            . "1 1\n0000000999 00000 n \n"
            . "trailer\n<< /Size 9 /Prev {$firstXrefOffset} >>\n"
            . "startxref\n{$secondXrefOffset}\n%%EOF\n";
    }
}
