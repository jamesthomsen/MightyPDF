<?php

declare(strict_types=1);

namespace MightyPDF\Reader;

use MightyPDF\Assembler\Dictionary;
use MightyPDF\Assembler\Types\PdfInteger;
use MightyPDF\Assembler\Types\PdfReference;
use MightyPDF\Assembler\Types\PdfValue;

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

    /** @var array<int, PdfValue|null> parsed objects, including proven-absent ones */
    private array $cache = [];

    /** @var array<int, true> */
    private array $loading = [];

    public function __construct(string $bytes)
    {
        $this->lexer = new Lexer($bytes);
        $this->xref = XrefTable::read($this->lexer);
        $this->scanner = new ObjectScanner($bytes);
        $this->parser = new ObjectParser(
            $this->lexer,
            fn (PdfReference $reference): ?int => $this->resolveLength($reference),
        );

        if ($this->xref->trailer()->get('Encrypt') !== null) {
            // Refusing outright, rather than reading on. In an encrypted
            // file every string and stream is ciphertext, so parsing
            // "succeeds" and yields a document full of binary noise --
            // field names that match nothing, content streams that draw
            // nothing. Filling a form in such a file would produce a
            // plausible-looking PDF that is silently wrong, which is far
            // worse than not opening it.
            throw new ParseException('This PDF is encrypted; decryption is not implemented yet.');
        }
    }

    public static function fromFile(string $path): self
    {
        $bytes = @file_get_contents($path);

        if ($bytes === false) {
            throw new ParseException("Failed to read PDF from $path.");
        }

        return new self($bytes);
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

    private function load(int $objectId): ?PdfValue
    {
        $entry = $this->xref->entry($objectId);

        if ($entry !== null) {
            $value = $this->parseAt($entry->offset, $objectId);

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
        return $parsed->objectId === $objectId ? $parsed->value : null;
    }

    private function resolveLength(PdfReference $reference): ?int
    {
        $value = $this->resolve($reference);

        return $value instanceof PdfInteger ? $value->value() : null;
    }
}
