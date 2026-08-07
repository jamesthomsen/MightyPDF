<?php

declare(strict_types=1);

namespace MightyPDF\Assembler;

/**
 * How the document asks to be printed on both sides (ISO 32000-2 §12.2,
 * /Duplex).
 *
 * The two double-sided cases differ in which edge the sheet turns on, and
 * getting it wrong prints every other page upside down: long edge is what
 * a portrait document wants, short edge what a landscape one does.
 */
enum Duplex: string
{
    case Simplex = 'Simplex';

    /** Turned on the long edge -- the right one for portrait pages. */
    case DuplexFlipLongEdge = 'DuplexFlipLongEdge';

    /** Turned on the short edge -- the right one for landscape pages. */
    case DuplexFlipShortEdge = 'DuplexFlipShortEdge';
}
