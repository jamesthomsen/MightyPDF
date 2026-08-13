<?php

declare(strict_types=1);

namespace MightyPDF\Tests\Editor\Signature;

use MightyPDF\Assembler\Document;
use MightyPDF\Assembler\Stream;
use MightyPDF\Content\Font\StandardFont;
use MightyPDF\Content\PageBuilder;
use MightyPDF\Crypt\Permissions;
use MightyPDF\Editor\Form\FormException;
use MightyPDF\Editor\PdfEditor;
use MightyPDF\Editor\Signature\DeferredSignature;
use PHPUnit\Framework\TestCase;

final class DeferredSignatureTest extends TestCase
{
    public function testTheByteRangeCoversEverythingExceptThePlaceholder(): void
    {
        $prepared = DeferredSignature::prepare(PdfEditor::fromBytes(self::document()));
        $bytes = $prepared->bytes();

        [$a, $b, $c, $d] = self::byteRangeOf($bytes);

        self::assertSame(0, $a);
        self::assertSame($b + $d, strlen($bytes) - ($c - $b));
        self::assertSame(strlen($bytes), $c + $d);

        // The gap is exactly the hex string, angle brackets included --
        // which is the one thing a validator checks before anything
        // cryptographic happens.
        self::assertSame('<', $bytes[$b]);
        self::assertSame('>', $bytes[$c - 1]);
    }

    public function testSignedBytesAreTheTwoRangesRunTogether(): void
    {
        $prepared = DeferredSignature::prepare(PdfEditor::fromBytes(self::document()));
        $bytes = $prepared->bytes();

        [$a, $b, $c, $d] = self::byteRangeOf($bytes);

        self::assertSame(
            substr($bytes, $a, $b) . substr($bytes, $c, $d),
            $prepared->signedBytes(),
        );
    }

    public function testTheDigestIsOfExactlyThoseBytes(): void
    {
        $prepared = DeferredSignature::prepare(PdfEditor::fromBytes(self::document()));

        self::assertSame(
            hash('sha256', $prepared->signedBytes(), true),
            $prepared->digest(),
        );
        self::assertSame(
            hash('sha512', $prepared->signedBytes(), true),
            $prepared->digest('sha512'),
        );
    }

    public function testCompletingMovesNoBytes(): void
    {
        $prepared = DeferredSignature::prepare(PdfEditor::fromBytes(self::document()));
        $before = $prepared->bytes();
        $after = $prepared->complete(str_repeat("\x30", 1_000));

        // The whole design rests on this: the /ByteRange was measured
        // against these offsets, so a completed signature that moved
        // anything would be a signature over the wrong bytes.
        self::assertSame(strlen($before), strlen($after));
        self::assertSame(self::byteRangeOf($before), self::byteRangeOf($after));
        self::assertSame($prepared->signedBytes(), self::signedPartOf($after));
    }

    public function testTheSignatureGoesInsideThePlaceholder(): void
    {
        $prepared = DeferredSignature::prepare(PdfEditor::fromBytes(self::document()));
        $cms = random_bytes(64);
        $signed = $prepared->complete($cms);

        [, $b, $c] = self::byteRangeOf($signed);
        $hex = trim(substr($signed, $b, $c - $b), '<>');

        self::assertStringStartsWith(bin2hex($cms), $hex);

        // ...and the rest is zero padding, which readers stop short of
        // because the DER structure says where it ends.
        self::assertSame(
            str_repeat('0', strlen($hex) - strlen(bin2hex($cms))),
            substr($hex, strlen(bin2hex($cms))),
        );
    }

    /**
     * A document one has been *asked* to sign is exactly the document
     * that can arrange to contain the two byte patterns the placeholders
     * are found by. Searching the whole file finds the decoy first --
     * the signature is spliced into somebody else's content while the
     * real /Contents stays empty and the real /ByteRange stays a
     * placeholder, and nothing reports an error. So the search starts
     * where the incremental update does.
     */
    public function testAPlaceholderInTheDocumentBeingSignedIsNotMistakenForTheRealOne(): void
    {
        $capacity = DeferredSignature::DEFAULT_CAPACITY;

        $document = new Document();
        $document->newPage();

        // Carried as the contents of an ordinary uncompressed stream, so
        // the bytes appear verbatim and every xref offset stays honest.
        $document->register(new Stream(
            $document->allocate(),
            '<' . str_repeat('0', $capacity * 2) . ">\n[0 2147483647 2147483647 2147483647]\n",
            compress: false,
        ));

        $hostile = $document->save();
        $cms = random_bytes(1_200);
        $signed = DeferredSignature::prepare(PdfEditor::fromBytes($hostile))->complete($cms);

        // The signature landed in the update, past everything the hostile
        // document had a say in.
        self::assertGreaterThanOrEqual(strlen($hostile), strpos($signed, bin2hex($cms)));

        // And the dictionary the reader will check describes it: a real
        // range, and the blob actually inside the placeholder it names.
        [$a, $b, $c, $d] = self::byteRangeOf($signed);

        self::assertNotSame(2_147_483_647, $b);
        self::assertSame(strlen($signed), $c + $d);
        self::assertStringStartsWith(bin2hex($cms), trim(substr($signed, $b, $c - $b), '<>'));
    }

