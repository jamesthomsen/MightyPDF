<?php

declare(strict_types=1);

namespace MightyPDF\Tests\Assembler;

use MightyPDF\Assembler\Document;
use MightyPDF\Assembler\PageSize;
use MightyPDF\Content\Font\StandardFont;
use MightyPDF\Content\PageBuilder;
use MightyPDF\Crypt\Permissions;
use MightyPDF\Editor\PdfEditor;
use MightyPDF\Layout\Flow;
use MightyPDF\Layout\Unit;
use MightyPDF\Reader\Text\TextExtractor;
use PHPUnit\Framework\TestCase;

/**
 * writeTo() and save() are one code path with two destinations, so what
 * these tests mostly assert is that the two agree -- byte for byte,
 * through every tail the writer can produce (classic table, cross-
 * reference stream, encrypted). A difference between them would be an
 * offset computed from the wrong place, which is precisely the bug class
 * ByteSink exists to make impossible.
 */
final class DocumentStreamingTest extends TestCase
{
    public function testStreamedBytesMatchSavedBytes(): void
    {
        $document = self::report();

        self::assertSame($document->save(), self::streamed($document));
    }

    /**
     * The cross-reference *stream* tail, which computes the xref
     * stream's own object id and offset separately from the body's.
     */
    public function testStreamedBytesMatchWithObjectStreams(): void
    {
        $document = self::report();
        $document->compressObjects();

        self::assertSame($document->save(), self::streamed($document));
    }

    /**
     * Not a byte comparison, because an encrypted document is not
     * reproducible: every save picks fresh randomness, so save() does
     * not even match itself. What is asserted instead is the thing that
     * would break if the encrypted path's offsets were wrong -- the file
     * opens, and its text comes back out.
     *
     * This is the path where the /Encrypt dictionary is held out of the
     * object streams (see Document::save()'s $keepDirect argument), so
     * it is worth streaming on its own account.
     */
    public function testAStreamedEncryptedDocumentOpens(): void
    {
        $document = self::report();
        $document->compressObjects();
        $document->encrypt('owner', 'user', Permissions::all());

        $editor = PdfEditor::fromBytes(self::streamed($document), 'user');

        self::assertStringContainsString('Section 8', (new TextExtractor($editor))->text());
    }

    public function testStreamingTwiceGivesTheSameBytes(): void
    {
        $document = self::report();

        self::assertSame(self::streamed($document), self::streamed($document));
    }

    /**
     * The assertion that actually exercises the offsets rather than
     * comparing two runs of the same arithmetic: a reader locates every
     * object through the xref this path built, so text coming back out
     * means the offsets point where the objects really are.
     */
    public function testAStreamedDocumentReadsBack(): void
    {
        $editor = PdfEditor::fromBytes(self::streamed(self::report()));
        $text = (new TextExtractor($editor))->text();

        self::assertStringContainsString('Section 1', $text);
        self::assertStringContainsString('Section 8', $text);
    }

    public function testAStreamedPackedDocumentReadsBack(): void
    {
        $document = self::report();
        $document->compressObjects();

        $extractor = new TextExtractor(PdfEditor::fromBytes(self::streamed($document)));

        self::assertSame(8, $extractor->pageCount());
        self::assertStringContainsString('Section 8', $extractor->text());
    }

    public function testWriteToLeavesTheHandleOpen(): void
    {
        $handle = fopen('php://memory', 'w+b');
        self::assertIsResource($handle);

        self::report()->writeTo($handle);

        // Still usable: the caller may have a footer of its own to add,
        // or an HTTP response to finish.
        self::assertIsResource($handle);
        self::assertTrue(rewind($handle));

        fclose($handle);
    }

    public function testSaveToFileWritesTheSameBytes(): void
    {
        $document = self::report();
        $path = tempnam(sys_get_temp_dir(), 'mightypdf-streaming-');
        self::assertIsString($path);

        try {
            $document->saveToFile($path);

            self::assertSame($document->save(), file_get_contents($path));
        } finally {
            unlink($path);
        }
    }

    public function testSaveToFileStillReportsAnUnwritablePath(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/Failed to write PDF to/');

        self::report()->saveToFile(__DIR__ . '/no-such-directory-here/out.pdf');
    }

    public function testFlowRunsItsPerPageHooksBeforeStreaming(): void
    {
        $flow = new Flow(new Document(), PageSize::A4, unit: Unit::Millimetres);
        $flow->onEachPage(static function (Flow $flow, int $page, int $total): void {
            $flow->textAt(20, 280, "Page $page of $total");
        });
        $flow->write('Body text.');

        $handle = fopen('php://memory', 'w+b');
        self::assertIsResource($handle);
        $flow->writeTo($handle);
        rewind($handle);
        $bytes = (string) stream_get_contents($handle);
        fclose($handle);

        $text = (new TextExtractor(PdfEditor::fromBytes($bytes)))->text();

        self::assertStringContainsString('Page 1 of 1', $text);
        self::assertStringContainsString('Body text.', $text);
    }

    /** A document with enough in it to span several objects and a few pages. */
    private static function report(): Document
    {
        $document = new Document();

        for ($section = 1; $section <= 8; ++$section) {
            $page = $document->newPage(PageSize::A4);
            $builder = new PageBuilder($document, $page);

            $builder->drawText(StandardFont::HelveticaBold, 18.0, 50.0, 780.0, "Section $section");
            $builder->drawText(StandardFont::Helvetica, 11.0, 50.0, 750.0, 'Body copy for the section above.');
            $builder->fillRectangle(50.0, 700.0, 200.0, 20.0, 0.9, 0.9, 0.9);
        }

        return $document;
    }

    /** @return string everything writeTo() put on a memory stream */
    private static function streamed(Document $document): string
    {
        $handle = fopen('php://memory', 'w+b');

        if ($handle === false) {
            self::fail('Could not open a memory stream.');
        }

        $document->writeTo($handle);
        rewind($handle);
        $bytes = (string) stream_get_contents($handle);
        fclose($handle);

        return $bytes;
    }
}
