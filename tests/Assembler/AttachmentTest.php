<?php

declare(strict_types=1);

namespace MightyPDF\Tests\Assembler;

use MightyPDF\Assembler\Attachment\AttachmentRelationship;
use MightyPDF\Assembler\Document;
use MightyPDF\Assembler\PageMode;
use PHPUnit\Framework\TestCase;

final class AttachmentTest extends TestCase
{
    public function testAnAttachmentBecomesAnEmbeddedFileStreamAndAFileSpecification(): void
    {
        $document = new Document();
        $document->newPage();
        $document->attach('data.csv', "a,b\n1,2\n", 'The figures behind the chart', 'text/csv');

        $output = $document->save();

        self::assertStringContainsString('/Type /EmbeddedFile', $output);
        self::assertStringContainsString('/Type /Filespec', $output);
        self::assertStringContainsString('(data.csv)', $output);
        self::assertStringContainsString('/Subtype /text#2Fcsv', $output, 'the slash has to be escaped in a name');
        self::assertStringContainsString('/EmbeddedFiles', $output);
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

        $output = $document->save();

        self::assertStringContainsString('/Size ' . strlen($bytes), $output);
        self::assertStringContainsString('/CheckSum <' . md5($bytes) . '>', $output);
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

        $output = $document->save();

        self::assertStringContainsString('/AFRelationship /Data', $output);
        // And listed at the top level, where a consumer looks for it
        // without walking the name tree.
        self::assertMatchesRegularExpression('/\/AF \[\d+ 0 R\]/', $output);
    }

    public function testNoRelationshipMeansNoEntryRatherThanOneSayingNothing(): void
    {
        $document = new Document();
        $document->newPage();
        $document->attach('notes.txt', 'hello');

        self::assertStringNotContainsString('/AFRelationship', $document->save());
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

        $output = $document->save();

        preg_match('/\/Names \[(.*?)\]/s', $output, $matches);

        self::assertLessThan(strpos($matches[1], 'mike'), strpos($matches[1], 'alpha'));
        self::assertLessThan(strpos($matches[1], 'zebra'), strpos($matches[1], 'mike'));
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

        $output = $document->save();

        // Only the /F entry is a path; the name-tree key and /UF keep the
        // name as given, since neither is interpreted as one.
        self::assertStringContainsString('/F (.._.._etc_passwd)', $output);
        self::assertStringNotContainsString('/F (../../etc/passwd)', $output);
    }

    public function testANonAsciiNameSurvivesInTheUnicodeEntry(): void
    {
        $document = new Document();
        $document->newPage();
        $document->attach('Rapport financier — 2026.csv', 'x');

        $output = $document->save();

        // /F holds an ASCII-safe rendering -- one underscore for the em
        // dash, not one per byte of it.
        self::assertStringContainsString('/F (Rapport financier _ 2026.csv)', $output);

        // /UF holds the real thing as a UTF-16BE text string, led by the
        // byte-order mark that marks it as one.
        self::assertStringContainsString(
            "\xFE\xFF" . mb_convert_encoding('Rapport financier — 2026.csv', 'UTF-16BE', 'UTF-8'),
            $output,
        );
    }

    public function testAttachingAsksForTheAttachmentsPanel(): void
    {
        $document = new Document();
        $document->newPage();
        $document->attach('data.csv', 'x');

        self::assertStringContainsString('/PageMode /UseAttachments', $document->save());
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

        $output = $document->save();

        self::assertStringContainsString('/PageMode /FullScreen', $output);
        self::assertStringNotContainsString('/UseAttachments', $output);
        self::assertStringNotContainsString('/UseOutlines', $output);
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

        self::assertStringNotContainsString('the contents', $output);
        self::assertStringContainsString('/Type /EmbeddedFile', $output);
    }
}
