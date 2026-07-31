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

    public function testNamesCrossReferenceStreamsRatherThanFailingObscurely(): void
    {
        // The common case in PDF 1.5+ files, and the next thing to build.
        // Saying so beats "expected keyword".
        $body = "%PDF-1.7\n";
        $offset = strlen($body);

        $pdf = $body
            . "1 0 obj\n<< /Type /XRef /Size 2 >>\nstream\nx\nendstream\nendobj\n"
            . "startxref\n{$offset}\n%%EOF\n";

        $this->expectException(ParseException::class);
        $this->expectExceptionMessage('cross-reference streams are not supported yet');

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
