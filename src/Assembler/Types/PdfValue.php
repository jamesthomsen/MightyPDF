<?php

declare(strict_types=1);

namespace MightyPDF\Assembler\Types;

/**
 * A PDF object that can render itself as the exact bytes that appear
 * inline in PDF syntax (see ISO 32000-2 §7.3, "Objects").
 */
interface PdfValue
{
    public function format(): string;
}
