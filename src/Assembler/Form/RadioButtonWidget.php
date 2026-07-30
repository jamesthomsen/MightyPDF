<?php

declare(strict_types=1);

namespace MightyPDF\Assembler\Form;

use MightyPDF\Assembler\Dictionary;
use MightyPDF\Assembler\Stream;
use MightyPDF\Assembler\Types\PdfInteger;
use MightyPDF\Assembler\Types\PdfName;
use MightyPDF\Assembler\Types\PdfReference;
use MightyPDF\Assembler\Types\PdfRectangle;

/**
 * One option's on-page widget annotation within a radio button group
 * (ISO 32000-2 §12.7.4.2.3). Deliberately does not extend FormField: a
 * radio option has no /FT of its own (inherited from its RadioGroupField
 * /Parent) and carries a /Parent back-reference that no other field type
 * in this library needs.
 *
 * Like CheckboxField, the on/off visual is driven by /AS + /AP rather
 * than reader-regenerated appearances, since readers are inconsistent
 * about regenerating button appearances on their own -- see
 * CheckboxField's docblock for the same reasoning.
 */
final class RadioButtonWidget extends Dictionary
{
    /** Annotation flags (Table 167): bit 3, "Print". */
    private const int FLAG_PRINT = 4;

    public function __construct(
        int $objectId,
        int $parentObjectId,
        PdfRectangle $rect,
        string $exportValue,
        bool $checked,
        Stream $onAppearance,
        Stream $offAppearance,
    ) {
        parent::__construct($objectId);

        $this->set('Type', new PdfName('Annot'));
        $this->set('Subtype', new PdfName('Widget'));
        $this->set('Parent', new PdfReference($parentObjectId));
        $this->set('Rect', $rect);
        $this->set('F', new PdfInteger(self::FLAG_PRINT));

        $state = $checked ? $exportValue : 'Off';
        $this->set('AS', new PdfName($state));

        $states = new Dictionary();
        $states->set($exportValue, new PdfReference($onAppearance->objectId()));
        $states->set('Off', new PdfReference($offAppearance->objectId()));

        $appearance = new Dictionary();
        $appearance->set('N', $states);
        $this->set('AP', $appearance);
    }
}
