<?php

declare(strict_types=1);

namespace MightyPDF\Tests\Content\Svg;

use MightyPDF\Assembler\Dictionary;
use MightyPDF\Content\Svg\SvgGradient;
use MightyPDF\Content\Svg\SvgGradientStop;
use MightyPDF\Content\Svg\SvgShadingPattern;
use MightyPDF\Content\Svg\SvgTransform;
use PHPUnit\Framework\TestCase;

final class SvgShadingPatternTest extends TestCase
{
    private const array UNIT_BOX = [0.0, 0.0, 1.0, 1.0];

    public function testALinearGradientBecomesAnAxialShading(): void
    {
        $shading = self::shadingOf(self::linear([0.0, 0.0, 1.0, 0.0]));

        self::assertSame('2', $shading->get('ShadingType')?->format());
        self::assertSame('[0 0 1 0]', $shading->get('Coords')?->format());
        self::assertSame('/DeviceRGB', $shading->get('ColorSpace')?->format());
    }

    /**
     * SVG runs a radial gradient from a focal point to a circle; PDF
     * interpolates between two circles, so the focus is the first of
     * them with no radius at all.
     */
    public function testARadialGradientBecomesTwoCirclesTheFirstOfThemAPoint(): void
    {
        $gradient = new SvgGradient(
            SvgGradient::RADIAL,
            [0.5, 0.5, 0.4, 0.3, 0.2],
            self::twoStops(),
            userSpace: false,
            transform: null,
        );

        $shading = self::shadingOf($gradient);

        self::assertSame('3', $shading->get('ShadingType')?->format());
        self::assertSame('[0.3 0.2 0 0.5 0.5 0.4]', $shading->get('Coords')?->format());
    }

    /**
     * Without /Extend, the part of a shape beyond the ends of its
     * gradient is left unpainted rather than painted with the end
     * colour -- which is not what SVG's default spread means.
     */
    public function testTheShadingExtendsPastBothEnds(): void
    {
        self::assertSame('[true true]', self::shadingOf(self::linear())->get('Extend')?->format());
    }

    public function testTwoStopsNeedOnlyAnInterpolation(): void
    {
        $function = self::shadingOf(self::linear())->get('Function');

        self::assertInstanceOf(Dictionary::class, $function);
        self::assertSame('2', $function->get('FunctionType')?->format());
        self::assertSame('[1 0 0]', $function->get('C0')?->format());
        self::assertSame('[0 0 1]', $function->get('C1')?->format());
        self::assertSame('1', $function->get('N')?->format());
    }

    /** PDF has no multi-stop gradient: it has functions stitched together. */
    public function testMoreThanTwoStopsAreStitchedTogether(): void
    {
        $gradient = self::linear([0.0, 0.0, 1.0, 0.0], [
            new SvgGradientStop(0.0, [1.0, 0.0, 0.0]),
            new SvgGradientStop(0.25, [0.0, 1.0, 0.0]),
            new SvgGradientStop(1.0, [0.0, 0.0, 1.0]),
        ]);

        $function = self::shadingOf($gradient)->get('Function');

        self::assertInstanceOf(Dictionary::class, $function);
        self::assertSame('3', $function->get('FunctionType')?->format());
        self::assertSame('[0 1]', $function->get('Domain')?->format());

        // One interior offset, and each piece written over 0 to 1.
        self::assertSame('[0.25]', $function->get('Bounds')?->format());
        self::assertSame('[0 1 0 1]', $function->get('Encode')?->format());
    }

    /**
     * Stops need not reach the ends of the gradient. PDF's /Extend
     * covers what lies outside the shading, but the function itself has
     * to be defined across the whole domain.
     */
    public function testStopsThatDoNotReachTheEndsAreHeldFlatToThem(): void
    {
        $gradient = self::linear([0.0, 0.0, 1.0, 0.0], [
            new SvgGradientStop(0.3, [1.0, 0.0, 0.0]),
            new SvgGradientStop(0.8, [0.0, 0.0, 1.0]),
        ]);

        $function = self::shadingOf($gradient)->get('Function');

        self::assertInstanceOf(Dictionary::class, $function);
        self::assertSame('3', $function->get('FunctionType')?->format(), 'flat ends make this three pieces');
        self::assertSame('[0.3 0.8]', $function->get('Bounds')?->format());
    }

    /**
     * A gradient in the default units is authored in a 0-to-1 square and
     * stretched over whatever box the shape occupies.
     */
    public function testObjectBoundingBoxUnitsStretchTheGradientOverTheShape(): void
    {
        $pattern = SvgShadingPattern::build(
            1,
            self::linear(),
            SvgTransform::IDENTITY,
            [10.0, 20.0, 80.0, 40.0],
        );

        self::assertSame('[80 0 0 40 10 20]', $pattern->get('Matrix')?->format());
    }

