<?php

declare(strict_types=1);

namespace MightyPDF\Editor\Form;

/**
 * Thrown when a form cannot be filled as asked.
 *
 * Form filling fails loudly on purpose. Every failure mode here -- a name
 * that is not in the document, a checkbox state the widget has no
 * appearance for, a value longer than /MaxLen -- produces a PDF that
 * opens perfectly and is simply missing or wrong in the one place anyone
 * will look. There is no useful "best effort" for that, so the library
 * refuses rather than writing a file the caller will trust.
 */
final class FormException extends \RuntimeException
{
}
