<?php

declare(strict_types=1);

namespace MightyPDF\Content\Font;

use MightyPDF\Assembler\Dictionary;

/**
 * A font bound to one document: the object a page's /Resources /Font
 * points at, and the encoder that turns text into the bytes a text-
 * showing operator takes.
 *
 * The two answers below about *how* to write those bytes exist because
 * PDF's text operators behave differently for one-byte and two-byte
 * encodings, and the difference is silent rather than fatal:
 *
 * - A two-byte string written as a literal "(...)" string works, but any
 *   byte that happens to equal "(", ")" or "\" has to be escaped, and a
 *   high byte of 0x28 is ordinary. Hex strings sidestep the whole
 *   question.
 * - Word spacing (Tw) applies only to single-byte code 32 (ISO 32000-2
 *   §9.3.3). Set it on a Type0 font and justified text is simply not
 *   justified -- no error, no warning, just ignored.
 */
interface FontWriter
{
    /** The font object itself, for the page's /Resources /Font. */
    public function dictionary(): Dictionary;

    /**
     * $utf8Text as the bytes of a text-showing operand.
     *
     * Throws where the font cannot represent a character -- see
     * EmbeddedFont::missingCharacters() for how to ask first.
     */
    public function encode(string $utf8Text): string;

    /** Whether encode()'s bytes must be written as "<...>" rather than "(...)". */
    public function usesHexStrings(): bool;

    /** Whether the Tw operator affects text drawn through this font. */
    public function supportsWordSpacing(): bool;
}
