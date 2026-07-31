<?php

declare(strict_types=1);

namespace MightyPDF\Reader;

use MightyPDF\Assembler\Dictionary;
use MightyPDF\Assembler\Stream;
use MightyPDF\Assembler\Types\PdfArray;
use MightyPDF\Assembler\Types\PdfInteger;
use MightyPDF\Assembler\Types\PdfName;
use MightyPDF\Assembler\Types\PdfReference;
use MightyPDF\Assembler\Types\PdfValue;
use MightyPDF\Crypt\CryptTransform;
use MightyPDF\Crypt\DecryptionException;
use MightyPDF\Crypt\StandardSecurityHandler;
use MightyPDF\Reader\Filter\StreamDecoder;

/**
 * Random access to the objects of an existing PDF, by object number.
 *
 * Lazy on purpose. The reader exists so that MightyPDF can *edit* files it
 * did not write, and the way to do that safely is an incremental update:
 * append the handful of objects that changed, leave every original byte
 * alone. That only works if the reader is a lookup service rather than a
 * loader -- nothing is parsed unless somebody asks for it, so the vast
 * majority of a file (the parts nobody is editing, including constructs
 * this library does not understand) is never interpreted at all and
 * therefore cannot be damaged.
 *
 * Every value handed out is a live writer-side object. Mutating one and
 * writing it back is the entire editing model; there is no separate
 * "apply changes" step to forget.
 */
final class ObjectStore
{
    /**
     * A reference chain longer than this is a cycle, not a document.
     * get()'s in-progress guard catches a value that refers to itself,
     * but not A -> B -> A, since by then A has finished loading.
     */
    private const int MAX_REFERENCE_DEPTH = 32;

    private readonly Lexer $lexer;
    private readonly XrefTable $xref;
    private readonly ObjectScanner $scanner;
    private readonly ObjectParser $parser;
    private readonly StreamDecoder $decoder;

    /** @var array<int, PdfValue|null> parsed objects, including proven-absent ones */
    private array $cache = [];

    /** @var array<int, true> */
    private array $loading = [];

    /**
     * Object stream id => its members, already decoded and located. An
     * object stream holds many objects in one compressed blob, so reading
     * a second object out of one that has already been opened must not
     * decompress it again -- which, for a file where every page and every
     * font is compressed together, is the difference between one inflate
     * and hundreds.
     *
     * @var array<int, array<int, PdfValue>>
     */
    private array $objectStreams = [];

    private ?StandardSecurityHandler $security = null;

    /**
     * The /Encrypt dictionary's own object number. It describes how to
     * decrypt everything else and is therefore itself left in the clear;
     * decrypting it would turn the one thing that must stay readable into
     * noise.
     */
    private ?int $encryptObjectId = null;

    public function __construct(string $bytes, string $password = '')
    {
        $this->lexer = new Lexer($bytes);
        $this->xref = XrefTable::read($this->lexer);
        $this->scanner = new ObjectScanner($bytes);
        $this->decoder = new StreamDecoder(fn (?PdfValue $value): ?PdfValue => $this->resolve($value));
        $this->parser = new ObjectParser(
            $this->lexer,
            fn (PdfReference $reference): ?int => $this->resolveLength($reference),
        );

        $this->unlock($password);
    }

    public static function fromFile(string $path, string $password = ''): self
    {
        $bytes = @file_get_contents($path);

        if ($bytes === false) {
            throw new ParseException("Failed to read PDF from $path.");
        }

        return new self($bytes, $password);
    }

    /**
     * Sets up decryption, if the document has any.
     *
     * Order matters here. The /Encrypt dictionary is itself an object in
     * the file, and it is the one object that is never enciphered -- so it
     * has to be read *before* the handler exists, which is exactly what
     * happens: with $security still null, loading it decrypts nothing. Its
     * object number is then remembered so that a later, ordinary lookup of
     * it does not try to decrypt it a second time.
     */
    private function unlock(string $password): void
    {
        $encrypt = $this->xref->trailer()->get('Encrypt');

        if ($encrypt === null) {
            return;
        }

        if ($encrypt instanceof PdfReference) {
            $this->encryptObjectId = $encrypt->objectId();
        }

        $dictionary = $this->resolveDictionary($encrypt);

        if ($dictionary === null) {
            throw new DecryptionException('This PDF says it is encrypted but its /Encrypt dictionary is missing.');
        }

        $id = $this->xref->trailer()->get('ID');

        $this->security = StandardSecurityHandler::open(
            $dictionary,
            $id instanceof PdfArray ? $id : null,
            $password,
        );

        // Anything read while working that out was read undecrypted.
        $this->cache = [];
        $this->objectStreams = [];
    }

