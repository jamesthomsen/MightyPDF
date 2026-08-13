<?php

declare(strict_types=1);

namespace MightyPDF\Exception;

/**
 * a sequence of calls that cannot be right -- encrypting a document twice, finishing a layout from inside its own page hook.
 *
 * Extends the SPL exception it replaces and adds only PdfException, so
 * that existing \\LogicException catches keep working
 * unchanged. See PdfException for why the marker is an interface.
 */
final class LogicException extends \LogicException implements PdfException
{
}
