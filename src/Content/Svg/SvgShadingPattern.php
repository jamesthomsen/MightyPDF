<?php

declare(strict_types=1);

namespace MightyPDF\Content\Svg;

use MightyPDF\Assembler\Dictionary;
use MightyPDF\Assembler\Types\PdfArray;
use MightyPDF\Assembler\Types\PdfBoolean;
use MightyPDF\Assembler\Types\PdfInteger;
use MightyPDF\Assembler\Types\PdfName;
use MightyPDF\Assembler\Types\PdfReal;

/**
 * Turns an SVG gradient into the PDF shading pattern that paints it
 * (ISO 32000-2 §8.7.4.5.3 for the pattern, §8.7.4.5 for the shading).
 *
 * The shape of the answer is a nest of four things, all but the
 * outermost written inline:
 *
 *   pattern        /PatternType 2, plus the matrix below
 *     shading      /ShadingType 2 (axial) or 3 (radial), and where it runs
 *       function   what colour is at each point along it
 *
 * **The matrix is the whole difficulty.** A pattern is not painted under
 * the current transformation matrix: PDF measures pattern space from the
 * default coordinate system of the page (§8.7.3.1), so a gradient inside
 * a drawing that has been scaled, moved and flipped into place would
 * otherwise appear at its raw SVG coordinates in the corner of the page.
 * Everything the shape has been transformed by therefore has to be
 * folded into the pattern's own matrix instead -- the placement of the
 * drawing, every enclosing transform, the shape's bounding box for
 * gradients measured in it, and the gradient's own gradientTransform,
 * composed in that order.
 *
 * That is also why SvgDocument tracks a transform matrix it otherwise
 * would not need: PDF's own CTM is unavailable to it, and unhelpful
 * here even if it were.
 */
final class SvgShadingPattern
{
    private const int PATTERN_TYPE_SHADING = 2;
    private const int SHADING_TYPE_AXIAL = 2;
    private const int SHADING_TYPE_RADIAL = 3;

    /** Function types: 2 is an interpolation between two colours, 3 stitches several together. */
    private const int FUNCTION_TYPE_EXPONENTIAL = 2;
    private const int FUNCTION_TYPE_STITCHING = 3;

    private function __construct()
    {
    }

    /**
     * @param array{0: float, 1: float, 2: float, 3: float, 4: float, 5: float} $transform
     *        what the shape being painted has been transformed by, from
     *        its own coordinates to the page
     * @param array{0: float, 1: float, 2: float, 3: float} $boundingBox
     *        the shape's box in its own coordinates, used only by
     *        gradients in objectBoundingBox units
     */
    public static function build(
        int $objectId,
        SvgGradient $gradient,
        array $transform,
        array $boundingBox,
    ): Dictionary {
        $pattern = new Dictionary($objectId);
        $pattern->set('Type', new PdfName('Pattern'));
        $pattern->set('PatternType', new PdfInteger(self::PATTERN_TYPE_SHADING));
        $pattern->set('Shading', self::shading($gradient));
        $pattern->set('Matrix', self::matrix($gradient, $transform, $boundingBox));

        return $pattern;
    }

    /**
     * Whether a gradient can be painted against this box at all.
     *
     * A gradient in objectBoundingBox units divides by the box's width
     * and height, and a shape with no area in one direction -- a
     * horizontal line, an empty path -- has none to divide by. The SVG
     * spec's answer is that the element is not rendered, which is also
     * the only answer that does not involve dividing by zero.
     *
     * @param array{0: float, 1: float, 2: float, 3: float} $boundingBox
     */
    public static function canPaint(SvgGradient $gradient, array $boundingBox): bool
    {
        if (!$gradient->isPaintable()) {
            return false;
        }

        return $gradient->userSpace || ($boundingBox[2] > 0.0 && $boundingBox[3] > 0.0);
    }

