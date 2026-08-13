<?php

declare(strict_types=1);

namespace MightyPDF\Exception;

/**
 * something that was fine to ask for and failed anyway -- a file that would not open, a stream that would not deflate.
 *
 * Extends the SPL exception it replaces and adds only PdfException, so
 * that existing \\RuntimeException catches keep working
 * unchanged. See PdfException for why the marker is an interface.
 */
final class RuntimeException extends \RuntimeException implements PdfException
{
}
