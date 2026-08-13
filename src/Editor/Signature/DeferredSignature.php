<?php

declare(strict_types=1);

namespace MightyPDF\Editor\Signature;

use MightyPDF\Assembler\Dictionary;
use MightyPDF\Assembler\Types\PdfArray;
use MightyPDF\Assembler\Types\PdfHexString;
use MightyPDF\Assembler\Types\PdfInteger;
use MightyPDF\Assembler\Types\PdfName;
use MightyPDF\Assembler\Types\PdfReference;
use MightyPDF\Assembler\Types\PdfString;
use MightyPDF\Editor\Form\FormException;
use MightyPDF\Editor\Form\FormFiller;
use MightyPDF\Editor\PageTree;
use MightyPDF\Editor\PdfEditor;

/**
 * Prepares a document to be signed by somebody else.
 *
 * This library does not sign, and this does not change that. Signing means
 * holding a private key, building a CMS structure, and deciding whose
 * certificates to trust -- three things that belong to a key store, an HSM
 * or a signing service, not to a PDF writer. What *is* a PDF writer's job
 * is the part around them, and it is fiddly enough that getting it wrong
 * is the usual reason a signed PDF reports itself as altered:
 *
 * - reserving a /Contents placeholder of exactly the right size,
 * - computing a /ByteRange that covers the entire file *except* that
 *   placeholder, to the byte,
 * - handing over precisely those bytes to be signed,
 * - and splicing the result back in without moving anything.
 *
 * So the split is: this prepares and completes, an external signer signs.
 *
 * ```php
 * $prepared = DeferredSignature::prepare(PdfEditor::open('contract.pdf'));
 *
 * // Whatever holds the key -- openssl, an HSM, a signing service --
 * // produces a detached CMS (PKCS#7) over these exact bytes.
 * $cms = $signer->sign($prepared->signedBytes());
 *
 * file_put_contents('signed.pdf', $prepared->complete($cms));
 * ```
 *
 * The signature is added as an **incremental update**, so the original
 * bytes are untouched and an existing signature over them stays valid --
 * which is the only way a second signature can be added at all.
 *
 * **What is not checked.** Nothing here validates the CMS, the
 * certificate chain, or that the blob signs the bytes it was given. A
 * caller that splices in the wrong thing gets a document that says it is
 * signed and fails validation, and this cannot tell the difference. That
 * is the boundary of the split above, and it is why complete() takes the
 * blob rather than a key.
 */
final class DeferredSignature
{
    /**
     * How much room /Contents gets, in bytes, unless told otherwise.
     *
     * A detached CMS with one certificate is around 1.5 KB; a full chain
     * with a timestamp and revocation information runs to several. The
     * placeholder cannot be resized later without moving every byte after
     * it -- which is what /ByteRange was measured against -- so it is
     * sized generously and the slack is zero-padded, which is what every
     * other producer does.
     */
    public const int DEFAULT_CAPACITY = 16_384;

    /**
     * The placeholder each /ByteRange offset is written as before the
     * real one is known.
     *
     * 2147483647 for one reason only: it is ten digits wide, and the
     * whole scheme depends on the entry being **exactly as wide before
     * the offsets are known as after**. An offset written short and
     * widened later would shift the very bytes it is describing. Ten
     * digits also happens to cover a file of up to 10 GB, and being the
     * largest signed 32-bit integer it is a value every reader parses --
     * though no saved file ever contains it, since locate() overwrites
     * the placeholder before anything sees it.
     */
    private const int BYTE_RANGE_PLACEHOLDER = 2_147_483_647;

    private function __construct(
        private readonly string $bytes,
        private readonly int $contentsStart,
        private readonly int $contentsEnd,
        private readonly int $capacity,
    ) {
    }

