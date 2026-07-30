<?php

declare(strict_types=1);

namespace MightyPDF\Assembler\Form;

use MightyPDF\Assembler\Dictionary;
use MightyPDF\Assembler\Types\PdfArray;
use MightyPDF\Assembler\Types\PdfInteger;
use MightyPDF\Assembler\Types\PdfName;
use MightyPDF\Assembler\Types\PdfReference;
use MightyPDF\Assembler\Types\PdfString;

/**
 * The parent field of a radio button group (ISO 32000-2 §12.7.4.2.3),
 * /FT /Btn with the Radio flag set.
 *
 * Unlike TextField/CheckboxField, this is not itself a widget annotation
 * -- it has no /Rect and is never placed on a page directly. Each option
 * is a separate RadioButtonWidget listed in this field's /Kids, and only
 * one of those widgets can be in the "on" state at a time (enforced by
 * every kid sharing the same /Parent and this field's single /V), which
 * is what gives a radio group real mutual exclusion handled natively by
 * the reader -- no JavaScript involved.
 */
final class RadioGroupField extends Dictionary
{
    /** Button field flags (Table 227): bit 16, "Radio". */
    private const int FLAG_RADIO = 1 << 15;

    /** @var list<int> */
    private array $kidObjectIds = [];

    public function __construct(int $objectId, string $name, ?string $checkedExportValue)
    {
        parent::__construct($objectId);

        $this->set('FT', new PdfName('Btn'));
        $this->set('T', PdfString::latin1($name));
        $this->set('Ff', new PdfInteger(self::FLAG_RADIO));
        $this->set('V', new PdfName($checkedExportValue ?? 'Off'));
        $this->syncKids();
    }

    public function addKid(int $kidObjectId): void
    {
        $this->kidObjectIds[] = $kidObjectId;
        $this->syncKids();
    }

    private function syncKids(): void
    {
        $this->set('Kids', new PdfArray(...array_map(
            static fn (int $id): PdfReference => new PdfReference($id),
            $this->kidObjectIds,
        )));
    }
}
