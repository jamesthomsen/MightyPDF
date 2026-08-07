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

    public function __construct(
        public readonly float $x1,
        public readonly float $y1,
        public readonly float $x2,
        public readonly float $y2,
    ) {
        $this->array = new PdfArray(new PdfReal($x1), new PdfReal($y1), new PdfReal($x2), new PdfReal($y2));
    }

    /**
     * Absolute, since a rectangle written with its corners the other way
     * round is still that rectangle -- §7.9.5 says as much, and a reader
     * normalizes it. A caller measuring a page it was handed should get
     * the size either way.
     */
    public function width(): float
    {
        return abs($this->x2 - $this->x1);
    }

    public function height(): float
    {
        return abs($this->y2 - $this->y1);
    }

    /**
     * The same rectangle with its corners the way round §7.9.5 says a
     * reader will normalize them to anyway: (x1, y1) the lower-left,
     * (x2, y2) the upper-right.
     *
     * For code that reads the corners rather than the extent. width()
     * and height() are absolute and so do not care, but anything asking
     * where the left edge *is* has to pick one of x1 and x2, and picking
     * x1 puts a document laid out on an inverted media box off the page
     * -- silently, because the /MediaBox it writes is still correct and
     * every reader still shows the right sheet.
     */
    public function normalized(): self
    {
        return new self(
            min($this->x1, $this->x2),
            min($this->y1, $this->y2),
            max($this->x1, $this->x2),
            max($this->y1, $this->y2),
        );
    }

    public function format(): string
    {
        return $this->array->format();
    }
}