    public function testTheOriginalBytesAreUntouched(): void
    {
        $original = self::document();
        $prepared = DeferredSignature::prepare(PdfEditor::fromBytes($original));

        // An incremental update, so a signature already over the original
        // range stays valid -- which is the only way a second signature
        // can be added at all.
        self::assertStringStartsWith($original, $prepared->bytes());
    }

    public function testWritesADetachedPkcs7SignatureDictionary(): void
    {
        $bytes = DeferredSignature::prepare(PdfEditor::fromBytes(self::document()))->bytes();

        self::assertStringContainsString('/Type /Sig', $bytes);
        self::assertStringContainsString('/Filter /Adobe.PPKLite', $bytes);
        self::assertStringContainsString('/SubFilter /adbe.pkcs7.detached', $bytes);

        // Bit 2 of /SigFlags: a reader must not rewrite this file on save,
        // because doing so invalidates every signature in it.
        self::assertStringContainsString('/SigFlags 3', $bytes);
    }

    public function testCarriesTheClaimedDetails(): void
    {
        $bytes = DeferredSignature::prepare(
            PdfEditor::fromBytes(self::document()),
            signerName: 'James Thomsen',
            reason: 'I approve this',
            location: 'London',
            contactInfo: 'james@example.com',
            signedAt: new \DateTimeImmutable('2026-08-12 09:00:00', new \DateTimeZone('UTC')),
        )->bytes();

        self::assertStringContainsString('(James Thomsen)', $bytes);
        self::assertStringContainsString('(I approve this)', $bytes);
        self::assertStringContainsString('(London)', $bytes);
        self::assertStringContainsString('(D:20260812090000Z)', $bytes);
    }

    public function testUnsetDetailsAreLeftOut(): void
    {
        $bytes = DeferredSignature::prepare(PdfEditor::fromBytes(self::document()))->bytes();

        self::assertStringNotContainsString('/Reason', $bytes);
        self::assertStringNotContainsString('/Location', $bytes);
        self::assertStringNotContainsString('/M ', $bytes);
    }

    public function testUsesAnExistingSignatureField(): void
    {
        $document = new Document();
        $page = $document->newPage();

        (new PageBuilder($document, $page))
            ->addSignatureField('approval', x: 100, y: 100, width: 200, height: 50);

        $editor = PdfEditor::fromBytes($document->save());
        $prepared = DeferredSignature::prepare($editor, fieldName: 'approval');

        self::assertStringContainsString('/SubFilter /adbe.pkcs7.detached', $prepared->bytes());

        // The existing field is used rather than a second one added.
        $signed = PdfEditor::fromBytes($prepared->complete(random_bytes(32)));
        $filler = new \MightyPDF\Editor\Form\FormFiller($signed);

        self::assertSame(['approval'], $filler->names());
        self::assertNotNull($signed->resolve($filler->field('approval')?->dictionary->get('V')));
    }

    public function testAddsAnInvisibleFieldWhenNoneIsNamed(): void
    {
        $editor = PdfEditor::fromBytes(self::document());
        $prepared = DeferredSignature::prepare($editor);

        $signed = PdfEditor::fromBytes($prepared->complete(random_bytes(32)));
        $names = (new \MightyPDF\Editor\Form\FormFiller($signed))->names();

        self::assertCount(1, $names);
        self::assertStringStartsWith('Signature', $names[0]);
        self::assertStringContainsString('/Rect [0 0 0 0]', $prepared->bytes());
    }

    public function testRefusesAFieldThatIsNotThere(): void
    {
        $this->expectException(FormException::class);
        $this->expectExceptionMessage('no form field named "nonexistent"');

        DeferredSignature::prepare(PdfEditor::fromBytes(self::document()), fieldName: 'nonexistent');
    }

    public function testRefusesAFieldThatIsNotASignatureField(): void
    {
        $document = new Document();
        $page = $document->newPage();

        (new PageBuilder($document, $page))->addTextField('name', x: 10, y: 10, width: 100, height: 20);

        $this->expectException(FormException::class);
        $this->expectExceptionMessage('is not a signature field');

        DeferredSignature::prepare(PdfEditor::fromBytes($document->save()), fieldName: 'name');
    }

