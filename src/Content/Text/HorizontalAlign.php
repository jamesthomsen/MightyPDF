<?php

declare(strict_types=1);

namespace MightyPDF\Content\Text;

/**
 * Where text sits horizontally in the box it is drawn in.
 *
 * The string forms PageBuilder::drawParagraph() has always taken ('L',
 * 'C', 'R', 'J') still work and mean the same thing -- see fromLegacy().
 * They are kept because a stringly-typed argument that has been in the
 * README since the first release is not worth breaking, and widened to
 * this because 'M' for a *vertical* middle next to 'C' for a horizontal
 * centre is the kind of near-miss that only ever produces silent
 * misalignment.
 */
enum HorizontalAlign
{
    case Left;
    case Center;
    case Right;
    case Justify;

    /**
     * Justification stretches the spaces between words, so a single line
     * -- or the last line of a paragraph -- has nothing to stretch
     * towards and is simply set flush left. Callers that lay out one
     * line at a time therefore treat this as Left.
     */
    public function forSingleLine(): self
    {
        return $this === self::Justify ? self::Left : $this;
    }

    public static function fromLegacy(string $align): self
    {
        return match (strtoupper($align)) {
            'C' => self::Center,
            'R' => self::Right,
            'J' => self::Justify,
            default => self::Left,
        };
    }
}