    /**
     * @param string|null $fieldName an existing empty signature field to
     *        sign, or null to add an invisible one. Naming a field that is
     *        already signed, or is not a signature field, is refused.
     * @param int $capacity bytes reserved for the CMS blob
     */
    public static function prepare(
        PdfEditor $editor,
        ?string $fieldName = null,
        ?string $signerName = null,
        ?string $reason = null,
        ?string $location = null,
        ?string $contactInfo = null,
        ?\DateTimeInterface $signedAt = null,
        int $capacity = self::DEFAULT_CAPACITY,
    ): self {
        if ($capacity < 512) {
            throw new \InvalidArgumentException(
                "A CMS signature does not fit in $capacity bytes; the smallest realistic one is "
                . 'around 1.5 KB, and the placeholder cannot be grown afterwards.',
            );
        }

        if ($editor->store()->isEncrypted()) {
            throw new FormException(
                'This document is encrypted. A signature dictionary\'s /Contents is exempt from '
                . 'encryption while the rest of the update is not, and this library does not implement '
                . 'that exemption -- so it would produce a signature no reader can check. Sign the '
                . 'document before encrypting it.',
            );
        }

        $signature = self::buildSignatureDictionary(
            $editor,
            $capacity,
            $signerName,
            $reason,
            $location,
            $contactInfo,
            $signedAt,
        );

        self::attach($editor, $signature, $fieldName);

        // The length before saving, which is where the update carrying the
        // signature starts -- see locate().
        return self::locate($editor->save(), $capacity, $editor->originalLength());
    }

    /**
     * The document as it stands: complete, with an empty placeholder where
     * the signature will go.
     *
     * Rarely what a caller wants on its own -- it is a document claiming a
     * signature it does not have -- but it is what signedBytes() is a view
     * of, and having it makes the two easy to reason about.
     */
    public function bytes(): string
    {
        return $this->bytes;
    }

    /**
     * Exactly the bytes the signature covers: everything except the
     * placeholder, concatenated.
     *
     * This is what gets signed, and the concatenation is not a
     * simplification -- §12.8.1 defines the signed content as the two
     * /ByteRange spans run together, with the placeholder simply absent.
     */
    public function signedBytes(): string
    {
        return substr($this->bytes, 0, $this->contentsStart)
            . substr($this->bytes, $this->contentsEnd);
    }

    /**
     * The digest of those bytes, for a signer that takes a hash rather
     * than a stream.
     *
     * @param string $algorithm any hash() algorithm; SHA-256 is what
     *        current signature profiles expect
     */
    public function digest(string $algorithm = 'sha256'): string
    {
        return hash($algorithm, $this->signedBytes(), binary: true);
    }

    /** How many bytes the CMS blob may occupy. */
    public function capacity(): int
    {
        return $this->capacity;
    }

    /**
     * Splices the signature in and returns the finished document.
     *
     * @param string $cms the detached CMS (PKCS#7) blob, as raw bytes
     */
    public function complete(string $cms): string
    {
        if ($cms === '') {
            throw new \InvalidArgumentException('The signature is empty; there is nothing to splice in.');
        }

        if (strlen($cms) > $this->capacity) {
            throw new \InvalidArgumentException(sprintf(
                'The signature is %d bytes and the placeholder reserved %d. It cannot be enlarged now '
                . 'without moving the bytes the /ByteRange was measured against -- prepare again with '
                . 'capacity: %d.',
                strlen($cms),
                $this->capacity,
                (int) (ceil(strlen($cms) / 1024) * 1024),
            ));
        }

        // Zero-padded to the full width. The trailing zeros are inside the
        // hex string but past the end of the DER structure, and every
        // reader stops at the end of the DER rather than at the end of the
        // string -- which is the whole reason a fixed-size placeholder
        // works at all.
        $hex = str_pad(bin2hex($cms), $this->capacity * 2, '0');

        return substr($this->bytes, 0, $this->contentsStart + 1)
            . $hex
            . substr($this->bytes, $this->contentsEnd - 1);
    }

    private static function buildSignatureDictionary(
        PdfEditor $editor,
        int $capacity,
        ?string $signerName,
        ?string $reason,
        ?string $location,
        ?string $contactInfo,
        ?\DateTimeInterface $signedAt,
    ): Dictionary {
        $signature = new Dictionary($editor->allocate());

        $signature->set('Type', new PdfName('Sig'));
        $signature->set('Filter', new PdfName('Adobe.PPKLite'));
        // Detached: the signed content is the byte ranges rather than
        // anything carried inside the CMS. The only profile worth
        // offering, and the one every current reader validates.
        $signature->set('SubFilter', new PdfName('adbe.pkcs7.detached'));

        $signature->set('ByteRange', new PdfArray(
            new PdfInteger(0),
            new PdfInteger(self::BYTE_RANGE_PLACEHOLDER),
            new PdfInteger(self::BYTE_RANGE_PLACEHOLDER),
            new PdfInteger(self::BYTE_RANGE_PLACEHOLDER),
        ));
        $signature->set('Contents', new PdfHexString(str_repeat("\0", $capacity)));

        foreach (['Name' => $signerName, 'Reason' => $reason, 'Location' => $location, 'ContactInfo' => $contactInfo] as $key => $value) {
            if ($value !== null && $value !== '') {
                $signature->set($key, PdfString::text($value));
            }
        }

        if ($signedAt !== null) {
            // The claimed signing time. It is a claim: only a timestamp
            // token from a trusted authority, which lives inside the CMS,
            // makes it evidence.
            $offset = $signedAt->format('P');
            $signature->set('M', PdfString::latin1(
                'D:' . $signedAt->format('YmdHis')
                . ($offset === '+00:00' ? 'Z' : str_replace(':', "'", $offset) . "'"),
            ));
        }

        $editor->register($signature);

        return $signature;
    }

