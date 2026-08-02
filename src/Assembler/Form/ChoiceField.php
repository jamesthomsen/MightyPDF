<?php

declare(strict_types=1);

namespace MightyPDF\Assembler\Form;

use MightyPDF\Assembler\Types\PdfArray;
use MightyPDF\Assembler\Types\PdfInteger;
use MightyPDF\Assembler\Types\PdfNumberFormat;
use MightyPDF\Assembler\Types\PdfRectangle;
use MightyPDF\Assembler\Types\PdfString;

/**
 * A choice field (ISO 32000-2 §12.7.4.4), /FT /Ch -- a list box, or a
 * dropdown ("combo box" in spec terms) when the Combo flag is set. The
 * two are the same field type differing by one flag, so one class backs
 * both PageBuilder::addListBox() and PageBuilder::addDropdown().
 *
 * Relies on /NeedsAppearances like TextField, rather than hand-building
 * a list/dropdown appearance stream -- same reasoning as TextField's
 * docblock.
 */
final class ChoiceField extends FormField
{
    /** Field flags (Table 230): bit 18 "Combo", bit 1 "ReadOnly". */
    private const int FLAG_COMBO = 1 << 17;
    private const int FLAG_READONLY = 1;

    /**
     * @param list<string> $options in /Opt order; the caller is
     *        responsible for $value (if given) matching one of them, same
     *        as addRadioGroup()'s $checkedExportValue.
     */
    public function __construct(
        int $objectId,
        string $name,
        PdfRectangle $rect,
        string $defaultAppearanceFontResourceName,
        float $fontSizePt,
        array $options,
        ?string $value = null,
        bool $combo = false,
        bool $readonly = false,
    ) {
        parent::__construct($objectId, $name, $rect);

        $this->set('DA', PdfString::latin1(sprintf(
            '/%s %s Tf 0 g',
            $defaultAppearanceFontResourceName,
            PdfNumberFormat::format($fontSizePt),
        )));

        $this->set('Opt', new PdfArray(...array_map(
            static fn (string $option): PdfString => PdfString::text($option),
            $options,
        )));

        if ($value !== null) {
            $this->set('V', PdfString::text($value));
        }

        $flags = ($combo ? self::FLAG_COMBO : 0) | ($readonly ? self::FLAG_READONLY : 0);
        if ($flags !== 0) {
            $this->set('Ff', new PdfInteger($flags));
        }
    }

    protected function fieldType(): string
    {
        return 'Ch';
    }
}
