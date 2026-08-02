<?php

declare(strict_types=1);

namespace MightyPDF\Content\Font;

use MightyPDF\Assembler\DocumentContext;

/**
 * A font something can be drawn with: one of the standard 14 (see
 * StandardFont) or a TrueType file embedded in the document (see
 * EmbeddedFont).
 *
 * The interface splits along the one line that matters, which is what
 * depends on the document being written:
 *
 * - Measuring does not. How wide "Hello" is at 12pt is a property of the
 *   font file, so TextWrapper can lay out a paragraph -- or a caller can
 *   size a box to its text -- with no document in hand at all.
 * - Encoding does. Which bytes stand for "H" in the content stream
 *   depends on which glyphs *this document* turned out to use, because
 *   an embedded font is subset and renumbered. That is what writerFor()
 *   returns: the font as it exists inside one document.
 */
interface Font
{
    /**
     * Identity for the document's font cache -- two Font values with the
     * same key must be interchangeable, since the second one drawn with
     * will reuse the first one's font object.
     */
    public function cacheKey(): string;

    /** The width of $utf8Text, in points, when drawn at $sizePt. */
    public function widthOfPt(string $utf8Text, float $sizePt): float;

    /**
     * How far the font rises above the baseline at $sizePt -- what a
     * caller placing the first line of text inside a box needs, since
     * the y it draws at is a baseline and the box has a top edge.
     */
    public function ascentPt(float $sizePt): float;

    /**
     * This font as it exists in $document, creating and registering its
     * PDF objects on first use and reusing them after that.
     */
    public function writerFor(DocumentContext $document): FontWriter;
}
