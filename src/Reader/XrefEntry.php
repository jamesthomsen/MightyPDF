<?php

declare(strict_types=1);

namespace MightyPDF\Reader;

/**
 * One in-use cross-reference entry, in either of the two forms a PDF can
 * record: the object sits at a byte offset in the file, or it sits inside
 * an object stream along with a crowd of others.
 *
 * Free entries are not represented at all. They exist to chain the file's
 * free list, which only matters to a writer that wants to reuse object
 * numbers -- something MightyPDF will never do, since an incremental
 * update always appends fresh ones. To a reader, a freed object is simply
 * an object that is not there, which is exactly what "no entry" means.
 */
final readonly class XrefEntry
{
    private function __construct(
        public int $objectId,
        public int $generation,
        public int $offset,
        public ?int $containerObjectId,
        public ?int $indexInContainer,
    ) {
    }

    /** A type 1 entry: the object is written directly in the file. */
    public static function atOffset(int $objectId, int $generation, int $offset): self
    {
        return new self($objectId, $generation, $offset, null, null);
    }

    /**
     * A type 2 entry: the object lives inside object stream
     * $containerObjectId, as its $indexInContainer'th member.
     *
     * The generation is always 0 -- the spec does not give compressed
     * objects one, since an object stream's members are rewritten
     * wholesale rather than freed and reused individually.
     */
    public static function inObjectStream(int $objectId, int $containerObjectId, int $indexInContainer): self
    {
        return new self($objectId, 0, 0, $containerObjectId, $indexInContainer);
    }

    public function isCompressed(): bool
    {
        return $this->containerObjectId !== null;
    }
}
