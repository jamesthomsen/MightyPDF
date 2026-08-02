<?php

declare(strict_types=1);

namespace MightyPDF\Content\Svg;

use MightyPDF\Content\Font\Font;
use MightyPDF\Content\Font\FontWriter;

/**
 * A font chosen for a piece of SVG text, ready to draw with: the name
 * the page's resources know it by, the font itself for measuring, and
 * its writer for encoding.
 *
 * All three are needed and none can be derived from the others here.
 * Measuring is what text-anchor needs and belongs to the font;
 * encoding depends on the document, since an embedded font is subset
 * per document; and the resource name is per page. Only the caller can
 * supply the last two, which is why this comes back from a callback
 * rather than being built in the renderer.
 */
final class SvgTextFont
{
    public function __construct(
        public readonly string $resourceName,
        public readonly Font $font,
        public readonly FontWriter $writer,
    ) {
    }
}
