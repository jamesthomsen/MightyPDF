<?php

declare(strict_types=1);

namespace MightyPDF\Assembler;

/**
 * The single owner of both object-id allocation and byte-offset
 * bookkeeping for a document being assembled.
 *
 * This directly replaces the 2012 bug's root cause: offset arithmetic was
 * scattered across three call sites (MightyPDF::save() for the pages/page
 * tree/catalog, MightyPDF_Page::build() for attached content streams),
 * each doing its own strlen($out) math -- exactly the kind of duplication
 * that produces undercounting bugs like the confirmed /Size-off-by-one
 * (the trailer's Size was hand-copied from Xref::length(), which excluded
 * the free-list head object 0). Here, exactly one method (writeAllTo())
 * ever walks registered objects and records offsets; nothing else in the
 * assembler touches an Xref or does offset math. writeAll() is that same
 * method collecting into memory.
 */
final class IndirectObjectRegistry implements ObjectHost
{
    private int $nextId = 0;

    /** @var array<int, PdfObject> */
    private array $objects = [];

    public function allocate(): int
    {
        return ++$this->nextId;
    }

    public function register(PdfObject $object): void
    {
        $this->objects[$object->objectId()] = $object;
    }

    /**
     * Serializes every registered object, in ascending object-id order --
     * not registration order -- and builds the matching Xref as it goes.
     *
     * Ascending id order matters specifically because the 2012 code
     * emitted objects in whatever order they happened to be traversed
     * (pages, then the page tree, then the catalog, then any stream
     * reached via a page's inner build loop), which only produced a
     * correct xref table because allocation order and traversal order
     * happened to coincide. That implicit coupling is exactly what this
     * method removes: callers may register objects in any order.
     */
    /**
     * @param (\Closure(PdfObject): PdfObject)|null $prepare a last chance
     *        to substitute what actually gets written -- encryption, which
     *        has to reach the strings and streams inside every object
     *        while leaving object numbers and offsets exactly where they
     *        are. It returns a replacement rather than mutating, so the
     *        document stays in the state the caller built and save() can
     *        be called twice.
     * @param bool $compressObjects whether to pack what can be packed into
     *        object streams (see ObjectStream). The resulting Xref carries
     *        type-2 entries and so can only be written as a cross-reference
     *        stream; Xref::build() refuses it, deliberately.
     * @param list<int> $keepDirect object ids that must stay at a byte
     *        offset of their own whatever $compressObjects says -- the
     *        encryption dictionary, which a reader has to read before it
     *        can decrypt the object stream that would otherwise hold it.
     */
    public function writeAll(
        string $header,
        ?\Closure $prepare = null,
        bool $compressObjects = false,
        array $keepDirect = [],
    ): SerializedDocumentBody {
        $sink = new StringSink();

        $xref = $this->writeAllTo($header, $sink, $prepare, $compressObjects, $keepDirect);

        return new SerializedDocumentBody($sink->contents(), $xref);
    }

    /**
     * The same, writing into $sink as it goes and handing back only the
     * Xref -- which is what makes a document larger than memory possible
     * to write at all. See ByteSink, and Document::writeTo() for the
     * caller this exists for.
     *
     * Each object is still built in full before it is written, so peak
     * memory is bounded by the largest single object rather than by the
     * document: a 40 MB scanned image is 40 MB here, not 40 MB plus
     * every page that came before it.
     *
     * @param (\Closure(PdfObject): PdfObject)|null $prepare see writeAll()
     * @param list<int> $keepDirect see writeAll()
     */
    public function writeAllTo(
        string $header,
        ByteSink $sink,
        ?\Closure $prepare = null,
        bool $compressObjects = false,
        array $keepDirect = [],
    ): Xref {
        $objects = $this->objects;
        ksort($objects);

        self::finalizeAll($objects);

        $xref = new Xref();

        $packable = $compressObjects ? self::packable($objects, $keepDirect) : [];
        $containers = $this->packInto($packable, $xref);

        $sink->write($header);

        foreach ($objects as $objectId => $object) {
            if (isset($packable[$objectId])) {
                // Written inside a container instead, and by that route
                // deliberately unprepared: §7.5.7 encrypts an object
                // stream as a whole rather than the strings inside it.
                continue;
            }

            // Recorded before the write, not after: an entry says where
            // an object starts, and the sink's offset stops being that
            // the moment the object goes through it.
            $xref->addEntry($objectId, $sink->offset());
            $sink->write(($prepare === null ? $object : $prepare($object))->render(true));
        }

        // After the rest, which keeps the file in ascending object-id
        // order: container ids are allocated above everything registered.
        foreach ($containers as $container) {
            $xref->addEntry($container->objectId(), $sink->offset());
            $sink->write(($prepare === null ? $container : $prepare($container))->render(true));
        }

        return $xref;
    }

    /**
     * The objects eligible to be packed, keyed by id.
     *
     * @param array<int, PdfObject> $objects
     * @param list<int> $keepDirect
     *
     * @return array<int, PdfObject>
     */
    private static function packable(array $objects, array $keepDirect): array
    {
        $excluded = array_flip($keepDirect);

        return array_filter(
            $objects,
            static fn (PdfObject $object, int $id): bool
                => !isset($excluded[$id]) && ObjectStream::accepts($object),
            ARRAY_FILTER_USE_BOTH,
        );
    }

    /**
     * Packs $packable into as many object streams as it takes, recording
     * a type-2 entry for each object as it goes.
     *
     * Container object ids are derived from the highest id registered
     * rather than taken from allocate(), so that writing the document does
     * not consume ids: save() may be called twice (see Document::save()),
     * and a registry whose id space grew every time would give the second
     * file a different shape from the first for no reason.
     *
     * @param array<int, PdfObject> $packable
     *
     * @return list<Stream>
     */
    private function packInto(array $packable, Xref $xref): array
    {
        if ($packable === []) {
            return [];
        }

        $nextContainerId = max(max(array_keys($this->objects)), $this->nextId) + 1;
        $containers = [];

        foreach (array_chunk($packable, ObjectStream::CAPACITY, true) as $chunk) {
            $container = ObjectStream::pack($nextContainerId++, $chunk);
            $containers[] = $container;

            $index = 0;

            foreach (array_keys($chunk) as $objectId) {
                $xref->addCompressedEntry($objectId, $container->objectId(), $index++);
            }
        }

        return $containers;
    }

    /**
     * Gives every object that has to wait for the whole document its last
     * chance to fill itself in, before any of them is serialized.
     *
     * A separate pass rather than a step inside the write loop: a font
     * dictionary finalizing itself writes into its own descriptor and
     * font-file streams, which are objects in their own right and may
     * carry lower numbers -- and so would already have been written past.
     * See Finalizable for what implementations must guarantee.
     *
     * Shared with the incremental-update writer (MightyPDF\Editor\
     * PdfEditor::save()), which has the same problem and no registry.
     *
     * @param iterable<PdfObject> $objects
     */
    public static function finalizeAll(iterable $objects): void
    {
        foreach ($objects as $object) {
            if ($object instanceof Finalizable) {
                $object->finalize();
            }
        }
    }
}
