<?php

declare(strict_types=1);

namespace MightyPDF\Content\Font;

use MightyPDF\Assembler\Dictionary;
use MightyPDF\Assembler\Types\WinAnsiEncoding;

/**
 * One of the standard 14 fonts, bound to a document.
 *
 * There is nothing document-dependent about a standard font -- its
 * dictionary names a font every reader already has -- so this is a thin
 * pairing of that dictionary with WinAnsi encoding. It exists so that
 * PageBuilder can draw through one interface and never ask which kind of
 * font it was handed.
 */
final class StandardFontWriter implements FontWriter
{
    public function __construct(private readonly Dictionary $dictionary)
    {
    }

    public function dictionary(): Dictionary
    {
        return $this->dictionary;
    }

    public function encode(string $utf8Text): string
    {
        return WinAnsiEncoding::encode($utf8Text);
    }

    public function usesHexStrings(): bool
    {
        return false;
    }

    public function supportsWordSpacing(): bool
    {
        return true;
    }
}
