<?php

declare(strict_types=1);

namespace MightyPDF\Tests\Assembler;

use MightyPDF\Assembler\Document;
use MightyPDF\Assembler\Stream;
use MightyPDF\Crypt\Permissions;
use MightyPDF\Editor\PdfEditor;
use PHPUnit\Framework\TestCase;

final class XmpMetadataTest extends TestCase
{
    public function testADocumentThatAsksForNoneCarriesNone(): void
    {
        $document = new Document();
        $document->newPage();

        self::assertStringNotContainsString('/Metadata', $document->save());
    }

    public function testRestatesWhatTheInfoDictionarySays(): void
    {
        $document = new Document();
        $document->newPage();

        $document->info()->setTitle('Quarterly Report');
        $document->info()->setAuthor('Zoë Mikkelsen');
        $document->info()->setSubject('Results for Q2');
        $document->info()->setKeywords('results, quarterly');
        $document->info()->setCreator('MightyPDF');
        $document->info()->setProducer('MightyPDF');
        $document->metadata();

        $packet = self::packetOf($document->save());

        self::assertStringContainsString('<rdf:li xml:lang="x-default">Quarterly Report</rdf:li>', $packet);
        self::assertStringContainsString('<rdf:li>Zoë Mikkelsen</rdf:li>', $packet);
        self::assertStringContainsString('<rdf:li xml:lang="x-default">Results for Q2</rdf:li>', $packet);
        self::assertStringContainsString('<pdf:Keywords>results, quarterly</pdf:Keywords>', $packet);
        self::assertStringContainsString('<xmp:CreatorTool>MightyPDF</xmp:CreatorTool>', $packet);
    }

    public function testMetadataSetAfterTheMetadataObjectStillArrives(): void
    {
        $document = new Document();
        $document->newPage();

        // Deliberately the other way round: which of these two calls the
        // caller happens to make first must not change the file.
        $document->metadata();
        $document->info()->setTitle('Set afterwards');

        self::assertStringContainsString('Set afterwards', self::packetOf($document->save()));
    }

    public function testTitleAndDescriptionAreLanguageAlternatives(): void
    {
        $document = new Document();
        $document->newPage();
        $document->info()->setTitle('A title');
        $document->metadata();

        // A bare string in dc:title is read by some tools and not others;
        // rdf:Alt is what the specification asks for.
        self::assertStringContainsString(
            "<dc:title>\n    <rdf:Alt>\n     <rdf:li xml:lang=\"x-default\">A title</rdf:li>",
            self::packetOf($document->save()),
        );
    }

    public function testTheAuthorBecomesAnOrderedList(): void
    {
        $document = new Document();
        $document->newPage();
        $document->info()->setAuthor('Ada Lovelace');
        $document->metadata();

        self::assertStringContainsString(
            "<dc:creator>\n    <rdf:Seq>\n     <rdf:li>Ada Lovelace</rdf:li>",
            self::packetOf($document->save()),
        );
    }

    public function testDatesAreWrittenInIso8601(): void
    {
        $document = new Document();
        $document->newPage();
        $document->info()->setCreationDate(new \DateTimeImmutable('2026-08-07 12:30:00', new \DateTimeZone('UTC')));
        $document->info()->setModificationDate(new \DateTimeImmutable('2026-08-09 09:00:00', new \DateTimeZone('+02:00')));
        $document->metadata();

        $packet = self::packetOf($document->save());

        // "Z" rather than "+00:00": both are legal and Z is what every
        // other producer writes.
        self::assertStringContainsString('<xmp:CreateDate>2026-08-07T12:30:00Z</xmp:CreateDate>', $packet);
        self::assertStringContainsString('<xmp:ModifyDate>2026-08-09T09:00:00+02:00</xmp:ModifyDate>', $packet);
    }

    public function testTheDateInTheInfoDictionaryStillUsesPdfsOwnFormat(): void
    {
        $document = new Document();
        $document->newPage();
        $document->info()->setModificationDate(
            new \DateTimeImmutable('2026-08-09 09:00:00', new \DateTimeZone('+02:00')),
        );

        self::assertStringContainsString("(D:20260809090000+02'00')", $document->save());
    }

    public function testCarriesRightsWhichTheInfoDictionaryCannot(): void
    {
        $document = new Document();
        $document->newPage();
        $document->metadata()->setRights('© 2026 Acme Ltd');

        self::assertStringContainsString(
            '<rdf:li xml:lang="x-default">© 2026 Acme Ltd</rdf:li>',
            self::packetOf($document->save()),
        );
    }

    public function testCarriesAssetIdsWhenGivenThem(): void
    {
        $document = new Document();
        $document->newPage();
        $document->metadata()
            ->setDocumentId('uuid:0d1b7f0e-8e5a-4a5f-9a4e-1f2c3d4e5f60')
            ->setInstanceId('uuid:aaaa1111-2222-3333-4444-555566667777');

        $packet = self::packetOf($document->save());

        self::assertStringContainsString('<xmpMM:DocumentID>uuid:0d1b7f0e', $packet);
        self::assertStringContainsString('<xmpMM:InstanceID>uuid:aaaa1111', $packet);
    }

