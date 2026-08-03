<?php

declare(strict_types=1);

namespace MightyPDF\Content\Svg;

use MightyPDF\Assembler\Dictionary;
use MightyPDF\Assembler\Stream;
use MightyPDF\Assembler\Types\PdfArray;
use MightyPDF\Assembler\Types\PdfInteger;
use MightyPDF\Assembler\Types\PdfName;
use MightyPDF\Assembler\Types\PdfReal;

/**
 * Turns an SVG <pattern> into the PDF tiling pattern that paints it
 * (ISO 32000-2 §8.7.3): a content stream drawn once and repeated on a
 * lattice.
 *
 * The two models line up almost exactly, which is why this is much
 * shorter than SvgShadingPattern. SVG draws its pattern content in the
 * user space of the shape being filled and shows only what falls inside
 * the tile rectangle, repeating that at intervals of the tile's width
 * and height. PDF clips the cell to /BBox and repeats it at /XStep and
 * /YStep. So the tile rectangle *is* the /BBox, in the shape's own
 * coordinates, and nothing has to be translated to a tile-local origin.
 *
 * The matrix is the same difficulty as for a gradient, and has the same
 * answer: pattern space is measured from the page, not from the CTM in
 * effect, so everything the shape has been transformed by is folded in
 * here. See SvgShadingPattern's doc comment.
 */
final class SvgTilingPattern
{
    private const int PATTERN_TYPE_TILING = 1;

    /** /PaintType 1 is a coloured pattern: the cell carries its own colours. */
    private const int PAINT_TYPE_COLOURED = 1;

    /**
     * /TilingType 1, constant spacing: a reader may nudge a cell by up to
     * a device pixel to keep the lattice even, which is what an SVG
     * pattern means -- tiles at exact intervals of width and height.
     * Type 2 keeps each cell undistorted and lets the spacing drift
     * instead, which is the other trade and not the one SVG describes.
     * (Neither changes poppler's hairline seams between tiles that meet
     * exactly; those are its rasterisation, not the document.)
     */
    private const int TILING_TYPE_CONSTANT_SPACING = 1;

    private function __construct()
    {
    }

    /**
     * @param string $content the pattern's drawing, as content-stream
     *        operators in the shape's user space
     * @param Dictionary $resources what those operators name
     * @param array{0: float, 1: float, 2: float, 3: float, 4: float, 5: float} $transform
     *        what the shape being painted has been transformed by, from
     *        its own coordinates to the page
     * @param array{0: float, 1: float, 2: float, 3: float} $boundingBox
     *        the shape's box in its own coordinates, used only by a
     *        pattern measured in objectBoundingBox units
     */
    public static function build(
        int $objectId,
        SvgPattern $pattern,
        string $content,
        Dictionary $resources,
        array $transform,
        array $boundingBox,
    ): Stream {
        [$x, $y, $width, $height] = $pattern->tile($boundingBox);

        $stream = new Stream($objectId, $content);
        $stream->set('Type', new PdfName('Pattern'));
        $stream->set('PatternType', new PdfInteger(self::PATTERN_TYPE_TILING));
        $stream->set('PaintType', new PdfInteger(self::PAINT_TYPE_COLOURED));
        $stream->set('TilingType', new PdfInteger(self::TILING_TYPE_CONSTANT_SPACING));
        $stream->set('BBox', self::numbers([$x, $y, $x + $width, $y + $height]));

        // Positive, always: the tile is a rectangle in a coordinate
        // system whose y runs downwards, and a negative step is a
        // lattice a reader draws once and then stops.
        $stream->set('XStep', new PdfReal($width));
        $stream->set('YStep', new PdfReal($height));
        $stream->set('Resources', $resources);
        $stream->set('Matrix', self::numbers(self::matrix($pattern, $transform)));

        return $stream;
    }

    /**
     * @param array{0: float, 1: float, 2: float, 3: float, 4: float, 5: float} $transform
     * @return array{0: float, 1: float, 2: float, 3: float, 4: float, 5: float}
     */
    private static function matrix(SvgPattern $pattern, array $transform): array
    {
        return SvgTransform::compose($pattern->transform ?? SvgTransform::IDENTITY, $transform);
    }

    /** @param array<int, float> $values */
    private static function numbers(array $values): PdfArray
    {
        return new PdfArray(...array_map(
            static fn (float $value): PdfReal => new PdfReal($value),
            array_values($values),
        ));
    }
}
