<?php

declare(strict_types=1);

namespace MightyPDF\Assembler\Types;

/**
 * An explicit PDF null value ("/Key null"), distinct from omitting the
 * entry entirely.
 */
final class PdfNull implements PdfValue
{
    public function format(): string
    {
        return 'null';
    }
}
