<?php

declare(strict_types=1);

namespace MightyPDF\Content\Svg;

/**
 * Works out where an image goes inside the rectangle an `<image>`
 * element gives it -- SVG's preserveAspectRatio (SVG 1.1 §7.8).
 *
 * The attribute reads like "xMidYMid meet": an alignment, and then
 * whether the image is scaled to fit inside the rectangle (meet, the
 * default) or to cover it (slice). "none" stretches it to the rectangle
 * regardless of its shape.
 *
 * The result is a matrix in SVG's own coordinates, which is where the
 * flip lives: PDF draws an image from the bottom up, SVG places one from
 * the top down, and the whole drawing is already under a y-flip from
 * being put on the page. Rather than reason about all three, the matrix
 * below maps the image's unit square so the image's *first row* lands at
 * the top edge of the rectangle in SVG coordinates, which the placement
 * flip then turns the right way up.
 */
final class SvgAspectRatio
{
    private function __construct()
    {
    }

    /**
     * @return array{matrix: array{0: float, 1: float, 2: float, 3: float, 4: float, 5: float}, clip: bool}
     *         clip is true where the image is larger than its rectangle
     *         and has to be cut to it
     */
    public static function place(
        ?string $specification,
        float $x,
        float $y,
        float $width,
        float $height,
        int $imageWidth,
        int $imageHeight,
    ): array {
        $specification = trim($specification ?? '') ?: 'xMidYMid meet';
        $slice = str_contains($specification, 'slice');
        $alignment = strtok($specification, " \t\r\n") ?: 'xMidYMid';

        if (strtolower($alignment) === 'none' || $imageWidth <= 0 || $imageHeight <= 0) {
            return ['matrix' => self::matrix($x, $y, $width, $height), 'clip' => false];
        }

        // meet fits the image inside the box, slice covers the box with
        // it -- the difference is a min against a max.
        $scale = $slice
            ? max($width / $imageWidth, $height / $imageHeight)
            : min($width / $imageWidth, $height / $imageHeight);

        $scaledWidth = $imageWidth * $scale;
        $scaledHeight = $imageHeight * $scale;

        return [
            'matrix' => self::matrix(
                $x + self::offset($alignment, 'x', $width - $scaledWidth),
                $y + self::offset($alignment, 'y', $height - $scaledHeight),
                $scaledWidth,
                $scaledHeight,
            ),
            'clip' => $slice && ($scaledWidth > $width + 1e-9 || $scaledHeight > $height + 1e-9),
        ];
    }

    /**
     * How far along the leftover space the image sits: not at all for
     * Min, half of it for Mid, all of it for Max.
     */
    private static function offset(string $alignment, string $axis, float $slack): float
    {
        $prefix = $axis === 'x' ? 'x' : 'Y';

        if (str_contains($alignment, $prefix . 'Max')) {
            return $slack;
        }

        if (str_contains($alignment, $prefix . 'Min')) {
            return 0.0;
        }

        return $slack / 2;
    }

    /**
     * The image's unit square mapped onto (x, y, width, height) with its
     * top row at the top -- see the class doc comment for the flip.
     *
     * @return array{0: float, 1: float, 2: float, 3: float, 4: float, 5: float}
     */
    private static function matrix(float $x, float $y, float $width, float $height): array
    {
        return [$width, 0.0, 0.0, -$height, $x, $y + $height];
    }
}
