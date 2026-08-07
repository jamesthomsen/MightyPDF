<?php

declare(strict_types=1);

namespace MightyPDF\Assembler\Attachment;

use MightyPDF\Assembler\Dictionary;
use MightyPDF\Assembler\Stream;
use MightyPDF\Assembler\Types\PdfHexString;
use MightyPDF\Assembler\Types\PdfInteger;
use MightyPDF\Assembler\Types\PdfName;
use MightyPDF\Assembler\Types\PdfReference;
use MightyPDF\Assembler\Types\PdfString;

/**
 * A file carried inside the document (ISO 32000-2 §7.11.4): its name, its
 * bytes, and what it has to do with the page it came with.
 *
 * Two objects make one attachment, and both are here because neither is
 * useful alone. The stream holds the bytes; this dictionary holds the
 * name a reader shows, the description under it, and a reference to that
 * stream. A reader's attachments panel lists these.
 *
 * **The name is written twice**, as /F and /UF. /F is the older entry and
 * is bytes rather than text -- readers disagree about how to interpret
 * anything outside ASCII in it -- so /UF carries the real name as a
 * proper text string and /F carries an ASCII-safe rendering for anything
 * that only reads the old one. A name that is already ASCII is the same
 * in both.
 *
 * **The relationship entry is what makes an attachment machine-readable.**
 * An e-invoice is a PDF a person reads with an XML file inside it that a
 * system reads, and the two are the same invoice -- which is a claim the
 * file has to make, not one a consumer can infer from a filename. That is
 * what AttachmentRelationship::Data says, and what Factur-X, ZUGFeRD and
 * the rest of the EU e-invoicing formats are built on.
 */
final class FileSpecification extends Dictionary
{
    public function __construct(
        int $objectId,
        private readonly string $name,
        Stream $embeddedFile,
        ?string $description = null,
        AttachmentRelationship $relationship = AttachmentRelationship::Unspecified,
    ) {
        parent::__construct($objectId);

        $this->set('Type', new PdfName('Filespec'));
        $this->set('F', PdfString::latin1(self::asciiName($name)));
        $this->set('UF', PdfString::text($name));

        $files = new Dictionary();
        $files->set('F', new PdfReference($embeddedFile->objectId()));
        $this->set('EF', $files);

        if ($description !== null) {
            $this->set('Desc', PdfString::text($description));
        }

        if ($relationship !== AttachmentRelationship::Unspecified) {
            $this->set('AFRelationship', new PdfName($relationship->value));
        }
    }

    /** The name as given, which is the key it is filed under. */
    public function name(): string
    {
        return $this->name;
    }

    /**
     * The stream holding the bytes.
     *
     * $mediaType goes into /Subtype as a PDF name, where the slash has to
     * be escaped -- "text/xml" is written /text#2Fxml. PdfName does that
     * itself, so the media type is passed through as written.
     *
     * /Params carries the size and a checksum of the *uncompressed*
     * bytes. Both are optional and both are worth having: a reader shows
     * the size before extracting, and the checksum is how it notices a
     * file that a later incremental update replaced. The spec specifies
     * MD5 for that checksum -- it is a file-identity check with no
     * security claim attached, and using anything else here would produce
     * a value no reader can verify.
     */
    public static function embeddedFile(int $objectId, string $bytes, ?string $mediaType = null): Stream
    {
        $stream = new Stream($objectId, $bytes);
        $stream->set('Type', new PdfName('EmbeddedFile'));

        if ($mediaType !== null) {
            $stream->set('Subtype', new PdfName($mediaType));
        }

        $parameters = new Dictionary();
        $parameters->set('Size', new PdfInteger(strlen($bytes)));
        $parameters->set('CheckSum', new PdfHexString(md5($bytes, true)));
        $stream->set('Params', $parameters);

        return $stream;
    }

    /**
     * A filename with everything outside printable ASCII replaced, for
     * the /F entry that older readers use.
     *
     * Path separators go too. A file specification is a path in PDF's own
     * grammar, where "/" separates components, so a name containing one
     * would be read as a directory -- and an attachment named
     * "../../etc/passwd" is a name a reader has no business acting on.
     */
    private static function asciiName(string $name): string
    {
        // Per character, not per byte: without the /u an em dash becomes
        // three underscores rather than one, and a Cyrillic name becomes
        // twice as many characters as it had.
        $ascii = preg_replace('/[^\x20-\x7E]/u', '_', $name);

        // Which fails outright on a name that is not valid UTF-8. Such a
        // name has no characters to count, so the byte-wise pass -- which
        // cannot fail -- is the right answer rather than a fallback.
        $ascii ??= preg_replace('/[^\x20-\x7E]/', '_', $name);

        return str_replace(['/', '\\'], '_', $ascii ?? '_');
    }
}
