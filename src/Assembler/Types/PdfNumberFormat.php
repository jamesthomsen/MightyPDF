<?php

declare(strict_types=1);

namespace MightyPDF\Assembler\Types;

use MightyPDF\Exception\InvalidArgumentException;

/**
 * Shared float-to-PDF-numeric-literal formatting, used by PdfReal and by
 * the content layer's operator formatting. PDF real numbers must never use
 * scientific notation (ISO 32000-2 §7.3.3) and readers vary in how much
 * precision they tolerate, so this always emits a fixed-point literal with
 * trailing zeros/decimal point trimmed.
 *
 * number_format() is used with explicit '.'/'' separators specifically so
 * output never depends on the process's LC_NUMERIC locale.
 */
final class PdfNumberFormat
{
    private function __construct()
    {
    }

    public static function format(float $value): string
    {
        if (!is_finite($value)) {
            $description = is_nan($value) ? 'NAN' : ($value > 0 ? 'INF' : '-INF');
            throw new InvalidArgumentException("Cannot format non-finite number in a PDF: $description");
        }

        $formatted = number_format($value, 6, '.', '');
        if (str_contains($formatted, '.')) {
            $formatted = rtrim(rtrim($formatted, '0'), '.');
        }

        if ($formatted === '' || $formatted === '-') {
            $formatted = '0';
        }

        if ($formatted === '-0') {
            $formatted = '0';
        }

        return $formatted;
    }
}