    /**
     * The shading a gradient paints with.
     *
     * $luminosity asks for the greyscale twin instead: the same geometry
     * carrying each stop's *opacity* as a grey level, which is what a
     * soft mask reads transparency from. Built here rather than in
     * SvgSoftMask so that the geometry -- axial or radial, the coords,
     * the padding of stops to the whole domain -- is written once and
     * cannot drift between the colour and its mask.
     */
    public static function shading(SvgGradient $gradient, bool $luminosity = false): Dictionary
    {
        $shading = new Dictionary();
        $shading->set('ColorSpace', new PdfName($luminosity ? 'DeviceGray' : 'DeviceRGB'));
        $shading->set('Function', self::colorFunction($gradient->paddedStops(), $luminosity));

        // Extend paints the space beyond each end of the gradient with
        // the colour at that end, which is what SVG's default "pad"
        // spread means. Without it a shape larger than its gradient is
        // painted only where the gradient reaches, and the rest of it is
        // simply not painted -- transparent, not the end colour.
        $shading->set('Extend', new PdfArray(new PdfBoolean(true), new PdfBoolean(true)));

        if ($gradient->isLinear()) {
            [$x1, $y1, $x2, $y2] = $gradient->coordinates;

            $shading->set('ShadingType', new PdfInteger(self::SHADING_TYPE_AXIAL));
            $shading->set('Coords', self::numbers([$x1, $y1, $x2, $y2]));

            return $shading;
        }

        [$cx, $cy, $r, $fx, $fy] = $gradient->coordinates;

        // A radial gradient in SVG runs from a focal *point* to a
        // circle; PDF interpolates between two circles, so the focus is
        // the first of them with a radius of zero.
        $shading->set('ShadingType', new PdfInteger(self::SHADING_TYPE_RADIAL));
        $shading->set('Coords', self::numbers([$fx, $fy, 0.0, $cx, $cy, $r]));

        return $shading;
    }

    /**
     * The colour at each point from 0 to 1 along the gradient.
     *
     * Two stops need only an interpolation between two colours. More
     * than two need one such interpolation per pair, stitched together
     * over the ranges between their offsets -- PDF has no notion of a
     * multi-stop gradient as a single object.
     *
     * @param list<SvgGradientStop> $stops
     */
    private static function colorFunction(array $stops, bool $luminosity): Dictionary
    {
        $components = static fn (SvgGradientStop $stop): array => $luminosity ? $stop->luminosity() : $stop->color;

        if (count($stops) === 2) {
            return self::interpolation($components($stops[0]), $components($stops[1]));
        }

        $functions = [];
        $bounds = [];
        $encode = [];

        for ($i = 0, $last = count($stops) - 1; $i < $last; ++$i) {
            $functions[] = self::interpolation($components($stops[$i]), $components($stops[$i + 1]));

            // Each sub-function is written over 0 to 1 and used over the
            // slice between two offsets, which is what /Encode says.
            array_push($encode, new PdfInteger(0), new PdfInteger(1));

            // /Bounds holds the interior offsets only: the ends of the
            // whole function are its /Domain, not a boundary between
            // two pieces of it.
            if ($i > 0) {
                $bounds[] = new PdfReal($stops[$i]->offset);
            }
        }

        $function = new Dictionary();
        $function->set('FunctionType', new PdfInteger(self::FUNCTION_TYPE_STITCHING));
        $function->set('Domain', self::numbers([0.0, 1.0]));
        $function->set('Functions', new PdfArray(...$functions));
        $function->set('Bounds', new PdfArray(...$bounds));
        $function->set('Encode', new PdfArray(...$encode));

        return $function;
    }

    /**
     * @param list<float> $from one component for a grey ramp, three for a colour
     * @param list<float> $to
     */
    private static function interpolation(array $from, array $to): Dictionary
    {
        $function = new Dictionary();
        $function->set('FunctionType', new PdfInteger(self::FUNCTION_TYPE_EXPONENTIAL));
        $function->set('Domain', self::numbers([0.0, 1.0]));
        $function->set('C0', self::numbers($from));
        $function->set('C1', self::numbers($to));

        // N 1: a straight ramp from C0 to C1, which is what a gradient
        // stop pair means. Anything else would be an ease.
        $function->set('N', new PdfInteger(1));

        return $function;
    }

    /**
     * @param array{0: float, 1: float, 2: float, 3: float, 4: float, 5: float} $transform
     * @param array{0: float, 1: float, 2: float, 3: float} $boundingBox
     */
    private static function matrix(SvgGradient $gradient, array $transform, array $boundingBox): PdfArray
    {
        $matrix = $gradient->transform ?? SvgTransform::IDENTITY;

        if (!$gradient->userSpace) {
            // objectBoundingBox units: the gradient is authored in a
            // square from (0, 0) to (1, 1) that is then stretched over
            // whatever box the shape occupies.
            [$x, $y, $width, $height] = $boundingBox;
            $matrix = SvgTransform::compose($matrix, [$width, 0.0, 0.0, $height, $x, $y]);
        }

        return self::numbers(SvgTransform::compose($matrix, $transform));
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
