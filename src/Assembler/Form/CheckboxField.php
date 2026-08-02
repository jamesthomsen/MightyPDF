<?php

declare(strict_types=1);

namespace MightyPDF\Assembler\Form;

use MightyPDF\Assembler\Dictionary;
use MightyPDF\Assembler\Stream;
use MightyPDF\Assembler\Types\PdfName;
use MightyPDF\Assembler\Types\PdfReference;
use MightyPDF\Assembler\Types\PdfRectangle;

/**
 * A checkbox field (ISO 32000-2 §12.7.4.2.3), /FT /Btn.
 *
 * Unlike TextField, a checkbox's on/off visual is conventionally driven
 * by /AS + /AP rather than reader-regenerated from /NeedAppearances
 * alone, so this always carries a minimal two-state /AP /N appearance
 * dictionary -- the on/off Form XObjects themselves are built by
 * PageBuilder (which already owns ContentStream/registry access) and
 * simply handed in here.
 */
final class CheckboxField extends FormField
{
    public function __construct(
        int $objectId,
        string $name,
        PdfRectangle $rect,
        bool $checked,
        Stream $onAppearance,
        Stream $offAppearance,
        string $exportValue = 'Yes',
    ) {
        parent::__construct($objectId, $name, $rect);

        $state = $checked ? $exportValue : 'Off';
        $this->set('V', new PdfName($state));
        $this->set('AS', new PdfName($state));

        $states = new Dictionary();
        $states->set($exportValue, new PdfReference($onAppearance->objectId()));
        $states->set('Off', new PdfReference($offAppearance->objectId()));

        $appearance = new Dictionary();
        $appearance->set('N', $states);
        $this->set('AP', $appearance);
    }

    protected function fieldType(): string
    {
        return 'Btn';
    }
}
