<?php

declare(strict_types=1);

namespace MightyPDF\Reader\Text;

/**
 * One run of text as the page draws it, with where it sits.
 *
 * A fragment is what one show-text operator produced, which is not a word,
 * a line or a sentence -- it is however much text the producer happened to
 * emit in one go. Some writers emit a line at a time, some a word, some a
 * character with its own position because they are kerning by hand. Any
 * meaning above that (a line, a paragraph, reading order) is reconstructed
 * from the geometry, which is what PageText::text() does.
 *
 * Coordinates are the page's own: points, origin at the bottom-left, y
 * running up. ($x, $y) is the start of the baseline, so $y is where the
 * letters sit rather than the bottom of the box around them.
 */
final readonly class TextFragment
{
    public function __construct(
        public string $text,
        public float $x,
        public float $y,
        public float $width,
        public float $fontSize,
    ) {
    }

    /** The x the next character would start at. */
    public function endX(): float
    {
        return $this->x + $this->width;
    }
}
