<?php

declare(strict_types=1);

namespace MightyPDF\Assembler\Types;

/**
 * A PDF literal string, "(...)" (ISO 32000-2 §7.3.4.2).
 *
 * The original 2012 implementation's escape table had the same class of
 * bug as the old PdfName: it searched for PHP string literals like
 * '\x08'/'\xFF' (literal multi-character text) instead of the actual
 * control bytes, so the escaping never matched anything real. This
 * implementation uses real double-quoted escape sequences as the search
 * targets so they match actual bytes in the string.
 *
 * PDF literal strings are conventionally either PDFDocEncoding/Latin-1
 * (fine for text drawn with the standard 14 fonts) or UTF-16BE with a
 * byte-order mark (needed for arbitrary Unicode, e.g. form field values).
 * The two named constructors make that choice explicit at the call site
 * rather than silently assuming one encoding.
 */
final class PdfString implements PdfValue
{
    private function __construct(private readonly string $bytes)
    {
    }

    public static function latin1(string $text): self
    {
        return new self($text);
    }

    public static function utf16be(string $text): self
    {
        $encoded = iconv('UTF-8', 'UTF-16BE', $text);
        if ($encoded === false) {
            throw new \InvalidArgumentException('Text is not valid UTF-8, cannot convert to UTF-16BE.');
        }

        return new self("\xFE\xFF" . $encoded);
    }

    public function format(): string
    {
        return '(' . self::escape($this->bytes) . ')';
    }

    private static function escape(string $bytes): string
    {
        // Backslash must be escaped first -- every other replacement below
        // introduces new backslashes, and str_replace does not rescan
        // replacement text, so processing backslash first (and only once)
        // is what keeps this correct.
        $find    = ["\\", "(", ")", "\r", "\n", "\t", "\x08", "\x0C"];
        $replace = ["\\\\", "\\(", "\\)", "\\r", "\\n", "\\t", "\\b", "\\f"];

        return str_replace($find, $replace, $bytes);
    }
}
