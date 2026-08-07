<?php

declare(strict_types=1);

namespace MightyPDF\Tests\Assembler\Types;

use MightyPDF\Assembler\Types\PdfRectangle;
use PHPUnit\Framework\TestCase;

final class PdfRectangleTest extends TestCase
{
    public function testFormatsIntegerAlignedCoordinates(): void
    {
        self::assertSame('[0 0 612 792]', (new PdfRectangle(0, 0, 612, 792))->format());
    }

    public function testFormatsFractionalCoordinates(): void
    {
        // Unlike the 2012 implementation (which forced everything through
        // an Integer type), fractional coordinates must round-trip
        // exactly -- needed for precise text/form-field placement.
        self::assertSame('[10.5 20.25 100.5 200.75]', (new PdfRectangle(10.5, 20.25, 100.5, 200.75))->format());
    }
    /**
     * A rectangle written with its corners the other way round is the
     * same rectangle -- §7.9.5 says so and readers normalize it -- so
     * code that reads the corners rather than the extent has to as well.
     */
    public function testNormalizedPutsTheCornersTheWayRoundAReaderExpects(): void
    {
        $normalized = (new PdfRectangle(595.28, 841.89, 0.0, 0.0))->normalized();

        self::assertSame(0.0, $normalized->x1);
        self::assertSame(0.0, $normalized->y1);
        self::assertSame(595.28, $normalized->x2);
        self::assertSame(841.89, $normalized->y2);
    }

    public function testNormalizingAnUprightRectangleChangesNothing(): void
    {
        $upright = new PdfRectangle(10.0, 20.0, 100.0, 200.0);

        self::assertSame($upright->format(), $upright->normalized()->format());
    }

    public function testWidthAndHeightWereAlreadyAbsolute(): void
    {
        $inverted = new PdfRectangle(595.28, 841.89, 0.0, 0.0);

        self::assertSame(595.28, $inverted->width());
        self::assertSame(841.89, $inverted->height());
    }
}
