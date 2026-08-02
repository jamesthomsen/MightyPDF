<?php

declare(strict_types=1);

namespace MightyPDF\Content\Svg;

/**
 * A raster image embedded in an SVG, once the caller has turned its
 * bytes into a page resource: the name the content stream refers to it
 * by, and the size it is in pixels.
 *
 * The size comes back because only the caller can decode the image, and
 * only the renderer knows the rectangle it has to fit -- working out
 * preserveAspectRatio needs both.
 */
final class SvgRasterImage
{
    public function __construct(
        public readonly string $resourceName,
        public readonly int $width,
        public readonly int $height,
    ) {
    }
}