    public function isEncrypted(): bool
    {
        return $this->security !== null;
    }

    public function security(): ?StandardSecurityHandler
    {
        return $this->security;
    }

    public function trailer(): Dictionary
    {
        return $this->xref->trailer();
    }

    public function xref(): XrefTable
    {
        return $this->xref;
    }

    /** The document catalog, i.e. the trailer's /Root. */
    public function catalog(): Dictionary
    {
        $root = $this->resolve($this->trailer()->get('Root'));

        if (!$root instanceof Dictionary) {
            throw new ParseException('The trailer has no usable /Root catalog.');
        }

        return $root;
    }

    /** The object with this number, or null if the file does not have one. */
    public function get(int $objectId): ?PdfValue
    {
        if (array_key_exists($objectId, $this->cache)) {
            return $this->cache[$objectId];
        }

        if (isset($this->loading[$objectId])) {
            // Reached while already parsing this object -- in practice a
            // stream whose /Length points back at itself. Malformed, but
            // not fatal: reporting "no value" sends the stream reader down
            // its endstream-scanning path, which is the right answer.
            return null;
        }

        $this->loading[$objectId] = true;

        try {
            return $this->cache[$objectId] = $this->load($objectId);
        } finally {
            unset($this->loading[$objectId]);
        }
    }

    /**
     * Follows indirect references until a direct value is reached, so
     * callers can treat "12 0 R" and the dictionary it names alike --
     * which matters because whether a given entry is direct or indirect is
     * a choice of whoever wrote the file, not something the spec fixes.
     */
    public function resolve(?PdfValue $value): ?PdfValue
    {
        for ($depth = 0; $value instanceof PdfReference; ++$depth) {
            if ($depth >= self::MAX_REFERENCE_DEPTH) {
                return null;
            }

            $value = $this->get($value->objectId());
        }

        return $value;
    }

    /** resolve(), narrowed to a Dictionary (Stream included, being one). */
    public function resolveDictionary(?PdfValue $value): ?Dictionary
    {
        $resolved = $this->resolve($value);

        return $resolved instanceof Dictionary ? $resolved : null;
    }

    /**
     * A stream's actual content, with its /Filter chain undone.
     *
     * Throws for a stream this reader cannot decode -- an image, most
     * likely. Returning the encoded bytes instead would hand back JPEG
     * data labelled as decoded content and let the caller's
     * misunderstanding travel; use rawBytes() when the encoded form is
     * what is genuinely wanted.
     */
    public function decodedStream(Stream $stream): string
    {
        return $this->decoder->decode($stream);
    }

    public function canDecode(Stream $stream): bool
    {
        return $this->decoder->canDecode($stream);
    }

    private function load(int $objectId): ?PdfValue
    {
        $entry = $this->xref->entry($objectId);

        if ($entry !== null) {
            $value = $entry->isCompressed()
                ? $this->loadFromObjectStream($entry)
                : $this->parseAt($entry->offset, $objectId);

            if ($value !== null) {
                return $value;
            }
        }

        // The xref had no entry, or its offset did not lead to this
        // object. Both are ordinary in the wild (see ObjectScanner), so
        // fall back to finding the object by scanning rather than
        // declaring the document broken.
        $scanned = $this->scanner->offsetOf($objectId);

        return $scanned === null ? null : $this->parseAt($scanned, $objectId);
    }

    private function parseAt(int $offset, int $objectId): ?PdfValue
    {
        try {
            $parsed = $this->parser->parseIndirectObjectAt($offset);
        } catch (ParseException) {
            // Deliberately swallowed: this is a speculative read at an
            // offset that may be stale. Failing here means "not found
            // there", and load() still has the scanner to try.
            return null;
        }

        // The object number found must be the one asked for. Without this
        // check a stale offset that happens to land on some *other*
        // object would return that object's contents under the requested
        // id -- corruption that no later stage could detect.
        if ($parsed->objectId !== $objectId) {
            return null;
        }

        return $this->decrypt($parsed->value, $objectId, $parsed->generation);
    }

