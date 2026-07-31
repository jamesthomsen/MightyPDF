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

    /**
     * Wraps bytes that are already in their final on-the-wire form, with
     * no encoding decision of any kind -- for strings read back out of an
     * existing PDF (see MightyPDF\Reader\ObjectParser).
     *
     * Byte-identical to latin1() and deliberately separate from it: a
     * string parsed out of a file may be PDFDocEncoding *or* UTF-16BE, and
     * which one is a property of those bytes, not something the reader
     * gets to choose. Calling latin1() there would read as an assertion
     * about the encoding that the reader is in no position to make.
     */
    public static function raw(string $bytes): self
    {
        return new self($bytes);
    }

    /**
     * The right choice for any string whose content comes from the caller
     * rather than from this library: field names, field values, document
     * metadata -- i.e. PDF's "text string" type (§7.9.2.2), which is
     * defined as *either* PDFDocEncoding or UTF-16BE-with-BOM, chosen per
     * string.
     *
     * Pure ASCII goes out as-is, since it is identical in both encodings
     * and stays readable in the raw file. Anything else goes out as
     * UTF-16BE, which represents all of Unicode losslessly. Squeezing
     * these into a single-byte encoding instead is silent data loss: a
     * Cyrillic field value transliterates to "??????", and a name written
     * as raw UTF-8 bytes in a Latin-1 string reads back as mojibake --
     * which matters most for /T, since that is the key form-filling code
     * looks a field up by.
     *
     * Escaping stays correct either way: escape() works on bytes, and
     * every escape it emits is byte-reversible, so a UTF-16BE code unit
     * that happens to contain 0x28 ("(") round-trips intact.
     */
    public static function text(string $text): self
    {
        if (preg_match('/^[\x20-\x7E\t\r\n]*$/', $text) === 1) {
            return new self($text);
        }

        return self::utf16be($text);
    }

    public static function utf16be(string $text): self
    {
        $encoded = iconv('UTF-8', 'UTF-16BE', $text);
        if ($encoded === false) {
            throw new \InvalidArgumentException('Text is not valid UTF-8, cannot convert to UTF-16BE.');
        }

        return new self("\xFE\xFF" . $encoded);
    }

    /** The bytes as stored, with no interpretation. */
    public function bytes(): string
    {
        return $this->bytes;
    }

    public function toUtf8(): string
    {
        return self::decode($this->bytes);
    }

    /**
     * Reads a PDF "text string" (§7.9.2.2) back as UTF-8 -- the inverse of
     * text(), and the only way to get a form field's name or value back
     * out of a file as usable text.
     *
     * The encoding is self-describing only in the UTF-16BE case, via its
     * byte-order mark. Everything else is nominally PDFDocEncoding, which
     * agrees with Latin-1 across the printable range; but files written by
     * tools that simply emitted UTF-8 and hoped are common enough that
     * already-valid UTF-8 is passed through rather than being mangled a
     * second time by a Latin-1 conversion that would turn "café" into
     * "cafÃ©".
     */
    public static function decode(string $bytes): string
    {
        if (str_starts_with($bytes, "\xFE\xFF")) {
            $utf8 = iconv('UTF-16BE', 'UTF-8', substr($bytes, 2));

            return $utf8 === false ? '' : $utf8;
        }

        // Not in the spec, but written by tools that conflated "text
        // string" with "UTF-8 string".
        if (str_starts_with($bytes, "\xEF\xBB\xBF")) {
            return substr($bytes, 3);
        }

        if (preg_match('//u', $bytes) === 1) {
            return $bytes;
        }

        $utf8 = iconv('ISO-8859-1', 'UTF-8', $bytes);

        return $utf8 === false ? $bytes : $utf8;
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
