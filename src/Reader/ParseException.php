<?php

declare(strict_types=1);

namespace MightyPDF\Reader;

use MightyPDF\Exception\PdfException;

/**
 * Thrown when a byte sequence cannot be interpreted as PDF syntax.
 *
 * The reader is deliberately lenient about *recoverable* damage -- a wrong
 * /Length, a stale xref offset, stray bytes between objects -- because
 * real-world PDFs are full of it and refusing to open them would make the
 * reader useless. This exception is reserved for input that cannot be
 * interpreted at all, or for capabilities that are not implemented yet
 * (cross-reference streams, encryption), where returning something
 * plausible-looking would be far worse than stopping.
 */
final class ParseException extends \RuntimeException implements PdfException
{
    public static function at(int $offset, string $message): self
    {
        return new self(sprintf('%s (at byte offset %d).', $message, $offset));
    }
}
