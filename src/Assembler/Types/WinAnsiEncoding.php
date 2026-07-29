<?php

declare(strict_types=1);

namespace MightyPDF\Assembler\Types;

/**
 * Encodes a PHP (UTF-8) string into WinAnsiEncoding bytes for use in PDF
 * literal strings and content-stream text-showing operators. Lives
 * alongside PdfNumberFormat as a shared low-level encoding utility used
 * by both the content layer (drawn text) and form fields (/V, /DA) --
 * not content-drawing logic itself.
 *
 * WinAnsiEncoding is, for practical purposes, Windows-1252/CP1252 (they
 * differ only in a handful of rarely-used code points), so this defers to
 * iconv rather than hand-rolling a second code-point table. Characters
 * with no CP1252 representation are transliterated to the closest ASCII
 * approximation (e.g. curly quotes -> straight quotes) rather than
 * failing outright -- full Unicode text drawing would need font
 * embedding, which is out of scope for the standard-14-fonts v1.
 */
final class WinAnsiEncoding
{
    private function __construct()
    {
    }

    public static function encode(string $utf8Text): string
    {
        $encoded = @iconv('UTF-8', 'CP1252//TRANSLIT', $utf8Text);
        if ($encoded === false) {
            throw new \InvalidArgumentException('Text could not be encoded as WinAnsiEncoding.');
        }

        return $encoded;
    }
}