    public function testInventsNoIdentityOfItsOwn(): void
    {
        $document = new Document();
        $document->newPage();
        $document->metadata();

        // An id made up per save would change every time the document was
        // rebuilt, which is the opposite of what an asset id is for.
        self::assertStringNotContainsString('xmpMM:DocumentID', self::packetOf($document->save()));
    }

    public function testUnsetFieldsAreLeftOutEntirely(): void
    {
        $document = new Document();
        $document->newPage();
        $document->metadata();

        $packet = self::packetOf($document->save());

        self::assertStringNotContainsString('dc:title', $packet);
        self::assertStringNotContainsString('<pdf:Keywords>', $packet);
    }

    public function testEscapesMarkupInAValue(): void
    {
        $document = new Document();
        $document->newPage();
        $document->info()->setTitle('Bolts & <Nuts>');
        $document->metadata();

        $packet = self::packetOf($document->save());

        self::assertStringContainsString('Bolts &amp; &lt;Nuts&gt;', $packet);
        self::assertStringNotContainsString('<Nuts>', $packet);
    }

    public function testAWholePacketCanBeSuppliedInstead(): void
    {
        $document = new Document();
        $document->newPage();
        $document->info()->setTitle('Ignored');
        $document->metadata()->setPacket('<x:xmpmeta>my own packet</x:xmpmeta>');

        $packet = self::packetOf($document->save());

        self::assertSame('<x:xmpmeta>my own packet</x:xmpmeta>', $packet);
        self::assertStringNotContainsString('Ignored', $packet);
    }

    public function testThePacketIsWellFormedXml(): void
    {
        $document = new Document();
        $document->newPage();
        $document->info()->setTitle('Quarterly Report');
        $document->info()->setAuthor('Zoë Mikkelsen');
        $document->metadata()->setRights('© 2026 Acme Ltd');

        $packet = self::packetOf($document->save());
        $xml = simplexml_load_string(preg_replace('/<\?xpacket.*?\?>/', '', $packet) ?? '');

        self::assertNotFalse($xml, 'The generated XMP packet should parse as XML.');
    }

    public function testTheStreamIsNotCompressed(): void
    {
        $document = new Document();
        $document->newPage();
        $document->metadata();

        $editor = PdfEditor::fromBytes($document->save());
        $stream = $editor->resolve($editor->catalog()->get('Metadata'));

        self::assertInstanceOf(Stream::class, $stream);

        // §14.3.2 exists so a consumer can find this without understanding
        // the rest of the file; a deflated packet defeats that.
        self::assertNull($stream->get('Filter'));
    }

    public function testAnEncryptedDocumentEnciphersItsMetadataByDefault(): void
    {
        $document = new Document();
        $document->newPage();
        $document->info()->setTitle('Confidential');
        $document->metadata();
        $document->encrypt('owner', permissions: Permissions::PRINT);

        // Table 21's default, and the safe one: metadata is content too,
        // and a title can be as revealing as the page it describes.
        self::assertStringNotContainsString('Confidential', $document->save());
    }

    public function testMetadataCanBeLeftReadableInAnEncryptedDocument(): void
    {
        $document = new Document();
        $document->newPage();
        $document->info()->setTitle('Findable');
        $document->metadata();
        $document->encrypt('owner', permissions: Permissions::PRINT, encryptMetadata: false);

        // The point of asking for this: an indexer with no password still
        // gets the title of a document whose pages it cannot read.
        self::assertStringContainsString('Findable', $document->save());
    }

    public function testTheEncryptDictionarySaysSoWhenMetadataIsLeftReadable(): void
    {
        $document = new Document();
        $document->newPage();
        $document->metadata();
        $document->encrypt('owner', encryptMetadata: false);

        self::assertStringContainsString('/EncryptMetadata false', $document->save());
    }

    public function testTheDefaultIsNotWrittenOut(): void
    {
        $document = new Document();
        $document->newPage();
        $document->encrypt('owner');

        // A reader being told its own default is a reader given noise.
        self::assertStringNotContainsString('/EncryptMetadata', $document->save());
    }

    public function testAReadableMetadataDocumentStillOpens(): void
    {
        $document = new Document();
        $document->newPage();
        $document->info()->setTitle('Findable');
        $document->metadata();
        $document->encrypt('owner', 'user', encryptMetadata: false);

        // The /Perms block carries an enciphered copy of the same flag, so
        // a mismatch reads as a tampered document and the file will not
        // open at all.
        $editor = PdfEditor::fromBytes($document->save(), 'user');

        self::assertFalse($editor->store()->security()?->encryptsMetadata());
        self::assertStringContainsString('Findable', self::packetOfEditor($editor));
    }

    private static function packetOf(string $pdf): string
    {
        return self::packetOfEditor(PdfEditor::fromBytes($pdf));
    }

    private static function packetOfEditor(PdfEditor $editor): string
    {
        $stream = $editor->resolve($editor->catalog()->get('Metadata'));

        self::assertInstanceOf(Stream::class, $stream);

        return trim($editor->store()->decodedStream($stream));
    }
}
