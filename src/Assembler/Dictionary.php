<?php

declare(strict_types=1);

namespace MightyPDF\Assembler;

use MightyPDF\Assembler\Types\PdfName;
use MightyPDF\Assembler\Types\PdfValue;

/**
 * A PDF dictionary object, "<< /Key value ... >>" (ISO 32000-2 §7.3.7).
 *
 * Entries exist only when set to a real value -- assigning null removes
 * the entry (PDF's convention for "this key is absent"), so rendering
 * never needs to filter nulls out the way the 2012 Dictionary did. To
 * write an explicit PDF null or false, set the value to PdfNull/PdfBoolean
 * rather than PHP null.
 */
class Dictionary extends PdfObject
{
    /** @var array<string, PdfValue> */
    private array $entries = [];

    public function set(string $key, ?PdfValue $value): static
    {
        if ($value === null) {
            unset($this->entries[$key]);
        } else {
            $this->entries[$key] = $value;
        }

        return $this;
    }

    public function get(string $key): ?PdfValue
    {
        return $this->entries[$key] ?? null;
    }

    /** @return array<string, PdfValue> */
    public function entries(): array
    {
        return $this->entries;
    }

    protected function content(): string
    {
        if ($this->entries === []) {
            return '<<>>';
        }

        $parts = [];
        foreach ($this->entries as $name => $value) {
            $parts[] = (new PdfName($name))->format() . ' ' . $value->format();
        }

        return '<< ' . implode(' ', $parts) . ' >>';
    }
}
