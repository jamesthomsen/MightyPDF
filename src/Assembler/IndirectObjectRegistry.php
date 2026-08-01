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
    public function writeAll(string $header): SerializedDocumentBody
    {
        $objects = $this->objects;
        ksort($objects);

        $xref = new Xref();
        $out = $header;

        foreach ($objects as $objectId => $object) {
            $xref->addEntry($objectId, strlen($out));
            $out .= $object->render(true);
        }

        return new SerializedDocumentBody($out, $xref);
    }
}
