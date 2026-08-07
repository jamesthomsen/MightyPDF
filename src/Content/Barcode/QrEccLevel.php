<?php

declare(strict_types=1);

namespace MightyPDF\Content\Barcode;

/**
 * How much of a QR code can be destroyed and still read.
 *
 * The trade is against capacity: at High, roughly a third of the symbol
 * is check data, so the same message needs a bigger code. Medium is the
 * usual choice and the default here -- it recovers about 15% and is what
 * most printed codes use.
 *
 * Reach for Quartile or High when the code will be printed small, on
 * something that creases, or where a logo will be placed over the middle.
 * Reach for Low only when the code is on a screen and the capacity is
 * genuinely needed.
 *
 * The values are the two-bit codes written into the symbol's format
 * information, which are deliberately *not* in ascending order of
 * strength -- M is 0 and L is 1.
 */
enum QrEccLevel: int
{
    /** About 7% recoverable. */
    case Low = 1;

    /** About 15%. The default. */
    case Medium = 0;

    /** About 25%. */
    case Quartile = 3;

    /** About 30%. */
    case High = 2;
}
