<?php

declare(strict_types=1);

namespace MightyPDF\Tests\Crypt;

use MightyPDF\Assembler\Document;
use MightyPDF\Assembler\Stream;
use MightyPDF\Assembler\Types\PdfArray;
use MightyPDF\Content\Font\StandardFont;
use MightyPDF\Content\PageBuilder;
use MightyPDF\Crypt\DecryptionException;
use MightyPDF\Crypt\Permissions;
use MightyPDF\Editor\Form\FormFiller;
use MightyPDF\Editor\PdfEditor;
use MightyPDF\Reader\ObjectStore;
use PHPUnit\Framework\TestCase;

/**
 * Writing an encrypted document, which is AES-256 only -- there is no
 * reason to put a broken cipher into a file being made today.
 *
 * The output has been confirmed to open in poppler, which reports it as
 * AES-256 with exactly the permissions granted, and in Ghostscript with
 * no warnings.
 */
final class DocumentEncryptionTest extends TestCase
{
    private const string PAGE_TEXT = 'Something worth protecting';

    public function testAnUnencryptedDocumentStaysUnencrypted(): void
    {
        $store = new ObjectStore(self::document()->save());

        self::assertFalse($store->isEncrypted());
    }

    public function testTheContentIsActuallyEnciphered(): void
    {
        // The point of the exercise. Shown against the same document
        // saved unencrypted, where the text really is recoverable, so
        // that this is a comparison rather than a claim.
        self::assertStringContainsString(
            self::PAGE_TEXT,
            self::firstPageContent(new ObjectStore(self::document()->save())),
            'unencrypted: the content stream inflates to the text',
        );

        self::assertStringNotContainsString(self::PAGE_TEXT, self::encrypted());
    }

    public function testItReadsBackWithAnEmptyUserPassword(): void
    {
        $store = new ObjectStore(self::encrypted());

        self::assertTrue($store->isEncrypted());
        self::assertStringContainsString(self::PAGE_TEXT, self::firstPageContent($store));
    }

    public function testStringsComeBackToo(): void
    {
        // Encryption reaches the leaves of an object, so a field value is
        // as enciphered as a content stream.
        $filler = new FormFiller(PdfEditor::fromBytes(self::encrypted()));

        self::assertSame(['greeting' => 'Zoë'], $filler->values());
    }

    public function testARealUserPasswordIsRequiredToOpenIt(): void
    {
        $this->expectException(DecryptionException::class);
        $this->expectExceptionMessage('Supply the password');

        new ObjectStore(self::encrypted(userPassword: 'letmein'));
    }

    public function testEitherPasswordOpensIt(): void
    {
        $encrypted = self::encrypted(userPassword: 'letmein');

        foreach (['letmein', 'owner-pw'] as $password) {
            $store = new ObjectStore($encrypted, $password);

            self::assertStringContainsString(self::PAGE_TEXT, self::firstPageContent($store), $password);
        }
    }

    public function testTheEncryptDictionaryIsLeftInTheClear(): void
    {
        // It is what tells a reader how to decrypt everything else;
        // enciphering it would leave a file nothing could open.
        $store = new ObjectStore(self::encrypted());
        $encrypt = $store->resolveDictionary($store->trailer()->get('Encrypt'));

        self::assertSame('Standard', $encrypt?->get('Filter')?->value());
        self::assertSame(5, $encrypt->get('V')?->value());
        self::assertSame(6, $encrypt->get('R')?->value());
    }

    /**
     * Table 20 scopes /Length to /V 2 and 3, so a V5 dictionary without
     * one is correct -- but qpdf --check asks for it regardless of /V and
     * warns when it is missing, which made every encrypted file this
     * library produced fail the checker. Written in bits, matching the
     * key length, while the crypt filter's own /Length is in bytes.
     */
    public function testTheEncryptDictionaryStatesItsKeyLength(): void
    {
        $store = new ObjectStore(self::encrypted());
        $encrypt = $store->resolveDictionary($store->trailer()->get('Encrypt'));

        self::assertSame(256, $encrypt?->get('Length')?->value());

        $filter = $store->resolveDictionary($encrypt->get('CF'));
        $standard = $store->resolveDictionary($filter?->get('StdCF'));

        self::assertSame(32, $standard?->get('Length')?->value());
    }

