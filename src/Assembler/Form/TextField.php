<?php

declare(strict_types=1);

namespace MightyPDF\Assembler\Form;

use MightyPDF\Assembler\Types\PdfInteger;
use MightyPDF\Assembler\Types\PdfNumberFormat;
use MightyPDF\Assembler\Types\PdfRectangle;
use MightyPDF\Assembler\Types\PdfString;
use MightyPDF\Assembler\Types\WinAnsiEncoding;

/**
 * A text field (ISO 32000-2 §12.7.4.3), /FT /Tx -- single-line by default,
 * or multiline when the caller asks for it.
 */
final class TextField extends FormField
{
    /** Field flags (Table 227): bit 1 "ReadOnly", bit 13 "Multiline". */
    private const int FLAG_READONLY = 1;
    private const int FLAG_MULTILINE = 1 << 12;

    /** Quadding values (§12.7.3.1, /Q): 0 left, 1 center, 2 right. */
    public const int ALIGN_LEFT = 0;
    public const int ALIGN_CENTER = 1;
    public const int ALIGN_RIGHT = 2;

    public function __construct(
        int $objectId,
        string $name,
        PdfRectangle $rect,
        string $defaultAppearanceFontResourceName,
        float $fontSizePt,
        ?string $value = null,
        ?int $maxLength = null,
        ?int $align = null,
        bool $multiline = false,
        bool $readonly = false,
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

        if ($align !== null) {
            $this->set('Q', new PdfInteger($align));
        }

        $flags = ($readonly ? self::FLAG_READONLY : 0) | ($multiline ? self::FLAG_MULTILINE : 0);
        if ($flags !== 0) {
            $this->set('Ff', new PdfInteger($flags));
        }
    }

    protected function fieldType(): string
    {
        return 'Tx';
    }
}
