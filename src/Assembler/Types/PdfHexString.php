<?php

declare(strict_types=1);

namespace MightyPDF\Assembler\Types;

/**
 * A PDF hexadecimal string, "<...>" (ISO 32000-2 §7.3.4.3).
 */
final class PdfHexString implements PdfValue
{
    public function __construct(private readonly string $bytes)
    {
    }

    public function format(): string
    {
        return '<' . bin2hex($this->bytes) . '>';
    }
}
