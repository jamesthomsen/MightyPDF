<?php

declare(strict_types=1);

namespace MightyPDF\Assembler;

use MightyPDF\Assembler\Types\PdfArray;
use MightyPDF\Assembler\Types\PdfInteger;
use MightyPDF\Assembler\Types\PdfReference;

/**
 * The trailer dictionary (ISO 32000-2 §7.5.5), "trailer\n<< ... >>".
 *
 * Per spec the trailer is never itself an indirect object, so this does
 * not extend PdfObject/Dictionary -- it renders its own small, fixed set
 * of entries directly.
 *
 * $size must always be derived from the same Xref that was actually
 * written (Xref::highestObjectId() + 1), never hand-copied by a caller:
 * the 2012 bug was exactly this value coming from a third call site
 * (Xref::length(), which excluded the free-list head) instead of the
 * xref table itself.
 */
final class Trailer
{
    public function __construct(
        private readonly int $size,
        private readonly int $rootObjectId,
        private readonly ?int $infoObjectId = null,
        private readonly ?PdfArray $id = null,
    ) {
    }

    public function build(): string
    {
        $entries = [];
        $entries[] = '/Size ' . (new PdfInteger($this->size))->format();
        $entries[] = '/Root ' . (new PdfReference($this->rootObjectId))->format();

        if ($this->infoObjectId !== null) {
            $entries[] = '/Info ' . (new PdfReference($this->infoObjectId))->format();
        }

        if ($this->id !== null) {
            $entries[] = '/ID ' . $this->id->format();
        }

        return "trailer\n<< " . implode(' ', $entries) . " >>\n";
    }
}
