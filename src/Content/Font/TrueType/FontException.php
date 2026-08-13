<?php

declare(strict_types=1);

namespace MightyPDF\Content\Font\TrueType;

use MightyPDF\Exception\PdfException;

/**
 * A font file that cannot be used: not a font at all, truncated,
 * internally inconsistent, or in a format this library does not embed
 * (see TrueTypeFile for which those are).
 *
 * Its own type rather than a bare RuntimeException so that "the font you
 * gave me is unusable" can be caught separately from anything that goes
 * wrong while writing the document -- a caller offering a font picker
 * wants to report the first to the person choosing, and not the second.
 */
final class FontException extends \RuntimeException implements PdfException
{
}
