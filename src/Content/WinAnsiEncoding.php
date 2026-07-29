<?php

declare(strict_types=1);

namespace MightyPDF\Content;

/**
 * Encodes a PHP (UTF-8) string into WinAnsiEncoding bytes for use in a
 * PDF content stream's text-showing operators.
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
