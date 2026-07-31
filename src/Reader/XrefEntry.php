<?php

declare(strict_types=1);

namespace MightyPDF\Reader;

/**
 * One in-use entry from a cross-reference table: "object N generation G
 * lives at byte $offset".
 *
 * Free entries are not represented at all. They exist to chain the file's
 * free list, which only matters to a writer that wants to reuse object
 * numbers -- something MightyPDF will never do, since an incremental
 * update always appends fresh ones. To a reader, a freed object is simply
 * an object that is not there, which is exactly what "no entry" already
 * means.
 */
final readonly class XrefEntry
{
    public function __construct(
        public int $objectId,
        public int $generation,
        public int $offset,
    ) {
    }
}
