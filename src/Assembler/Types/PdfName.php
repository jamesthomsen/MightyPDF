<?php

declare(strict_types=1);

namespace MightyPDF\Assembler\Types;

/**
 * A PDF name object (ISO 32000-2 §7.3.5), e.g. /Catalog, /F1.
 *
 * Any byte outside the printable, non-delimiter ASCII range -- and the
 * NUMBER SIGN itself, since it introduces an escape -- must be written as
 * "#" followed by two hex digits. The original 2012 implementation's
 * escape table used PHP string literals like '\x00' (four literal
 * characters: backslash, x, 0, 0), which never matches an actual control
 * byte in a single-quoted PHP string, so that escaping never fired. This
 * implementation walks the raw bytes directly instead.
 */
final class PdfName implements PdfValue
{
    private const array DELIMITERS = ['(', ')', '<', '>', '[', ']', '{', '}', '/', '%'];

    public function __construct(private readonly string $value)
    {
    }

    public function value(): string
    {
        return $this->value;
    }

    public function format(): string
    {
        $out = '/';
        $length = strlen($this->value);

        for ($i = 0; $i < $length; ++$i) {
            $byte = $this->value[$i];
            $ord = ord($byte);
            $isRegular = $ord >= 0x21 && $ord <= 0x7E && $byte !== '#' && !in_array($byte, self::DELIMITERS, true);

            $out .= $isRegular ? $byte : '#' . strtoupper(str_pad(dechex($ord), 2, '0', STR_PAD_LEFT));
        }

        return $out;
    }
}
