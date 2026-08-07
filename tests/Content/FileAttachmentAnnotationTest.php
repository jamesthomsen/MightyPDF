<?php

declare(strict_types=1);

namespace MightyPDF\Tests\Content;

use MightyPDF\Assembler\Annotation\AttachmentIcon;
use MightyPDF\Assembler\Document;
use MightyPDF\Content\PageBuilder;
use PHPUnit\Framework\TestCase;

final class FileAttachmentAnnotationTest extends TestCase
{
    public function testAnIconOnThePagePointsAtTheFileTheDocumentAlreadyCarries(): void
    {
        $document = new Document();
        $page = $document->newPage();

        $workings = $document->attach('workings.csv', "a,b\n1,2\n", mediaType: 'text/csv');
        (new PageBuilder($document, $page))->addFileAttachment($workings, 500, 640);

        $output = $document->save();

        self::assertStringContainsString('/Subtype /FileAttachment', $output);
        self::assertStringContainsString('/FS ' . $workings->objectId() . ' 0 R', $output);
        self::assertStringContainsString('/Name /PushPin', $output);
        self::assertStringContainsString('/Rect [500 640 520 660]', $output);

        // One embedded file, not two: the panel entry and the icon are
        // the same attachment.
        self::assertSame(1, substr_count($output, '/Type /EmbeddedFile'));
    }

    public function testTheIconIsListedInThePagesAnnotations(): void
    {
        $document = new Document();
        $page = $document->newPage();

        $file = $document->attach('notes.txt', 'x');
        (new PageBuilder($document, $page))->addFileAttachment($file, 100, 100);

        self::assertStringContainsString('/Annots [', $page->render(true));
    }

    public function testTheTooltipFallsBackToTheFilename(): void
    {
        $document = new Document();
        $page = $document->newPage();

        $file = $document->attach('notes.txt', 'x');
        (new PageBuilder($document, $page))->addFileAttachment($file, 100, 100);

        self::assertStringContainsString('/Contents (notes.txt)', $document->save());
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

        $output = $document->save();

        self::assertStringContainsString('/Name /Paperclip', $output);
        self::assertStringContainsString('/Contents (The reconciliation behind this figure)', $output);
    }

    /** Without the print flag the icon is absent from a printed copy. */
    public function testTheAnnotationIsMarkedForPrinting(): void
    {
        $document = new Document();
        $page = $document->newPage();

        $file = $document->attach('notes.txt', 'x');
        (new PageBuilder($document, $page))->addFileAttachment($file, 100, 100);

        self::assertStringContainsString('/F 4', $document->save());
    }
}
