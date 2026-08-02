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
 * the free-list head object 0). Here, exactly one method (writeAll())
 * ever walks registered objects and records offsets; nothing else in the
 * assembler touches an Xref or does offset math.
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
     */
    public function writeAll(string $header, ?\Closure $prepare = null): SerializedDocumentBody
    {
        $objects = $this->objects;
        ksort($objects);

        self::finalizeAll($objects);

        $xref = new Xref();
        $out = $header;

        foreach ($objects as $objectId => $object) {
            $xref->addEntry($objectId, strlen($out));
            $out .= ($prepare === null ? $object : $prepare($object))->render(true);
        }

        return new SerializedDocumentBody($out, $xref);
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