    /**
     * Points a signature field at the new signature dictionary, creating
     * the field if there is not one to use.
     */
    private static function attach(PdfEditor $editor, Dictionary $signature, ?string $fieldName): void
    {
        $field = $fieldName === null
            ? self::newInvisibleField($editor)
            : self::existingField($editor, $fieldName);

        $field->set('V', new PdfReference($signature->objectId()));
        $editor->register($field);

        self::requireSignatureFlags($editor);
    }

    private static function existingField(PdfEditor $editor, string $name): Dictionary
    {
        $filler = new FormFiller($editor);
        $field = $filler->field($name);

        if ($field === null) {
            throw new FormException(sprintf(
                'This PDF has no form field named "%s"%s.',
                $name,
                $filler->names() === []
                    ? ' -- it has no fillable fields at all'
                    : '. It has: "' . implode('", "', array_slice($filler->names(), 0, 20)) . '"',
            ));
        }

        if ($field->type !== \MightyPDF\Editor\Form\FieldType::Signature) {
            throw new FormException("\"$name\" is not a signature field, so a signature cannot be put in it.");
        }

        if ($editor->resolve($field->dictionary->get('V')) !== null) {
            throw new FormException(
                "\"$name\" already holds a signature. Overwriting it would silently discard one, so add "
                . 'a second signature in a field of its own instead -- which is what an incremental '
                . 'update is for.',
            );
        }

        return $field->dictionary;
    }

    /**
     * A signature field nobody sees.
     *
     * A signature does not have to be visible, and an invisible one is the
     * right default: a caller who wanted a drawn appearance would have
     * made the field themselves and passed its name. /Rect is the empty
     * rectangle §12.7.4.5 says makes a field invisible.
     */
    private static function newInvisibleField(PdfEditor $editor): Dictionary
    {
        $page = (new PageTree($editor))->page(0)
            ?? throw new FormException('This document has no pages, so there is nowhere to put a signature field.');

        $field = new Dictionary($editor->allocate());

        $field->set('Type', new PdfName('Annot'));
        $field->set('Subtype', new PdfName('Widget'));
        $field->set('FT', new PdfName('Sig'));
        // Named after its own object number, which is unique in the
        // document by definition and so cannot collide with a field the
        // document already has.
        $field->set('T', PdfString::text('Signature' . $field->objectId()));
        $field->set('Rect', new PdfArray(new PdfInteger(0), new PdfInteger(0), new PdfInteger(0), new PdfInteger(0)));
        // Hidden and NoView are wrong here -- the field is present and
        // simply has no area. Bit 3, Print, is what keeps it in the file
        // when the page is printed.
        $field->set('F', new PdfInteger(4));
        $field->set('P', new PdfReference($page->objectId()));

        $editor->register($field);

        self::addToPage($editor, $page, $field->objectId());
        self::addToForm($editor, $field->objectId());

        return $field;
    }

    private static function addToPage(PdfEditor $editor, Dictionary $page, int $objectId): void
    {
        $existing = $editor->resolve($page->get('Annots'));
        $items = $existing instanceof PdfArray ? $existing->items() : [];

        $page->set('Annots', new PdfArray(...[...$items, new PdfReference($objectId)]));
        $editor->register($page);
    }

    private static function addToForm(PdfEditor $editor, int $objectId): void
    {
        $catalog = $editor->catalog();
        $form = $editor->resolveDictionary($catalog->get('AcroForm'));

        if ($form === null) {
            $form = new Dictionary($editor->allocate());
            $form->set('Fields', new PdfArray(new PdfReference($objectId)));
            $editor->register($form);

            $catalog->set('AcroForm', new PdfReference($form->objectId()));
            $editor->register($catalog);

            return;
        }

        $fields = $editor->resolve($form->get('Fields'));
        $items = $fields instanceof PdfArray ? $fields->items() : [];

        $form->set('Fields', new PdfArray(...[...$items, new PdfReference($objectId)]));
        $editor->register($form->hasObjectId() ? $form : $catalog);
    }