    public function testUserSpaceGradientsIgnoreTheShapesBox(): void
    {
        $gradient = new SvgGradient(SvgGradient::LINEAR, [0.0, 0.0, 1.0, 0.0], self::twoStops(), true, null);

        $pattern = SvgShadingPattern::build(1, $gradient, SvgTransform::IDENTITY, [10.0, 20.0, 80.0, 40.0]);

        self::assertSame('[1 0 0 1 0 0]', $pattern->get('Matrix')?->format());
    }

    /**
     * A pattern is positioned relative to the page, not to the
     * transformation in effect where it is used, so everything the
     * shape has been transformed by has to be folded into its matrix.
     */
    public function testTheShapesOwnTransformIsFoldedIntoThePatternMatrix(): void
    {
        $gradient = new SvgGradient(SvgGradient::LINEAR, [0.0, 0.0, 1.0, 0.0], self::twoStops(), true, null);

        // The drawing was placed at half scale, 100 points up the page.
        $pattern = SvgShadingPattern::build(1, $gradient, [0.5, 0.0, 0.0, 0.5, 0.0, 100.0], self::UNIT_BOX);

        self::assertSame('[0.5 0 0 0.5 0 100]', $pattern->get('Matrix')?->format());
    }

    public function testAGradientTransformAppliesBeforeEverythingElse(): void
    {
        $gradient = new SvgGradient(
            SvgGradient::LINEAR,
            [0.0, 0.0, 1.0, 0.0],
            self::twoStops(),
            userSpace: true,
            transform: [1.0, 0.0, 0.0, 1.0, 5.0, 0.0],
        );

        $pattern = SvgShadingPattern::build(1, $gradient, [2.0, 0.0, 0.0, 2.0, 0.0, 0.0], self::UNIT_BOX);

        // The gradient's own 5-unit shift is scaled by the placement
        // that follows it, not added to the page coordinates raw.
        self::assertSame('[2 0 0 2 10 0]', $pattern->get('Matrix')?->format());
    }

    public function testTheResultIsAShadingPattern(): void
    {
        $pattern = SvgShadingPattern::build(7, self::linear(), SvgTransform::IDENTITY, self::UNIT_BOX);

        self::assertSame(7, $pattern->objectId());
        self::assertSame('/Pattern', $pattern->get('Type')?->format());
        self::assertSame('2', $pattern->get('PatternType')?->format());
    }

    /**
     * A gradient in bounding-box units divides by the box's width and
     * height, and a shape with no area in one direction has none to
     * divide by.
     */
    public function testAShapeWithNoAreaCannotBePaintedWithABoundingBoxGradient(): void
    {
        self::assertFalse(SvgShadingPattern::canPaint(self::linear(), [0.0, 0.0, 100.0, 0.0]));
        self::assertTrue(SvgShadingPattern::canPaint(self::linear(), [0.0, 0.0, 100.0, 5.0]));
    }

    public function testAUserSpaceGradientDoesNotNeedTheShapeToHaveArea(): void
    {
        $gradient = new SvgGradient(SvgGradient::LINEAR, [0.0, 0.0, 1.0, 0.0], self::twoStops(), true, null);

        self::assertTrue(SvgShadingPattern::canPaint($gradient, [0.0, 0.0, 100.0, 0.0]));
    }

    public function testAGradientWithNoStopsPaintsNothing(): void
    {
        $gradient = new SvgGradient(SvgGradient::LINEAR, [0.0, 0.0, 1.0, 0.0], [], false, null);

        self::assertFalse(SvgShadingPattern::canPaint($gradient, self::UNIT_BOX));
    }

    /** @param list<SvgGradientStop> $stops */
    private static function linear(array $coordinates = [0.0, 0.0, 1.0, 0.0], ?array $stops = null): SvgGradient
    {
        return new SvgGradient(
            SvgGradient::LINEAR,
            $coordinates,
            $stops ?? self::twoStops(),
            userSpace: false,
            transform: null,
        );
    }

    /** @return list<SvgGradientStop> */
    private static function twoStops(): array
    {
        return [
            new SvgGradientStop(0.0, [1.0, 0.0, 0.0]),
            new SvgGradientStop(1.0, [0.0, 0.0, 1.0]),
        ];
    }

    private static function shadingOf(SvgGradient $gradient): Dictionary
    {
        $shading = SvgShadingPattern::build(1, $gradient, SvgTransform::IDENTITY, self::UNIT_BOX)->get('Shading');

        self::assertInstanceOf(Dictionary::class, $shading);

        return $shading;
    }
}