    public function testAnEncryptedDocumentCarriesAnId(): void
    {
        // Required for an encrypted file, and both halves match for one
        // that has never been updated.
        $store = new ObjectStore(self::encrypted());
        $id = $store->trailer()->get('ID');

        self::assertInstanceOf(PdfArray::class, $id);
        self::assertCount(2, $id->items());
        self::assertSame($id->items()[0]->format(), $id->items()[1]->format());
    }

    public function testEachSaveUsesFreshInitialisationVectors(): void
    {
        // Two saves of one document must not produce identical ciphertext
        // for identical plaintext.
        $document = self::document();
        $document->encrypt(ownerPassword: 'owner-pw');

        self::assertNotSame($document->save(), $document->save());
    }

    public function testRefusesToEncryptTwice(): void
    {
        $document = self::document();
        $document->encrypt(ownerPassword: 'owner-pw');

        $this->expectException(\LogicException::class);
        $document->encrypt(ownerPassword: 'another');
    }

    public function testGrantsExactlyThePermissionsAskedFor(): void
    {
        // Verified independently: poppler reports this document as
        // "print:yes copy:no change:no addNotes:no".
        $document = self::document();
        $document->encrypt(
            ownerPassword: 'owner-pw',
            permissions: Permissions::allowing(Permissions::PRINT | Permissions::FILL_FORMS),
        );

        $store = new ObjectStore($document->save());
        $encrypt = $store->resolveDictionary($store->trailer()->get('Encrypt'));
        $granted = $encrypt?->get('P')?->value();

        self::assertIsInt($granted);
        self::assertSame(Permissions::PRINT, $granted & Permissions::PRINT);
        self::assertSame(Permissions::FILL_FORMS, $granted & Permissions::FILL_FORMS);
        self::assertSame(0, $granted & Permissions::COPY);
        self::assertSame(0, $granted & Permissions::MODIFY);
    }

    public function testEverythingPermittedIsTheConventionalMinusFour(): void
    {
        // The spec fixes most of the word, which is why /P is a large
        // negative number rather than a small positive one.
        self::assertSame(-4, Permissions::all());
        self::assertSame(-3900, Permissions::allowing(Permissions::PRINT));
    }

    public function testAnEncryptedDocumentCanThenBeEditedAndStaysReadable(): void
    {
        // The editor re-enciphers what it appends with the file's own key.
        $editor = PdfEditor::fromBytes(self::encrypted());
        (new FormFiller($editor))->set('greeting', 'Edited afterwards');

        $reopened = new FormFiller(PdfEditor::fromBytes($editor->save()));

        self::assertSame('Edited afterwards', $reopened->values()['greeting']);
    }

    private static function document(): Document
    {
        $document = new Document();
        $page = $document->newPage();

        (new PageBuilder($document, $page))
            ->drawText(StandardFont::Helvetica, 12.0, 60, 700, self::PAGE_TEXT)
            ->addTextField('greeting', x: 200, y: 500, width: 200, height: 20, value: 'Zoë');

        return $document;
    }

    private static function encrypted(string $userPassword = ''): string
    {
        $document = self::document();
        $document->encrypt(ownerPassword: 'owner-pw', userPassword: $userPassword);

        return $document->save();
    }

    private static function firstPageContent(ObjectStore $store): string
    {
        $tree = $store->resolveDictionary($store->catalog()->get('Pages'));
        $kids = $tree?->get('Kids');
        self::assertInstanceOf(PdfArray::class, $kids);

        $page = $store->resolveDictionary($kids->items()[0]);
        $contents = $page?->get('Contents');
        self::assertInstanceOf(PdfArray::class, $contents);

        $stream = $store->resolve($contents->items()[0]);
        self::assertInstanceOf(Stream::class, $stream);

        return $store->decodedStream($stream);
    }
}