    /**
     * /SigFlags 3: the document contains a signature, and must be saved as
     * an incremental update rather than rewritten.
     *
     * Bit 2 is the one that matters. Without it a reader is entitled to
     * rewrite the file on save, which invalidates every signature in it.
     */
    private static function requireSignatureFlags(PdfEditor $editor): void
    {
        $catalog = $editor->catalog();
        $form = $editor->resolveDictionary($catalog->get('AcroForm'));

        if ($form === null) {
            return;
        }

        $form->set('SigFlags', new PdfInteger(3));
        $editor->register($form->hasObjectId() ? $form : $catalog);
    }

    /**
     * The placeholder /ByteRange exactly as it was written, so that
     * finding it is a search for the bytes that are there rather than for
     * a second spelling of them that has to be kept in step.
     */
    private static function byteRangeTemplate(): string
    {
        return (new PdfArray(
            new PdfInteger(0),
            new PdfInteger(self::BYTE_RANGE_PLACEHOLDER),
            new PdfInteger(self::BYTE_RANGE_PLACEHOLDER),
            new PdfInteger(self::BYTE_RANGE_PLACEHOLDER),
        ))->format();
    }

    /**
     * Finds the placeholder in the saved bytes and writes the real
     * /ByteRange over its template.
     *
     * Done on the finished bytes rather than on the objects because the
     * offsets being recorded are offsets *into those bytes* -- they cannot
     * be known before the file exists, and any change made afterwards
     * moves them. That is also why both placeholders are fixed width.
     *
     * **Searched from $updateAt, not from the start.** The signature is
     * added as an incremental update, so both placeholders are in the
     * bytes appended by this save and nowhere else -- while the bytes
     * *before* them are somebody else's document, which is free to contain
     * a run of zeros in angle brackets and an array of the right four
     * integers as ordinary content. A search from zero finds those first,
     * and then the CMS is spliced into a decoy while the real /Contents
     * stays empty and the real /ByteRange stays a placeholder: a document
     * that says it is signed, is not, and reported no error on the way
     * out. A document one has been *asked to sign* is precisely the one
     * that can arrange this.
     *
     * @param int $updateAt where the incremental update begins
     */
    private static function locate(string $bytes, int $capacity, int $updateAt): self
    {
        $placeholder = '<' . str_repeat('0', $capacity * 2) . '>';
        $contentsStart = strpos($bytes, $placeholder, $updateAt);

        if ($contentsStart === false) {
            throw new \RuntimeException(
                'The signature placeholder is not in the saved document. This should not be reachable; '
                . 'it means the signature dictionary was not written.',
            );
        }

        $contentsEnd = $contentsStart + strlen($placeholder);

        $template = self::byteRangeTemplate();
        $rangeAt = strpos($bytes, $template, $updateAt);

        if ($rangeAt === false) {
            throw new \RuntimeException('The /ByteRange placeholder is not in the saved document.');
        }

        // One update, one signature, so a second copy of either pattern in
        // it is not a thing this wrote. Refused rather than guessed at:
        // picking the wrong one produces the same silent non-signature the
        // search window above exists to prevent.
        if (strpos($bytes, $placeholder, $contentsEnd) !== false
            || strpos($bytes, $template, $rangeAt + strlen($template)) !== false) {
            throw new \RuntimeException(
                'The signature update contains the placeholder twice, so which one to splice into is '
                . 'ambiguous. This should not be reachable; prepare() adds exactly one signature.',
            );
        }

        // Padded with spaces before the closing bracket rather than with
        // leading zeros. Both parse -- a PDF integer may carry leading
        // zeros -- but every other producer writes it this way, and a
        // signature is the last place to be the file that is unusual.
        $range = sprintf(
            '[0 %d %d %d',
            $contentsStart,
            $contentsEnd,
            strlen($bytes) - $contentsEnd,
        );

        $range = str_pad($range, strlen($template) - 1) . ']';

        // Same length by construction, so nothing recorded above moves.
        $bytes = substr_replace($bytes, $range, $rangeAt, strlen($template));

        return new self($bytes, $contentsStart, $contentsEnd, $capacity);
    }
}
