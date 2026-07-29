<?php

declare(strict_types=1);

namespace MightyPDF\Assembler\Types;

final class PdfInteger implements PdfValue
{
    public function __construct(private readonly int $value)
    {
    }

    public function value(): int
    {
        return $this->value;
    }

    public function format(): string
    {
        return (string) $this->value;
    }
}
