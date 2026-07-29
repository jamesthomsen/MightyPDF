<?php

declare(strict_types=1);

namespace MightyPDF\Assembler\Form;

use MightyPDF\Assembler\Types\PdfInteger;
use MightyPDF\Assembler\Types\PdfNumberFormat;
use MightyPDF\Assembler\Types\PdfRectangle;
use MightyPDF\Assembler\Types\PdfString;
use MightyPDF\Assembler\Types\WinAnsiEncoding;

/** A single-line text field (ISO 32000-2 §12.7.4.3), /FT /Tx. */
final class TextField extends FormField
{
    public function __construct(
        int $objectId,
        string $name,
        PdfRectangle $rect,
        string $defaultAppearanceFontResourceName,
        float $fontSizePt,
        ?string $value = null,
        ?int $maxLength = null,
    ) {
        parent::__construct($objectId, $name, $rect);

        $this->set('DA', PdfString::latin1(sprintf(
            '/%s %s Tf 0 g',
            $defaultAppearanceFontResourceName,
            PdfNumberFormat::format($fontSizePt),
        )));

        if ($value !== null) {
            $this->set('V', PdfString::latin1(WinAnsiEncoding::encode($value)));
        }

        if ($maxLength !== null) {
            $this->set('MaxLen', new PdfInteger($maxLength));
        }
    }

    protected function fieldType(): string
    {
        return 'Tx';
    }
}
