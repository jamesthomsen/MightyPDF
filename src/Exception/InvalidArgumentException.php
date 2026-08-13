<?php

declare(strict_types=1);

namespace MightyPDF\Exception;

/**
 * an argument that was already wrong when it was passed -- a negative width, a colour outside 0..1, a font that cannot draw the text asked of it.
 *
 * Extends the SPL exception it replaces and adds only PdfException, so
 * that existing \\InvalidArgumentException catches keep working
 * unchanged. See PdfException for why the marker is an interface.
 */
final class InvalidArgumentException extends \InvalidArgumentException implements PdfException
{
}
