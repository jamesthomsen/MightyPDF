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

    public function bytes(): string
    {
        return $this->bytes;
    }

    /**
     * Hexadecimal is a way of writing bytes, not a separate kind of
     * string: a field name or value is just as validly written
     * <FEFF0041> as (A), so it decodes by the same rules.
     */
    public function toUtf8(): string
    {
        return PdfString::decode($this->bytes);
    }

    public function format(): string
    {
        return '<' . bin2hex($this->bytes) . '>';
    }
}
