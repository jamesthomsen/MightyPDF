<?php

declare(strict_types=1);

namespace MightyPDF\Content\Svg;

/**
 * A resolved <pattern>: the tile it repeats, and the drawing that fills
 * one tile.
 *
 * Unlike a gradient, a pattern's paint is not a formula but a piece of
 * the document -- its children are ordinary SVG elements, drawn by the
 * same renderer that drew the shape referring to them. This holds what
 * is needed to place them; SvgTilingPattern turns it into PDF.
 */
final class SvgPattern
{
    /**
     * @param float $x tile origin, in whichever units $userSpace says
     * @param array{0: float, 1: float, 2: float, 3: float}|null $viewBox
     *        content coordinates to be fitted to the tile, if the pattern
     *        declares any
     * @param array{0: float, 1: float, 2: float, 3: float, 4: float, 5: float}|null $transform
     *        patternTransform, already multiplied out
     * @param \SimpleXMLElement $content the element whose children are
     *        drawn -- not necessarily this pattern's own element, since a
     *        pattern with no children of its own borrows the content of
     *        the one it references
     */
    public function __construct(
        public readonly float $x,
        public readonly float $y,
        public readonly float $width,
        public readonly float $height,
        public readonly bool $userSpace,
        public readonly bool $contentInUserSpace,
        public readonly ?array $viewBox,
        public readonly string $aspectRatio,
        public readonly ?array $transform,
        public readonly \SimpleXMLElement $content,
    ) {
    }

    /**
     * Whether this pattern can paint the shape whose box is $boundingBox.
     *
     * A tile with no area repeats nothing, and one measured in a box with
     * no area has no size to be given. The SVG spec's answer to both is
     * that the element is not rendered, which is also the only answer
     * that avoids dividing by zero.
     *
     * @param array{0: float, 1: float, 2: float, 3: float} $boundingBox
     */
    public function canPaint(array $boundingBox): bool
    {
        if ($this->width <= 0.0 || $this->height <= 0.0) {
            return false;
        }

        return $this->userSpace || ($boundingBox[2] > 0.0 && $boundingBox[3] > 0.0);
    }

    /**
     * The tile rectangle in the user space of the shape being painted,
     * as x, y, width, height.
     *
     * @param array{0: float, 1: float, 2: float, 3: float} $boundingBox
     * @return array{0: float, 1: float, 2: float, 3: float}
     */
    public function tile(array $boundingBox): array
    {
        if ($this->userSpace) {
            return [$this->x, $this->y, $this->width, $this->height];
        }

        [$boxX, $boxY, $boxWidth, $boxHeight] = $boundingBox;

        return [
            $boxX + $this->x * $boxWidth,
            $boxY + $this->y * $boxHeight,
            $this->width * $boxWidth,
            $this->height * $boxHeight,
        ];
    }

    /**
     * What the tile's contents are drawn under: the viewBox fitted to the
     * tile, or the bounding box for content in objectBoundingBox units,
     * or nothing at all -- the ordinary case, where the content is drawn
     * in the same user space as the shape it fills.
     *
     * @param array{0: float, 1: float, 2: float, 3: float} $boundingBox
     * @return array{0: float, 1: float, 2: float, 3: float, 4: float, 5: float}|null
     */
    public function contentMatrix(array $boundingBox): ?array
    {
        [$tileX, $tileY, $tileWidth, $tileHeight] = $this->tile($boundingBox);

        if ($this->viewBox !== null) {
            // A viewBox makes the tile a viewport of its own, exactly as
            // it does on <svg> -- which is what SvgAspectRatio is for.
            return SvgAspectRatio::fit($this->aspectRatio, $this->viewBox, [$tileX, $tileY, $tileWidth, $tileHeight]);
        }

        if ($this->contentInUserSpace) {
            return null;
        }

        // patternContentUnits="objectBoundingBox": the content is drawn
        // in the shape's box as a unit square, wherever the tile happens
        // to sit.
        return [$boundingBox[2], 0.0, 0.0, $boundingBox[3], $boundingBox[0], $boundingBox[1]];
    }
}
