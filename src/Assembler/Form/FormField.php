<?php

declare(strict_types=1);

namespace MightyPDF\Assembler\Form;

use MightyPDF\Assembler\Dictionary;
use MightyPDF\Assembler\Types\PdfInteger;
use MightyPDF\Assembler\Types\PdfName;
use MightyPDF\Assembler\Types\PdfRectangle;
use MightyPDF\Assembler\Types\PdfString;

/**
 * Base for an interactive form field (ISO 32000-2 §12.7.3). In PDF a form
 * field and its on-page widget annotation are, for a simple single-widget
 * field like the ones this library builds, the same object -- so this
 * models them merged into one, matching how real-world simple PDFs
 * actually structure it, rather than an idealized two-object model that
 * would need reconciling against real files once phase 2 opens existing
 * PDFs.
 */
abstract class FormField extends Dictionary
{
    /** Annotation flags (Table 167): bit 3, "Print". */
    private const int FLAG_PRINT = 4;

    public function __construct(int $objectId, string $name, PdfRectangle $rect)
    {
        parent::__construct($objectId);

        $this->set('Type', new PdfName('Annot'));
        $this->set('Subtype', new PdfName('Widget'));
        $this->set('FT', new PdfName($this->fieldType()));
        $this->set('T', PdfString::text($name));
        $this->set('Rect', $rect);
        $this->set('F', new PdfInteger(self::FLAG_PRINT));
    }

    abstract protected function fieldType(): string;
}