    /**
     * Deciphers one indirect object, if the document is encrypted.
     *
     * Done here, at the point of parsing, because the key depends on the
     * object's own number and generation (see
     * StandardSecurityHandler::objectKey()) -- a string carried away and
     * decrypted later has lost the only thing that identifies its key.
     *
     * Members of an object stream deliberately do not pass through here:
     * the container was decrypted as a whole, and its contents are
     * plaintext by the time they are parsed. Decrypting them again would
     * be applying a key to data that was never enciphered with it.
     */
    private function decrypt(PdfValue $value, int $objectId, int $generation): PdfValue
    {
        $security = $this->security;

        if ($security === null || $objectId === $this->encryptObjectId) {
            return $value;
        }

        if ($value instanceof Dictionary
            && CryptTransform::isNeverEncrypted($value, $security->encryptsMetadata())) {
            return $value;
        }

        return CryptTransform::apply(
            $value,
            static fn (string $bytes): string => $security->decryptString($bytes, $objectId, $generation),
            static fn (string $bytes): string => $security->decryptStream($bytes, $objectId, $generation),
        );
    }

    private function resolveLength(PdfReference $reference): ?int
    {
        $value = $this->resolve($reference);

        return $value instanceof PdfInteger ? $value->value() : null;
    }

    private function loadFromObjectStream(XrefEntry $entry): ?PdfValue
    {
        $container = $entry->containerObjectId;

        if ($container === null) {
            return null;
        }

        $members = $this->objectStreams[$container] ??= $this->readObjectStream($container);

        // Looked up by object id rather than by the entry's index. The
        // index is a claim the cross-reference table makes about the
        // object stream's internal ordering, and if the two disagree the
        // id is the one that cannot be misinterpreted.
        return $members[$entry->objectId] ?? null;
    }

    /**
     * Decodes an object stream (/Type /ObjStm) and parses every object in
     * it at once.
     *
     * All of them, not just the one asked for, because the expensive part
     * is inflating the container -- once that is paid, its members are a
     * few hundred bytes of parsing each, and a file that compresses its
     * whole page tree into one stream would otherwise pay the inflate
     * again for every page.
     *
     * @return array<int, PdfValue>
     */
    private function readObjectStream(int $containerObjectId): array
    {
        $container = $this->get($containerObjectId);

        if (!$container instanceof Stream) {
            return [];
        }

        $type = $container->get('Type');

        if (!$type instanceof PdfName || $type->value() !== 'ObjStm') {
            return [];
        }

        $count = $this->resolve($container->get('N'));
        $first = $this->resolve($container->get('First'));

        if (!$count instanceof PdfInteger || !$first instanceof PdfInteger) {
            return [];
        }

        try {
            $data = $this->decodedStream($container);
        } catch (ParseException) {
            return [];
        }

        // The stream begins with /N pairs of integers -- object number and
        // where that object starts, relative to /First. Both the header
        // and the objects live in the decoded bytes, so one lexer serves
        // for both.
        $lexer = new Lexer($data);
        $parser = new ObjectParser($lexer);

        $positions = [];

        for ($i = 0; $i < $count->value(); ++$i) {
            $objectId = $lexer->nextToken();
            $offset = $lexer->nextToken();

            if ($objectId === null || $offset === null
                || $objectId->type !== TokenType::Number || $offset->type !== TokenType::Number) {
                break;
            }

            $positions[(int) $objectId->value] = $first->value() + (int) $offset->value;
        }

        $members = [];

        foreach ($positions as $objectId => $position) {
            if ($position < 0 || $position >= strlen($data)) {
                continue;
            }

            try {
                // parseValueAt(), not parseIndirectObjectAt(): a member of
                // an object stream is stored bare, with no "N G obj"
                // wrapper -- that header is exactly the overhead this
                // format exists to remove. Generation is always 0 here.
                $members[$objectId] = $parser->parseValueAt($position, $objectId);
            } catch (ParseException) {
                // One damaged member should not cost the whole container.
            }
        }

        return $members;
    }
}
