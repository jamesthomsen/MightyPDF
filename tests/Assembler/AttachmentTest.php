<?php

declare(strict_types=1);

namespace MightyPDF\Tests\Assembler;

use MightyPDF\Assembler\Attachment\AttachmentRelationship;
use MightyPDF\Assembler\Document;
use MightyPDF\Assembler\PageMode;
use MightyPDF\Tests\Support\SavedDocument;
use PHPUnit\Framework\TestCase;

final class AttachmentTest extends TestCase
{
    public function testAnAttachmentBecomesAnEmbeddedFileStreamAndAFileSpecification(): void
    {
        $document = new Document();
        $document->newPage();
        $document->attach('data.csv', "a,b\n1,2\n", 'The figures behind the chart', 'text/csv');

        $saved = SavedDocument::of($document);

        // Reached the way a reader reaches it: catalog, name tree, the
        // value beside the key. A file specification that exists but is
        // not listed here is a file nobody can open.
        self::assertSame('data.csv', $saved->value('Names', 'EmbeddedFiles', 'Names', 0));

        $specification = $saved->dictionary('Names', 'EmbeddedFiles', 'Names', 1);
        self::assertSame('Filespec', $specification->get('Type')?->value());
        self::assertSame('The figures behind the chart', SavedDocument::scalar($specification->get('Desc')));

        $embedded = $saved->stream('Names', 'EmbeddedFiles', 'Names', 1, 'EF', 'F');
        self::assertSame('EmbeddedFile', $embedded->get('Type')?->value());
        self::assertSame('text/csv', $embedded->get('Subtype')?->value(), 'the escaped name decodes back');
        self::assertSame("a,b\n1,2\n", $saved->editor()->store()->decodedStream($embedded));
    }

    /**
     * The size and checksum are of the bytes as given, not as stored --
     * the stream itself is deflated on the way out.
     */
    public function testTheStreamRecordsTheUncompressedSizeAndChecksum(): void
    {
        $bytes = str_repeat('payload', 100);

        $document = new Document();
        $document->newPage();
        $document->attach('big.txt', $bytes);

        $parameters = SavedDocument::of($document)
            ->dictionary('Names', 'EmbeddedFiles', 'Names', 1, 'EF', 'F', 'Params');

        self::assertSame(strlen($bytes), $parameters->get('Size')?->value());
        self::assertSame(md5($bytes, true), $parameters->get('CheckSum')?->bytes());
    }

    public function testTheRelationshipIsWrittenWhenOneIsClaimed(): void
    {
        $document = new Document();
        $document->newPage();
        $document->attach(
            'factur-x.xml',
            '<invoice/>',
            mediaType: 'application/xml',
            relationship: AttachmentRelationship::Data,
        );

        $saved = SavedDocument::of($document);

        self::assertSame('Data', $saved->value('Names', 'EmbeddedFiles', 'Names', 1, 'AFRelationship'));

        // And listed at the top level, where a consumer looks for it
        // without walking the name tree -- the same specification, not a
        // second copy of it.
        self::assertCount(1, $saved->array('AF')->items());
        self::assertSame(
            'factur-x.xml',
            SavedDocument::scalar($saved->dictionary('AF', 0)->get('UF')),
        );
    }

    public function testNoRelationshipMeansNoEntryRatherThanOneSayingNothing(): void
    {
        $document = new Document();
        $document->newPage();
        $document->attach('notes.txt', 'hello');

        self::assertNull(SavedDocument::of($document)->at('Names', 'EmbeddedFiles', 'Names', 1, 'AFRelationship'));
    }

    /**
     * A name tree's keys have to be sorted: a reader is entitled to
     * binary-search them, and in an unsorted node some entries simply
     * cannot be found.
     */
    public function testTheNameTreeIsSortedWhateverOrderTheAttachmentsArrived(): void
    {
        $document = new Document();
        $document->newPage();

        foreach (['zebra.txt', 'alpha.txt', 'mike.txt'] as $name) {
            $document->attach($name, 'x');
        }

        $names = SavedDocument::of($document)->array('Names', 'EmbeddedFiles', 'Names');

        // Every even entry is a key; the odd ones are the specifications
        // beside them.
        $keys = [];

        foreach ($names->items() as $position => $item) {
            if ($position % 2 === 0) {
                $keys[] = SavedDocument::scalar($item);
            }
        }

        self::assertSame(['alpha.txt', 'mike.txt', 'zebra.txt'], $keys);
    }

