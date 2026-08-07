<?php

declare(strict_types=1);

namespace MightyPDF\Assembler;

use MightyPDF\Assembler\Types\PdfRectangle;

/**
 * The standard paper sizes, in PDF points (1/72 inch).
 *
 * Here rather than in the layout layer because Document::newPage() is
 * what consumes them, and a caller drawing straight onto a page has the
 * same problem as one using the layout layer: A4 is 595.28 x 841.89pt,
 * which is a number nobody remembers and everybody copies -- out of a
 * README, into a constructor call, once per project. Copied numbers are
 * fine until one of them is transposed.
 *
 * The ISO A series is defined in millimetres, so the point values are
 * derived from those rather than transcribed: 210mm x 297mm at 72/25.4
 * points per millimetre. The US sizes are defined in inches and are
 * exact.
 */
enum PageSize
{
    case A3;
    case A4;
    case A5;
    case Letter;
    case Legal;
    case Tabloid;

    /** @return array{float, float} width and height in millimetres */
    private function millimetres(): array
    {
        return match ($this) {
            self::A3 => [297.0, 420.0],
            self::A4 => [210.0, 297.0],
            self::A5 => [148.0, 210.0],
            self::Letter => [215.9, 279.4],
            self::Legal => [215.9, 355.6],
            self::Tabloid => [279.4, 431.8],
        };
    }

    public function widthPt(): float
    {
        return round($this->millimetres()[0] * 72.0 / 25.4, 2);
    }

    public function heightPt(): float
    {
        return round($this->millimetres()[1] * 72.0 / 25.4, 2);
    }

    /**
     * Rounded to the hundredth of a point, which is what makes A4 come
     * out as the 595.28 x 841.89 every other tool writes rather than a
     * full-precision 595.2755905511811. The difference is a five
     * thousandth of a millimetre; writing the familiar number means a
     * document produced here diffs cleanly against one that was not.
     */
    public function mediaBox(): PdfRectangle
    {
        return new PdfRectangle(0, 0, $this->widthPt(), $this->heightPt());
    }

    /** The same sheet turned on its side. */
    public function landscape(): PdfRectangle
    {
        return new PdfRectangle(0, 0, $this->heightPt(), $this->widthPt());
    }
}
