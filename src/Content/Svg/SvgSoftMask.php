<?php

declare(strict_types=1);

namespace MightyPDF\Content\Svg;

use MightyPDF\Assembler\Dictionary;
use MightyPDF\Assembler\Stream;
use MightyPDF\Assembler\Types\PdfArray;
use MightyPDF\Assembler\Types\PdfName;
use MightyPDF\Assembler\Types\PdfReal;
use MightyPDF\Assembler\Types\PdfRectangle;
use MightyPDF\Assembler\Types\PdfReference;
use MightyPDF\Content\ContentStream;

/**
 * The luminosity soft mask that carries a gradient's `stop-opacity`
 * (ISO 32000-2 §11.6.5.2).
 *
 * A PDF colour has no transparency in it, so a gradient that fades out
 * is drawn twice: once in colour, as an ordinary shading pattern, and
 * once in greyscale, where each stop's *opacity* is its grey level.
 * The greyscale copy is attached to the graphics state as a soft mask,
 * and the reader reads it as "white here means paint, black means leave
 * the page alone" -- so black-fading-to-nothing is a black shading under
 * a white-to-black mask.
 *
 * Two things about where it goes:
 *
 * - The mask is a *group*, not an image: a form XObject with a
 *   transparency group in DeviceGray. That is what makes its luminosity
 *   meaningful rather than just its coverage.
 * - It is rendered under the transform in effect where the graphics
 *   state is set, not from the page like a pattern (§8.7.4.3 applies to
 *   patterns, not to a mask's group). So the mask's contents are drawn
 *   in the shape's own coordinates, and none of the placement matrix
 *   goes anywhere near it -- which is why this takes a bounding box and
 *   a gradient and nothing else.
 */
final class SvgSoftMask
{
    /**
     * How far past the shape's own box the mask reaches.
     *
     * The mask's group is clipped to its /BBox, and anything clipped out
     * is black, meaning invisible. A stroke straddles the shape's box,
     * so a mask stopping exactly at the box would cut the outer half of
     * every stroked edge off -- a hairline of missing paint that looks
     * like an antialiasing artefact rather than a bug in the mask.
     */
    private const float MARGIN = 1.0;

    private function __construct()
    {
    }

    /**
     * @param array{0: float, 1: float, 2: float, 3: float} $boundingBox
     *        the shape's box, in the coordinates the shape is drawn in
     * @param float $strokeWidth how far the paint reaches outside it
     * @return array{0: Stream, 1: Dictionary} the mask's group, and the
     *         ExtGState that attaches it -- both still to be registered
     */
    public static function build(
        int $groupObjectId,
        int $stateObjectId,
        SvgGradient $gradient,
        array $boundingBox,
        float $strokeWidth = 0.0,
    ): array {
        $group = self::group($groupObjectId, $gradient, $boundingBox, $strokeWidth);

        $mask = new Dictionary();
        $mask->set('Type', new PdfName('Mask'));
        $mask->set('S', new PdfName('Luminosity'));
        $mask->set('G', new PdfReference($group->objectId()));

        // The backdrop against which the group is composited. Black is
        // the default and the right one: it means "transparent" where
        // the group paints nothing at all.
        $mask->set('BC', new PdfArray(new PdfReal(0.0)));

        $state = new Dictionary($stateObjectId);
        $state->set('Type', new PdfName('ExtGState'));
        $state->set('SMask', $mask);

        return [$group, $state];
    }

    /**
     * @param array{0: float, 1: float, 2: float, 3: float} $boundingBox
     */
    private static function group(
        int $objectId,
        SvgGradient $gradient,
        array $boundingBox,
        float $strokeWidth,
    ): Stream {
        [$x, $y, $width, $height] = $boundingBox;
        $margin = self::MARGIN + $strokeWidth;

        $operators = new ContentStream();

        // The gradient's own coordinate system, exactly as a shading
        // pattern's matrix builds it -- minus the placement, which the
        // graphics state already has in effect here.
        $operators->pushGraphicsState()
            ->concatMatrix(...self::gradientMatrix($gradient, $boundingBox))
            ->shade('Sh1')
            ->popGraphicsState();

        $shadings = new Dictionary();
        $shadings->set('Sh1', SvgShadingPattern::shading($gradient, luminosity: true));

        $resources = new Dictionary();
        $resources->set('Shading', $shadings);

        $transparency = new Dictionary();
        $transparency->set('Type', new PdfName('Group'));
        $transparency->set('S', new PdfName('Transparency'));
        $transparency->set('CS', new PdfName('DeviceGray'));

        $group = new Stream($objectId, $operators->bytes());
        $group->set('Type', new PdfName('XObject'));
        $group->set('Subtype', new PdfName('Form'));
        $group->set('BBox', new PdfRectangle(
            $x - $margin,
            $y - $margin,
            $x + $width + $margin,
            $y + $height + $margin,
        ));
        $group->set('Group', $transparency);
        $group->set('Resources', $resources);

        return $group;
    }

    /**
     * @param array{0: float, 1: float, 2: float, 3: float} $boundingBox
     * @return array{0: float, 1: float, 2: float, 3: float, 4: float, 5: float}
     */
    private static function gradientMatrix(SvgGradient $gradient, array $boundingBox): array
    {
        $matrix = $gradient->transform ?? SvgTransform::IDENTITY;

        if ($gradient->userSpace) {
            return $matrix;
        }

        [$x, $y, $width, $height] = $boundingBox;

        return SvgTransform::compose($matrix, [$width, 0.0, 0.0, $height, $x, $y]);
    }
}
