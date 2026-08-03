<?php

declare(strict_types=1);

namespace MightyPDF\Editor\Form;

use MightyPDF\Assembler\Types\PdfString;
use MightyPDF\Assembler\Types\WinAnsiEncoding;
use MightyPDF\Content\Font\FontMetrics;
use MightyPDF\Content\Text\Utf8;

/**
 * A form font whose codes are single bytes: one of the standard 14, or
 * any font in the file with a /Widths array.
 *
 * The encoding is taken to be WinAnsi, which is what a form's /DR fonts
 * are in practice -- Acrobat writes /Helv with /WinAnsiEncoding, and a
 * field's value is typed on a keyboard rather than drawn from a
 * repertoire. A character outside it is transliterated to its nearest
 * ASCII equivalent, which is what drawn text in a standard font does
 * everywhere else in this library.
 */
final class SimpleFieldFont implements FieldFont
{
    public function __construct(private readonly FontMetrics $metrics)
    {
    }

    public function widthOfPt(string $utf8Text, float $sizePt): float
    {
        return $this->metrics->widthOf(WinAnsiEncoding::encode($utf8Text), $sizePt);
    }

    public function show(string $utf8Text): string
    {
        return PdfString::latin1(WinAnsiEncoding::encode($utf8Text))->format();
    }

    /**
     * Always: WinAnsiEncoding transliterates rather than failing, so
     * there is no character it cannot write *something* for.
     */
    public function canShow(string $utf8Text): bool
    {
        return true;
    }

    public function characters(string $utf8Text): array
    {
        return Utf8::characters($utf8Text);
    }
}
