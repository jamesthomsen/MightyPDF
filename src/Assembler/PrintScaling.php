<?php

declare(strict_types=1);

namespace MightyPDF\Assembler;

/**
 * Whether a reader may resize the page to fit the printer's imageable
 * area (ISO 32000-2 §12.2, /PrintScaling).
 *
 * AppDefault -- which is what every reader does when the document says
 * nothing -- shrinks the page by a few percent to clear the printer's
 * unprintable margin. That is right for a document meant to be read and
 * wrong for anything measured: a form that has to line up with a
 * pre-printed one, a drawing at a stated scale, a sheet of labels, a
 * barcode whose module width was chosen for a scanner.
 */
enum PrintScaling: string
{
    /** Print at 100%, unscaled. */
    case None = 'None';

    /** Let the reader do whatever it normally does. */
    case AppDefault = 'AppDefault';
}
