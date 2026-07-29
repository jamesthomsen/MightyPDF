<?php

declare(strict_types=1);

namespace MightyPDF\Assembler\Types;

/**
 * An explicit PDF boolean value. Note this is distinct from omitting a
 * dictionary entry entirely -- a dictionary key set to PdfBoolean(false)
 * still appears in the output as "/Key false", unlike a null entry.
 */
final class PdfBoolean implements PdfValue
{
    public function __construct(private readonly bool $value)
    {
    }

    public function value(): bool
    {
        return $this->value;
    }

    public function format(): string
    {
        return $this->value ? 'true' : 'false';
    }
}
