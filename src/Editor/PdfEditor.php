<?php

declare(strict_types=1);

namespace MightyPDF\Editor;

use MightyPDF\Assembler\Dictionary;
use MightyPDF\Assembler\PdfObject;
use MightyPDF\Assembler\Trailer;
use MightyPDF\Assembler\Types\PdfReference;
use MightyPDF\Assembler\Types\PdfValue;
use MightyPDF\Assembler\Xref;
use MightyPDF\Assembler\XrefStream;
use MightyPDF\Crypt\CryptTransform;
use MightyPDF\Reader\ObjectStore;
use MightyPDF\Reader\ParseException;

/**
 * Opens an existing PDF, lets objects in it be changed or added, and
 * writes the result as an *incremental update*: the original bytes
 * verbatim, followed by only the objects that changed, a cross-reference
 * section describing just those, and a trailer whose /Prev chains back
 * into the file's existing sections (ISO 32000-2 §7.5.6).
 *
 * Rewriting the whole file instead would mean this library takes
 * responsibility for the correctness of every object in a document it did
 * not write -- tagged structure, optional content, colour profiles,
 * embedded files, digital signatures. It would silently destroy the ones
 * it does not understand. Appending inverts that: anything not explicitly
 * touched is preserved because its bytes were never regenerated, not
 * because someone remembered to handle it. It also leaves any existing
 * signature over the original byte range intact, which a full rewrite
 * cannot do by construction.
 *
 * The editing model is that objects handed out by the reader are live
 * writer-side objects. Change one with Dictionary::set(), hand it to
 * register(), and it goes in the update. There is no separate diffing or
 * apply step to get out of sync.
 */
final class PdfEditor
{
    /** A reference chain longer than this is a cycle, not a document. */
    private const int MAX_REFERENCE_DEPTH = 32;

    private readonly ObjectStore $store;
    private readonly string $originalBytes;

    private int $nextObjectId;

    /**
     * Object id => the object to write into the update section. Holds
     * both existing objects that were modified and brand-new ones; from
     * the writer's point of view those are the same thing, since an
     * incremental update expresses "this object now reads like so"
     * identically either way.
     *
     * @var array<int, PdfObject>
     */
    private array $changed = [];

    private function __construct(string $bytes, string $password)
    {
        $this->originalBytes = $bytes;
        $this->store = new ObjectStore($bytes, $password);
        $this->nextObjectId = $this->store->xref()->nextFreeObjectId();
    }

    /**
     * @param string $password only needed for a document that does not
     *        open with an empty one -- which most encrypted PDFs do, the
     *        usual arrangement being an owner password with no user
     *        password at all.
     */
    public static function open(string $path, string $password = ''): self
    {
        $bytes = @file_get_contents($path);

        if ($bytes === false) {
            throw new ParseException("Failed to read PDF from $path.");
        }

        return new self($bytes, $password);
    }

    public static function fromBytes(string $bytes, string $password = ''): self
    {
        return new self($bytes, $password);
    }

    public function store(): ObjectStore
    {
        return $this->store;
    }

    public function catalog(): Dictionary
    {
        return $this->store->catalog();
    }

    /**
     * Pending objects shadow the file's own.
     *
     * An editor's view of the document has to include what it has been
     * told so far: an object registered a moment ago exists as far as the
     * caller is concerned, but it is not in the file yet and the store
     * knows nothing about it. Without this, following a reference to
     * something just added -- reading back the appearance stream a form
     * fill created, say -- simply returns null.
     */
    public function get(int $objectId): ?PdfValue
    {
        return $this->changed[$objectId] ?? $this->store->get($objectId);
    }

    public function resolve(?PdfValue $value): ?PdfValue
    {
        // Deliberately not delegating to the store: its resolve() would
        // follow references through its own get(), which cannot see
        // anything this editor has added.
        for ($depth = 0; $value instanceof PdfReference; ++$depth) {
            if ($depth >= self::MAX_REFERENCE_DEPTH) {
                return null;
            }

            $value = $this->get($value->objectId());
        }

        return $value;
    }

    public function resolveDictionary(?PdfValue $value): ?Dictionary
    {
        $resolved = $this->resolve($value);

        return $resolved instanceof Dictionary ? $resolved : null;
    }

    /**
     * Reserves an object number no existing object uses, for a new object
     * about to be constructed with it. Same two-step shape as
     * IndirectObjectRegistry::allocate(), and for the same reason: a
     * PdfObject's id is readonly, so it has to be known before the object
     * exists.
     */
    public function allocate(): int
    {
        return $this->nextObjectId++;
    }

    /**
     * Marks an object to be written into the update -- whether it is a new
     * object or one that came from the file and has since been modified.
     */
    public function register(PdfObject $object): void
    {
        $this->changed[$object->objectId()] = $object;
    }

