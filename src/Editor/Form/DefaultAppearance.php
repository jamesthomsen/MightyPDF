<?php

declare(strict_types=1);

namespace MightyPDF\Editor\Form;

use MightyPDF\Assembler\Types\PdfNumberFormat;

/**
 * A field's /DA string, taken apart (ISO 32000-2 §12.7.4.3).
 *
 * /DA is not a dictionary. It is a fragment of content-stream operators
 * -- "/Helv 9 Tf 0 g" -- meant to be replayed verbatim at the start of an
 * appearance stream, and it is the only place a text field says what font
 * and size it wants. The font name in it refers to the AcroForm's /DR
 * resources, not to anything on the page.
 *
 * The operators are kept as-is and replayed unchanged, so that colour,
 * word spacing or anything else a producer put in there survives. Only
 * the font name and size are pulled out, because the appearance builder
 * has to measure the text and cannot do that without them.
 */
final readonly class DefaultAppearance
{
    private function __construct(
        public string $operators,
        public ?string $fontResourceName,
        public float $fontSizePt,
    ) {
    }

    /**
     * A size of 0 in /DA is not a mistake -- it means "choose a size that
     * fits", which is a real and common setting.
     */
    public function isAutoSized(): bool
    {
        return $this->fontSizePt <= 0.0;
    }

    public function withSize(float $sizePt): self
    {
        // The replayed operators must carry the resolved size, or the
        // reader would set the font back to 0 after we measured at a real
        // one.
        $operators = preg_replace(
            '/\/([^\s\/]+)\s+[\d.+-]+\s+Tf/',
            sprintf('/$1 %s Tf', PdfNumberFormat::format($sizePt)),
            $this->operators,
            1,
        );

        return new self($operators ?? $this->operators, $this->fontResourceName, $sizePt);
    }

    public static function parse(?string $da): self
    {
        $da ??= '';

        // The last Tf wins, as it would if these operators were executed.
        if (preg_match_all('/\/([^\s\/\[\]<>(){}]+)\s+([\d.+-]+)\s+Tf/', $da, $matches, PREG_SET_ORDER) === 0) {
            return new self($da, null, 0.0);
        }

        $last = $matches[count($matches) - 1];

        return new self($da, $last[1], (float) $last[2]);
    }
}