    public function testTwoAttachmentsCannotShareAName(): void
    {
        $document = new Document();
        $document->attach('report.pdf', 'a');

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('already carries an attachment called "report.pdf"');

        $document->attach('report.pdf', 'b');
    }

    public function testAnEmptyNameIsRefused(): void
    {
        $document = new Document();

        $this->expectException(\InvalidArgumentException::class);

        $document->attach('', 'x');
    }

    /**
     * The old /F entry is a path in PDF's own grammar, so a separator in
     * it would be read as a directory. The real name survives in /UF.
     */
    public function testAPathSeparatorIsStrippedFromTheLegacyNameEntry(): void
    {
        $document = new Document();
        $document->newPage();
        $document->attach('../../etc/passwd', 'x');

        $specification = SavedDocument::of($document)->dictionary('Names', 'EmbeddedFiles', 'Names', 1);

        // Only the /F entry is a path; the name-tree key and /UF keep the
        // name as given, since neither is interpreted as one.
        self::assertSame('.._.._etc_passwd', SavedDocument::scalar($specification->get('F')));
        self::assertSame('../../etc/passwd', SavedDocument::scalar($specification->get('UF')));
    }

    public function testANonAsciiNameSurvivesInTheUnicodeEntry(): void
    {
        $document = new Document();
        $document->newPage();
        $document->attach('Rapport financier — 2026.csv', 'x');

        $specification = SavedDocument::of($document)->dictionary('Names', 'EmbeddedFiles', 'Names', 1);

        // /F holds an ASCII-safe rendering -- one underscore for the em
        // dash, not one per byte of it.
        self::assertSame('Rapport financier _ 2026.csv', SavedDocument::scalar($specification->get('F')));

        // /UF holds the real thing. Asserted as the text it decodes to
        // rather than as UTF-16BE bytes with a byte-order mark: the
        // encoding is how a text string is stored, and the claim is
        // about the name surviving.
        self::assertSame('Rapport financier — 2026.csv', SavedDocument::scalar($specification->get('UF')));
    }

    public function testAttachingAsksForTheAttachmentsPanel(): void
    {
        $document = new Document();
        $document->newPage();
        $document->attach('data.csv', 'x');

        self::assertSame('UseAttachments', SavedDocument::of($document)->value('PageMode'));
    }

    /**
     * A document that said what it wanted keeps it. Overriding that
     * because an attachment was added afterwards makes the document open
     * differently depending on the order of two unrelated calls.
     */
    public function testAPageModeAlreadyAskedForIsNotOverridden(): void
    {
        $document = new Document();
        $document->newPage();
        $document->setPageMode(PageMode::FullScreen);
        $document->attach('data.csv', 'x');
        $document->outline();

        self::assertSame('FullScreen', SavedDocument::of($document)->value('PageMode'));
    }

    public function testAttachmentsAreListedByName(): void
    {
        $document = new Document();
        $specification = $document->attach('data.csv', 'x');

        self::assertSame(['data.csv' => $specification], $document->attachments());
        self::assertSame('data.csv', $specification->name());
    }

    public function testAnAttachedFileSurvivesEncryption(): void
    {
        $document = new Document();
        $document->newPage();
        $document->attach('secret.txt', 'the contents');
        $document->encrypt('owner', 'user');

        $output = $document->save();

        self::assertStringNotContainsString('the contents', $output, 'the payload must be enciphered');

        // And comes back out again for someone holding the password.
        $saved = SavedDocument::fromBytes($output, 'user');
        $embedded = $saved->stream('Names', 'EmbeddedFiles', 'Names', 1, 'EF', 'F');

        self::assertSame('EmbeddedFile', $embedded->get('Type')?->value());
        self::assertSame('the contents', $saved->editor()->store()->decodedStream($embedded));
    }
}
