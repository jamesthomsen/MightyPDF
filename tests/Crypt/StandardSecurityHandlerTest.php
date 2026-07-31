<?php

declare(strict_types=1);

namespace MightyPDF\Tests\Crypt;

use MightyPDF\Assembler\Stream;
use MightyPDF\Crypt\DecryptionException;
use MightyPDF\Editor\PdfEditor;
use MightyPDF\Reader\ObjectStore;
use MightyPDF\Tests\Support\EncryptedPdfFixture;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class StandardSecurityHandlerTest extends TestCase
{
    /**
     * The whole point of the feature: a document that every viewer opens
     * without prompting, because its user password is empty, and that a
     * reader without decryption sees as binary noise.
     *
     */
    #[DataProvider('schemes')]
    public function testUnlocksWithAnEmptyUserPassword(string $scheme): void
    {
        $store = new ObjectStore(EncryptedPdfFixture::$scheme());

        self::assertTrue($store->isEncrypted());
        self::assertSame('Catalog', $store->catalog()->get('Type')?->value());
    }

    #[DataProvider('schemes')]
    public function testDecryptsStreamData(string $scheme): void
    {
        $store = new ObjectStore(EncryptedPdfFixture::$scheme());
        $page = self::firstPage($store);

        $contents = $store->resolve($page->get('Contents'));
        self::assertInstanceOf(Stream::class, $contents);

        self::assertStringContainsString(
            EncryptedPdfFixture::PAGE_TEXT,
            $store->decodedStream($contents),
        );
    }

    #[DataProvider('schemes')]
    public function testDecryptsStringsWhereverTheyAre(string $scheme): void
    {
        // Encryption reaches the leaves of an object, not the object as a
        // whole, so a string nested in the document information dictionary
        // is as enciphered as a content stream.
        $store = new ObjectStore(EncryptedPdfFixture::$scheme());
        $info = $store->resolveDictionary($store->trailer()->get('Info'));

        self::assertSame(EncryptedPdfFixture::TITLE, $info?->get('Title')?->toUtf8());
    }

    #[DataProvider('schemes')]
    public function testDoesNotDecryptTheEncryptDictionaryItself(string $scheme): void
    {
        // It describes how to decrypt everything else; running it through
        // that process would destroy the one thing that must stay readable.
        $store = new ObjectStore(EncryptedPdfFixture::$scheme());
        $encrypt = $store->resolveDictionary($store->trailer()->get('Encrypt'));

        self::assertSame('Standard', $encrypt?->get('Filter')?->value());
    }

    public function testUnlocksWithTheOwnerPassword(): void
    {
        // The owner password unlocks by recovering the user password from
        // /O, rather than by matching /U directly.
        $store = new ObjectStore(EncryptedPdfFixture::rc4(), EncryptedPdfFixture::OWNER_PASSWORD);

        self::assertSame('Catalog', $store->catalog()->get('Type')?->value());
    }

    public function testUnlocksAnAes256FileWithTheOwnerPassword(): void
    {
        $store = new ObjectStore(EncryptedPdfFixture::aes256(), EncryptedPdfFixture::OWNER_PASSWORD);

        self::assertSame('Catalog', $store->catalog()->get('Type')?->value());
    }

    public function testUnlocksAFileThatReallyDoesNeedAUserPassword(): void
    {
        $store = new ObjectStore(EncryptedPdfFixture::rc4('letmein'), 'letmein');

        self::assertSame('Catalog', $store->catalog()->get('Type')?->value());
    }

    public function testSaysSoWhenAPasswordIsNeededAndNoneWasGiven(): void
    {
        $this->expectException(DecryptionException::class);
        $this->expectExceptionMessage('Supply the password');

        new ObjectStore(EncryptedPdfFixture::rc4('letmein'));
    }

    public function testSaysSoWhenThePasswordIsWrong(): void
    {
        $this->expectException(DecryptionException::class);
        $this->expectExceptionMessage('opens neither the user nor the owner lock');

        new ObjectStore(EncryptedPdfFixture::rc4('letmein'), 'not-it');
    }

    public function testReadsFortyBitRc4(): void
    {
        $store = new ObjectStore(EncryptedPdfFixture::rc4Weak());

        self::assertSame('Catalog', $store->catalog()->get('Type')?->value());
    }

    /**
     * A conforming /V 4 file carries no /Length in its /Encrypt
     * dictionary -- the key length belongs to the crypt filter. Reading
     * the outer one regardless falls back to the 40-bit default and
     * derives a key that unlocks nothing.
     */
    public function testTakesTheKeyLengthFromTheCryptFilterAtVersionFour(): void
    {
        $pdf = EncryptedPdfFixture::aes128();

        self::assertStringNotContainsString('/Length 128', $pdf, 'fixture precondition');
        self::assertSame('Catalog', (new ObjectStore($pdf))->catalog()->get('Type')?->value());
    }

    #[DataProvider('schemes')]
    public function testAnEditedDocumentStaysEncryptedAndStillReads(string $scheme): void
    {
        // Writing plaintext into an encrypted file does not make it
        // "partly decrypted", it makes it broken: the reader deciphers
        // everything /Encrypt says is enciphered, so plaintext comes back
        // out as noise.
        // Built once: AES picks a fresh initialisation vector every time,
        // so calling the fixture twice would not give the same bytes.
        $original = EncryptedPdfFixture::$scheme();
        $editor = PdfEditor::fromBytes($original);

        $catalog = $editor->catalog();
        $catalog->set('OpenAction', new \MightyPDF\Assembler\Types\PdfName('Marker'));
        $editor->register($catalog);


        $stampId = $editor->allocate();
        $editor->register(new Stream($stampId, 'BT /F1 9 Tf (added later) Tj ET', false));

        $saved = $editor->save();

        self::assertStringStartsWith($original, $saved);

        $reopened = new ObjectStore($saved);

        self::assertTrue($reopened->isEncrypted());
        self::assertSame('Marker', $reopened->catalog()->get('OpenAction')?->value());

        $added = $reopened->get($stampId);
        self::assertInstanceOf(Stream::class, $added);
        self::assertSame('BT /F1 9 Tf (added later) Tj ET', $reopened->decodedStream($added));

        // And the untouched original content is still readable.
        $contents = $reopened->resolve(self::firstPage($reopened)->get('Contents'));
        self::assertInstanceOf(Stream::class, $contents);
        self::assertStringContainsString(EncryptedPdfFixture::PAGE_TEXT, $reopened->decodedStream($contents));
    }

    #[DataProvider('schemes')]
    public function testSavingTwiceDoesNotEncipherTheDocumentTwice(string $scheme): void
    {
        // Encryption happens on a copy. Enciphering the live objects
        // instead would add another layer on every call, and only the
        // first save would be readable.
        $editor = PdfEditor::fromBytes(EncryptedPdfFixture::$scheme());
        $editor->register($editor->catalog()->set('Marked', new \MightyPDF\Assembler\Types\PdfBoolean(true)));

        $editor->save();

        // Not a byte comparison: AES picks a fresh initialisation vector
        // each time, so two saves of the same document legitimately differ.
        self::assertTrue((new ObjectStore($editor->save()))->catalog()->get('Marked')?->value());
    }

    /** @return iterable<string, array{string}> */
    public static function schemes(): iterable
    {
        yield 'RC4 128-bit (V2 R3)' => ['rc4'];
        yield 'AES-128 (V4 R4)' => ['aes128'];
        yield 'AES-256 (V5 R6)' => ['aes256'];
    }

    private static function firstPage(ObjectStore $store): \MightyPDF\Assembler\Dictionary
    {
        $tree = $store->resolveDictionary($store->catalog()->get('Pages'));
        self::assertNotNull($tree);

        $kids = $tree->get('Kids');
        self::assertInstanceOf(\MightyPDF\Assembler\Types\PdfArray::class, $kids);

        $page = $store->resolveDictionary($kids->items()[0]);
        self::assertNotNull($page);

        return $page;
    }
}
