<?php

declare(strict_types=1);

namespace MightyPDF\Reader;

use MightyPDF\Assembler\Types\PdfValue;

/**
 * One "N G obj ... endobj" as it was found in the file.
 *
 * The generation number is carried separately rather than being pushed
 * into the parsed value because PdfObject has no generation field: the
 * writer has only ever produced fresh documents, where everything is
 * generation 0 (see PdfReference's doc comment). Reading is where non-zero
 * generations first become observable, and callers need to see the number
 * that was actually in the file -- an xref entry only matches if the
 * generation matches too.
 */
final readonly class IndirectObject
{
    public function __construct(
        public int $objectId,
        public int $generation,
        public PdfValue $value,
    ) {
    }
}
