<?php

declare(strict_types=1);

namespace MightyPDF\Tests\Content;

use MightyPDF\Assembler\Annotation\AttachmentIcon;
use MightyPDF\Assembler\Document;
use MightyPDF\Assembler\Types\PdfReference;
use MightyPDF\Content\PageBuilder;
use MightyPDF\Tests\Support\SavedDocument;
use PHPUnit\Framework\TestCase;

final class FileAttachmentAnnotationTest extends TestCase
{
    public function testAnIconOnThePagePointsAtTheFileTheDocumentAlreadyCarries(): void
    {
        $document = new Document();
        $page = $document->newPage();

        $workings = $document->attach('workings.csv', "a,b\n1,2\n", mediaType: 'text/csv');
        (new PageBuilder($document, $page))->addFileAttachment($workings, 500, 640);

        $saved = SavedDocument::of($document);
        $annotation = $saved->annotations()[0];

        self::assertSame('FileAttachment', $annotation->get('Subtype')?->value());
        self::assertSame('PushPin', $annotation->get('Name')?->value());
        self::assertSame('[500 640 520 660]', $annotation->get('Rect')?->format());

        // The point of the feature: the icon references the file
        // specification the document already carries rather than
        // embedding a second copy of it.
        $reference = $annotation->get('FS');
        self::assertInstanceOf(PdfReference::class, $reference);
        self::assertSame($workings->objectId(), $reference->objectId());

        // One embedded file, not two: the panel entry and the icon are
        // the same attachment.
        self::assertSame(1, substr_count($saved->bytes(), '/Type /EmbeddedFile'));
    }

    public function testTheIconIsListedInThePagesAnnotations(): void
    {
        $document = new Document();
        $page = $document->newPage();

        $file = $document->attach('notes.txt', 'x');
        (new PageBuilder($document, $page))->addFileAttachment($file, 100, 100);

        self::assertCount(1, SavedDocument::of($document)->annotations());
    }

    public function testTheTooltipFallsBackToTheFilename(): void
    {
        $document = new Document();
        $page = $document->newPage();

        $file = $document->attach('notes.txt', 'x');
        (new PageBuilder($document, $page))->addFileAttachment($file, 100, 100);

        $annotation = SavedDocument::of($document)->annotations()[0];

        self::assertSame('notes.txt', SavedDocument::scalar($annotation->get('Contents')));
    }

    public function testANoteReplacesIt(): void
    {
        $document = new Document();
        $page = $document->newPage();

        $file = $document->attach('notes.txt', 'x');
        (new PageBuilder($document, $page))->addFileAttachment(
            $file,
            100,
            100,
            icon: AttachmentIcon::Paperclip,
            note: 'The reconciliation behind this figure',
        );

        $annotation = SavedDocument::of($document)->annotations()[0];

        self::assertSame('Paperclip', $annotation->get('Name')?->value());
        self::assertSame(
            'The reconciliation behind this figure',
            SavedDocument::scalar($annotation->get('Contents')),
        );
    }

    /** Without the print flag the icon is absent from a printed copy. */
    public function testTheAnnotationIsMarkedForPrinting(): void
    {
        $document = new Document();
        $page = $document->newPage();

        $file = $document->attach('notes.txt', 'x');
        (new PageBuilder($document, $page))->addFileAttachment($file, 100, 100);

        // Bit 3, "Print" -- asserted on the annotation rather than on the
        // file, where "/F 4" also matches a font resource or a form field
        // flag in any document that has one.
        self::assertSame(4, SavedDocument::of($document)->annotations()[0]->get('F')?->value());
    }
}