    /** @return array<int, PdfObject> */
    public function changedObjects(): array
    {
        return $this->changed;
    }

    public function save(): string
    {
        // Opening a file and saving it without changing anything must not
        // alter a single byte. There is nothing to say, so an update
        // section saying it would be noise -- and, for a signed document,
        // noise that costs the signature its "no revisions since" status.
        if ($this->changed === []) {
            return $this->originalBytes;
        }

        $out = self::startOnANewLine($this->originalBytes);

        $objects = $this->changed;
        ksort($objects);

        $xref = new Xref();

        foreach ($objects as $objectId => $object) {
            // Offsets are into the whole output, original bytes included.
            // Appending never moves an existing object, so every offset
            // recorded by every earlier section stays true and this one
            // only has to describe what this update added.
            $xref->addEntry($objectId, strlen($out), $object->generation());
            $out .= $this->forWriting($object)->render(true);
        }

        // The update's cross-reference section has to be in the same
        // format as the one it chains onto. See
        // XrefTable::usesCrossReferenceStreams() for what goes wrong
        // otherwise -- it is not a stylistic choice.
        return $this->store->xref()->usesCrossReferenceStreams()
            ? $this->appendCrossReferenceStream($out, $xref)
            : $this->appendCrossReferenceTable($out, $xref);
    }

    private function appendCrossReferenceTable(string $out, Xref $xref): string
    {
        $startXref = strlen($out);

        $trailer = $this->updateTrailer(max($this->nextObjectId, $xref->highestObjectId() + 1));

        return $out
            . $xref->buildUpdateSection()
            . $trailer->build()
            . "startxref\n{$startXref}\n%%EOF\n";
    }

    private function appendCrossReferenceStream(string $out, Xref $xref): string
    {
        // The section is itself an object, so it needs a number, and it
        // has to appear in its own table -- a reader that has just found
        // it via startxref still expects to be told where it is.
        //
        // Read without consuming: save() must be callable twice and give
        // the same answer, so it cannot advance the allocator.
        $xrefStreamId = $this->nextObjectId;
        $startXref = strlen($out);

        $xref->addEntry($xrefStreamId, $startXref);

        $trailer = $this->updateTrailer(max($xrefStreamId + 1, $xref->highestObjectId() + 1));

        return $out
            . XrefStream::build($xrefStreamId, $xref, $trailer)->render(true)
            . "startxref\n{$startXref}\n%%EOF\n";
    }

    /**
     * Re-enciphers an object on its way out, if the document is encrypted.
     *
     * An encrypted document stays encrypted. Writing plaintext strings
     * into one would not produce a "partly decrypted" file, it would
     * produce a broken one: a reader decrypts everything the /Encrypt
     * dictionary says is encrypted, so plaintext appended to it comes back
     * out as noise. The alternative -- stripping the encryption -- would
     * mean rewriting every object in the file, which the whole incremental
     * design exists to avoid, and would silently hand out a document whose
     * protection someone was relying on.
     *
     * The result is a copy: the caller keeps holding the plaintext object
     * it edited, and save() stays repeatable rather than enciphering the
     * live document a little more each time it is called.
     */
    private function forWriting(PdfObject $object): PdfObject
    {
        $security = $this->store->security();

        if ($security === null) {
            return $object;
        }

        if ($object instanceof Dictionary
            && CryptTransform::isNeverEncrypted($object, $security->encryptsMetadata())) {
            return $object;
        }

        $objectId = $object->objectId();
        $generation = $object->generation();

        $encrypted = CryptTransform::apply(
            $object,
            static fn (string $bytes): string => $security->encryptString($bytes, $objectId, $generation),
            static fn (string $bytes): string => $security->encryptStream($bytes, $objectId, $generation),
        );

        return $encrypted instanceof PdfObject ? $encrypted : $object;
    }

    private function updateTrailer(int $size): Trailer
    {
        return Trailer::forUpdate(
            previousTrailer: $this->store->trailer(),
            size: $size,
            previousXrefOffset: $this->store->xref()->startXrefOffset(),
        );
    }

    public function saveToFile(string $path): void
    {
        if (file_put_contents($path, $this->save()) === false) {
            throw new \RuntimeException("Failed to write PDF to $path");
        }
    }

    /**
     * A PDF's last line is "%%EOF", and "%" starts a comment that runs to
     * the end of the line. Appending an object straight onto a file that
     * ends without a line break would produce "%%EOF1 0 obj", commenting
     * the new object out of existence -- the update would parse cleanly
     * and simply not be there.
     */
    private static function startOnANewLine(string $bytes): string
    {
        if (str_ends_with($bytes, "\n") || str_ends_with($bytes, "\r")) {
            return $bytes;
        }

        return $bytes . "\n";
    }
}
