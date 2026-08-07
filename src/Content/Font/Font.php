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

    /** Whether this font can draw every character of $utf8Text as itself. */
    public function supports(string $utf8Text): bool;

    /**
     * The characters of $utf8Text this font cannot draw as themselves,
     * without duplicates and in the order they appear.
     *
     * What that costs depends on the kind of font, which is the reason
     * this is asked of the interface rather than of one implementation:
     * an embedded font refuses to draw at all, a standard font
     * transliterates or substitutes. Either way a caller holding a Font
     * it did not choose can find out before drawing instead of after.
     *
     * @return list<string>
     */
    public function missingCharacters(string $utf8Text): array;

    /**
     * How far the font rises above the baseline at $sizePt -- what a
     * caller placing the first line of text inside a box needs, since
     * the y it draws at is a baseline and the box has a top edge.
     */
    public function ascentPt(float $sizePt): float;

    /**
     * How far it drops below the baseline at $sizePt, as a *positive*
     * distance -- the opposite sign to AFM's Descender and the PDF
     * descriptor's /Descent, both of which are negative.
     *
     * The sign is chosen for the formulas that use it: everything that
     * places text vertically wants ascent + descent, and writing that as
     * ascent - descent invites a slip that is a fraction of a point at
     * 10pt and centimetres at poster sizes. See TextPlacement, which is
     * where those formulas live so that no caller has to restate them.
     */
    public function descentPt(float $sizePt): float;

    /**
     * The height of a capital letter at $sizePt, baseline to cap.
     *
     * Separate from the ascent because they answer different questions:
     * the ascent bounds the em box and centres running prose, while cap
     * height bounds what is actually inked in a label, a table heading
     * or a single large letter, and centres those. The difference is
     * proportional to the type size, so choosing wrong is invisible in
     * body copy and unmissable in display sizes.
     */
    public function capHeightPt(float $sizePt): float;

    /**
     * This font as it exists in $document, creating and registering its
     * PDF objects on first use and reusing them after that.
     */
    public function writerFor(DocumentContext $document): FontWriter;
}
