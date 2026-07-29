<?php

declare(strict_types=1);

namespace MightyPDF\Assembler\Types;

/**
 * An indirect reference, "N G R" (ISO 32000-2 §7.3.10).
 *
 * Generation is always 0 for phase 1 (this library only ever writes fresh
 * documents, never incremental updates), but it's a real field rather than
 * a hardcoded literal so phase 2 -- which will need non-zero generations
 * when editing existing files -- doesn't require changing this type.
 */
final class PdfReference implements PdfValue
{
    public function __construct(
        private readonly int $objectId,
        private readonly int $generation = 0,
    ) {
    }

    public function objectId(): int
    {
        return $this->objectId;
    }

    public function generation(): int
    {
        return $this->generation;
    }

    public function format(): string
    {
        return sprintf('%d %d R', $this->objectId, $this->generation);
    }
}
