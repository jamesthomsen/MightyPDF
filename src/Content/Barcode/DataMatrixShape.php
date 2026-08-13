<?php

declare(strict_types=1);

namespace MightyPDF\Content\Barcode;

/**
 * Whether a Data Matrix should be square or rectangular.
 *
 * A choice rather than an optimisation, which is why it is not "use a
 * rectangle if it comes out smaller". A rectangle almost never does: the
 * six rectangular sizes hold no more per module than the squares, and for
 * most payloads the smallest square is the same area or better. They exist
 * because the *thing being marked* is long and thin -- a cable, a vial, a
 * surgical instrument, the edge of a circuit board -- and the symbol has
 * to fit that, not the other way round.
 *
 * So a caller asking for a rectangle gets one, and a caller who has not
 * asked gets the square that every scanner, every label template and every
 * person expects.
 */
enum DataMatrixShape
{
    /** One of the 23 square sizes. The default. */
    case Square;

    /**
     * One of the six rectangles (8x18 through 16x48). Refused if the data
     * does not fit the largest of them -- falling back to a square would
     * defeat the point of having asked.
     */
    case Rectangular;
}
