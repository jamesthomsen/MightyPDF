<?php

declare(strict_types=1);

namespace MightyPDF\Assembler\Types;

/**
 * A PDF rectangle, [x1 y1 x2 y2] (ISO 32000-2 §7.9.5).
 *
 * Composes a PdfArray rather than extending it (PdfArray is final/readonly)
 * -- coordinates are always emitted as PdfReal so fractional placement
 * (form field rects, precise text positioning) works, unlike the 2012
 * implementation which forced everything through PdfInteger.
 */
final class PdfRectangle implements PdfValue
{
    private readonly PdfArray $array;

    public function __construct(float $x1, float $y1, float $x2, float $y2)
    {
        $this->array = new PdfArray(new PdfReal($x1), new PdfReal($y1), new PdfReal($x2), new PdfReal($y2));
    }

    public function format(): string
    {
        return $this->array->format();
    }
}
