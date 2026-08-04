<?php

declare(strict_types=1);

namespace MightyPDF\Content\Svg;

/**
 * The tiles drawn for one SVG: the ones already drawn, so that an
 * identical one is not drawn twice, and how much drawing is left.
 *
 * Shared by the renderer and every nested renderer a pattern's content
 * starts, which is what makes both halves work across a whole drawing.
 *
 * **The cache.** A pattern is painted per *shape*, so a drawing where
 * five hundred shapes share one pattern used to draw five hundred
 * identical tiles. What a tile looks like depends on the pattern and on
 * the matrix its contents are drawn under, and on nothing else -- so
 * where those two agree, the bytes agree, and the first answer is the
 * answer.
 *
 * **The budget.** SvgRenderer already refuses to draw a pattern with
 * itself, which stops a pattern recurring forever. It does not stop a
 * pattern *chain*: where each tile holds two shapes filled with the next
 * pattern along, nothing is circular and the number of tiles doubles at
 * every link. Measured before this existed, a 2.5 KB drawing produced a
 * 127 MB document from 705 MB of memory in nine seconds, and 2.9 KB
 * exhausted a gigabyte -- an amplification of the same kind as a
 * decompression bomb, arriving through the same door (an uploaded
 * drawing) as everything else this parser is careful about.
 *
 * The two limits bound different things. The depth stops a chain early,
 * where the cost of each link is still small; the total stops breadth,
 * which a single tile holding a thousand pattern-filled shapes reaches
 * without nesting deeply at all. Only a tile that is actually drawn is
 * counted, so the five hundred shapes above spend one between them.
 *
 * A drawing that runs out paints nothing for the fills it could not
 * reach, which is how this renderer treats every paint server it cannot
 * resolve -- see SvgRenderer::applyGradient().
 */
final class SvgTileCache
{
    /**
     * Patterns nested inside patterns. Real drawings nest one deep at
     * most: a tile holding a shape painted with another pattern is
     * already unusual, and four is past anything a drawing tool emits.
     */
    private const int MAX_DEPTH = 4;

    /**
     * Distinct tiles drawn for one SVG, all told. Generous next to what
     * a drawing uses -- repeats are free, so this is a thousand tiles
     * that genuinely differ -- and far below what it takes to be a
     * problem.
     */
    private const int MAX_TILES = 1024;

    /** @var array<string, string> what a tile was drawn as, by what determines it */
    private array $drawn = [];

    private int $count = 0;

    /** The tile already drawn for $key, or null where there is none. */
    public function drawn(string $key): ?string
    {
        return $this->drawn[$key] ?? null;
    }

    public function remember(string $key, string $tile): void
    {
        $this->drawn[$key] = $tile;
    }

    /**
     * Whether a tile may be drawn at $depth patterns deep, counting it
     * against the budget if so.
     */
    public function take(int $depth): bool
    {
        if ($depth >= self::MAX_DEPTH || $this->count >= self::MAX_TILES) {
            return false;
        }

        ++$this->count;

        return true;
    }
}
