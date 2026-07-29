<?php

declare(strict_types=1);

namespace MightyPDF\Assembler\Types;

/**
 * A PDF array, "[...]" (ISO 32000-2 §7.3.6).
 *
 * Immutable by construction: every element must already be a PdfValue, so
 * (unlike the 2012 implementation, whose Array::format() blindly called
 * ->format() on whatever it was given) there's no way to end up with a
 * bare scalar in the list that blows up when rendered.
 */
final class PdfArray implements PdfValue
{
    /** @var list<PdfValue> */
    private readonly array $items;

    public function __construct(PdfValue ...$items)
    {
        $this->items = array_values($items);
    }

    /** @return list<PdfValue> */
    public function items(): array
    {
        return $this->items;
    }

    public function format(): string
    {
        return '[' . implode(' ', array_map(static fn (PdfValue $item): string => $item->format(), $this->items)) . ']';
    }
}
