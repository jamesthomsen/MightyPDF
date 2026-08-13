<?php

declare(strict_types=1);

namespace MightyPDF\Content\Svg;

/**
 * Draws one tile of a <pattern> and reports the operators that fill it.
 *
 * This exists to break a cycle rather than to allow more than one
 * implementation, and SvgRenderer is the only one there is. Painting
 * with a pattern needs a tile; drawing a tile means rendering the
 * pattern's children, which are ordinary elements needing the whole
 * renderer. Naming the one thing SvgPaintServers wants back from the
 * renderer keeps that dependency to a single method instead of a mutual
 * reference between two classes that each do everything.
 */
interface SvgTileSource
{
    /**
     * @param array{0: float, 1: float, 2: float, 3: float, 4: float, 5: float}|null $contentMatrix
     * @return ?string null where the tile must not be drawn -- a pattern
     *         painted with itself, or a drawing that has spent its
     *         budget
     */
    public function tileFor(string $reference, SvgPattern $pattern, ?array $contentMatrix): ?string;
}
