<?php

declare(strict_types=1);

namespace MightyPDF\Assembler\Types;

final class PdfReal implements PdfValue
{
    public function __construct(private readonly float $value)
    {
    }

    public function value(): float
    {
        return $this->value;
    }

    public function format(): string
    {
        return PdfNumberFormat::format($this->value);
    }
}