    public function testRefusesToOverwriteAnExistingSignature(): void
    {
        $document = new Document();
        $page = $document->newPage();

        (new PageBuilder($document, $page))
            ->addSignatureField('approval', x: 100, y: 100, width: 200, height: 50);

        $once = DeferredSignature::prepare(PdfEditor::fromBytes($document->save()), fieldName: 'approval')
            ->complete(random_bytes(32));

        $this->expectException(FormException::class);
        $this->expectExceptionMessage('already holds a signature');

        DeferredSignature::prepare(PdfEditor::fromBytes($once), fieldName: 'approval');
    }

    public function testRefusesAnEncryptedDocument(): void
    {
        $document = new Document();
        $document->newPage();
        $document->encrypt('owner', permissions: Permissions::PRINT);

        $this->expectException(FormException::class);
        $this->expectExceptionMessage('Sign the document before encrypting it');

        DeferredSignature::prepare(PdfEditor::fromBytes($document->save()));
    }

    public function testRefusesASignatureTooBigForThePlaceholder(): void
    {
        $prepared = DeferredSignature::prepare(PdfEditor::fromBytes(self::document()), capacity: 1_024);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('placeholder reserved 1024');

        $prepared->complete(random_bytes(2_000));
    }

    public function testRefusesAPlaceholderTooSmallToBeUseful(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('does not fit in 100 bytes');

        DeferredSignature::prepare(PdfEditor::fromBytes(self::document()), capacity: 100);
    }

    public function testRefusesAnEmptySignature(): void
    {
        $prepared = DeferredSignature::prepare(PdfEditor::fromBytes(self::document()));

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('nothing to splice in');

        $prepared->complete('');
    }

    /**
     * The property everything else rests on, with a real signature in it:
     * a finished document, read back knowing nothing but its own
     * /ByteRange, reproduces exactly the bytes that were handed to the
     * signer. If this holds, a validator computing the same hash gets the
     * same answer -- which is what "the signature is valid" means.
     */
    public function testAFinishedDocumentDescribesItsOwnSignedContent(): void
    {
        if (!function_exists('openssl_cms_sign')) {
            self::markTestSkipped('openssl_cms_sign() is needed to produce a real CMS blob.');
        }

        $prepared = DeferredSignature::prepare(PdfEditor::fromBytes(self::document()));

        $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        self::assertNotFalse($key);

        $request = openssl_csr_new(['commonName' => 'Test Signer'], $key, ['digest_alg' => 'sha256']);
        self::assertNotFalse($request);

        $certificate = openssl_csr_sign($request, null, $key, 365, ['digest_alg' => 'sha256']);
        self::assertNotFalse($certificate);

        $content = tempnam(sys_get_temp_dir(), 'mpdf');
        $signature = tempnam(sys_get_temp_dir(), 'mpdf');

        try {
            file_put_contents($content, $prepared->signedBytes());

            self::assertTrue(openssl_cms_sign(
                $content,
                $signature,
                $certificate,
                $key,
                [],
                OPENSSL_CMS_DETACHED | OPENSSL_CMS_BINARY,
                OPENSSL_ENCODING_DER,
            ));

            $cms = file_get_contents($signature);
            self::assertIsString($cms);

            $finished = $prepared->complete($cms);

            // Re-derived from the finished file, not remembered.
            self::assertSame($prepared->signedBytes(), self::signedPartOf($finished));

            // And the blob really is in there, where /ByteRange says the
            // gap is.
            [, $b, $c] = self::byteRangeOf($finished);
            self::assertStringStartsWith(bin2hex($cms), trim(substr($finished, $b, $c - $b), '<>'));
        } finally {
            @unlink($content);
            @unlink($signature);
        }
    }

    /** @return array{int, int, int, int} */
    private static function byteRangeOf(string $pdf): array
    {
        self::assertSame(
            1,
            preg_match('/\/ByteRange\s*\[\s*(\d+)\s+(\d+)\s+(\d+)\s+(\d+)\s*\]/', $pdf, $m),
            'The document should carry exactly one parseable /ByteRange.',
        );

        return [(int) $m[1], (int) $m[2], (int) $m[3], (int) $m[4]];
    }

    private static function signedPartOf(string $pdf): string
    {
        [$a, $b, $c, $d] = self::byteRangeOf($pdf);

        return substr($pdf, $a, $b) . substr($pdf, $c, $d);
    }

    private static function document(): string
    {
        $document = new Document();
        $page = $document->newPage();

        (new PageBuilder($document, $page))
            ->drawText(StandardFont::Helvetica, 14.0, 60, 700, 'Contract of sale');

        return $document->save();
    }
}
