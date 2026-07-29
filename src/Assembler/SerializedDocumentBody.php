<?php

declare(strict_types=1);

namespace MightyPDF\Assembler;

/**
 * The result of IndirectObjectRegistry::writeAll(): the concatenated
 * object bytes, plus the Xref that was built alongside them (so the
 * trailer's /Size can be derived directly from it -- see Xref and
 * IndirectObjectRegistry doc comments for why that matters).
 */
final class SerializedDocumentBody
{
    public function __construct(
        public readonly string $bytes,
        public readonly Xref $xref,
    ) {
    }
}
