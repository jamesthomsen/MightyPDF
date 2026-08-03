<?php

declare(strict_types=1);

namespace MightyPDF\Tests\Content\Svg;

use MightyPDF\Content\Svg\SvgDocument;
use MightyPDF\Content\Svg\SvgGradient;
use PHPUnit\Framework\TestCase;

final class SvgGradientParserTest extends TestCase
{
    public function testReadsALinearGradientsStopsAndGeometry(): void
    {
        $gradient = self::gradient(
            '<linearGradient id="g" x1="0" y1="0" x2="1" y2="0">'
            . '<stop offset="0" stop-color="#ff0000"/>'
            . '<stop offset="1" stop-color="#0000ff"/>'
            . '</linearGradient>',
        );

        self::assertTrue($gradient->isLinear());
        self::assertSame([0.0, 0.0, 1.0, 0.0], $gradient->coordinates);
        self::assertCount(2, $gradient->stops);
        self::assertSame([1.0, 0.0, 0.0], $gradient->stops[0]->color);
        self::assertSame(1.0, $gradient->stops[1]->offset);
    }

    /** A gradient with no geometry of its own runs left to right across the shape. */
    public function testALinearGradientDefaultsToHorizontal(): void
    {
        $gradient = self::gradient('<linearGradient id="g"><stop offset="0" stop-color="red"/></linearGradient>');

        self::assertSame([0.0, 0.0, 1.0, 0.0], $gradient->coordinates);
    }

    public function testPercentagesAreFractionsOfTheBoundingBox(): void
    {
        $gradient = self::gradient(
            '<linearGradient id="g" x1="25%" y1="0%" x2="75%" y2="100%">'
            . '<stop offset="50%" stop-color="red"/>'
            . '</linearGradient>',
        );

        self::assertSame([0.25, 0.0, 0.75, 1.0], $gradient->coordinates);
        self::assertSame(0.5, $gradient->stops[0]->offset);
    }

    public function testARadialGradientsFocusDefaultsToItsCentre(): void
    {
        $gradient = self::gradient(
            '<radialGradient id="g" cx="0.3" cy="0.4" r="0.6"><stop offset="0" stop-color="red"/></radialGradient>',
        );

        self::assertSame(SvgGradient::RADIAL, $gradient->type);
        self::assertSame([0.3, 0.4, 0.6, 0.3, 0.4], $gradient->coordinates);
    }

    public function testARadialGradientDefaultsToFillingItsShape(): void
    {
        $gradient = self::gradient('<radialGradient id="g"><stop offset="0" stop-color="red"/></radialGradient>');

        self::assertSame([0.5, 0.5, 0.5, 0.5, 0.5], $gradient->coordinates);
    }

    /**
     * Reusing one set of stops with a different geometry is the whole
     * point of href on a gradient, and how tools write a "same colours,
     * other direction" pair.
     */
    public function testAGradientInheritsStopsFromTheOneItReferences(): void
    {
        $document = self::document(
            '<linearGradient id="base"><stop offset="0" stop-color="#ff0000"/>'
            . '<stop offset="1" stop-color="#00ff00"/></linearGradient>'
            . '<linearGradient id="rotated" xlink:href="#base" x1="0" y1="0" x2="0" y2="1"/>',
        );

        $rotated = $document->gradients()['rotated'];

        self::assertCount(2, $rotated->stops);
        self::assertSame([1.0, 0.0, 0.0], $rotated->stops[0]->color);

        // Its own geometry: top to bottom, where the gradient it took
        // the stops from runs left to right.
        self::assertSame([0.0, 0.0, 0.0, 1.0], $rotated->coordinates);
    }

    public function testSvg2StyleHrefIsFollowedToo(): void
    {
        $document = self::document(
            '<linearGradient id="base"><stop offset="0" stop-color="#ff0000"/></linearGradient>'
            . '<linearGradient id="child" href="#base"/>',
        );

        self::assertCount(1, $document->gradients()['child']->stops);
    }

    public function testInheritedAttributesDoNotOverrideOnesTheGradientSets(): void
    {
        $document = self::document(
            '<linearGradient id="base" x2="0" y2="1" gradientUnits="userSpaceOnUse">'
            . '<stop offset="0" stop-color="red"/></linearGradient>'
            . '<linearGradient id="child" xlink:href="#base" x2="0.5"/>',
        );

        $child = $document->gradients()['child'];

        self::assertTrue($child->userSpace, 'gradientUnits came from the referenced gradient');
        self::assertSame(0.5, $child->coordinates[2], 'x2 is the child\'s own');
    }

    /** A gradient chain that loops must not loop the parser with it. */
    public function testGradientsThatReferenceEachOtherAreNotFollowedForever(): void
    {
        $document = self::document(
            '<linearGradient id="a" xlink:href="#b"/><linearGradient id="b" xlink:href="#a"/>',
        );

        self::assertSame([], $document->gradients()['a']->stops);
    }

    public function testStopColoursMayBeWrittenAsAStyleDeclaration(): void
    {
        $gradient = self::gradient(
            '<linearGradient id="g"><stop offset="0" style="stop-color:#00ff00;stop-opacity:1"/></linearGradient>',
        );

        self::assertSame([0.0, 1.0, 0.0], $gradient->stops[0]->color);
    }

    /**
     * Offsets that go backwards would produce a shading function whose
     * domain runs the wrong way, which readers reject outright.
     */
    public function testOffsetsThatGoBackwardsAreHeldAtTheHighestSoFar(): void
    {
        $gradient = self::gradient(
            '<linearGradient id="g">'
            . '<stop offset="0.5" stop-color="red"/>'
            . '<stop offset="0.2" stop-color="green"/>'
            . '<stop offset="2" stop-color="blue"/>'
            . '</linearGradient>',
        );

        self::assertSame([0.5, 0.5, 1.0], array_map(
            static fn ($stop): float => $stop->offset,
            $gradient->stops,
        ));
    }

    public function testUserSpaceGradientsKeepTheirCoordinatesAsAuthored(): void
    {
        $gradient = self::gradient(
            '<linearGradient id="g" gradientUnits="userSpaceOnUse" x1="20" y1="0" x2="180" y2="0">'
            . '<stop offset="0" stop-color="red"/></linearGradient>',
        );

        self::assertTrue($gradient->userSpace);
        self::assertSame([20.0, 0.0, 180.0, 0.0], $gradient->coordinates);
    }

    /** In user space a percentage is measured against the viewport, not the shape. */
    public function testUserSpacePercentagesAreMeasuredAgainstTheViewport(): void
    {
        $gradient = self::gradient(
            '<linearGradient id="g" gradientUnits="userSpaceOnUse" x1="0%" x2="50%">'
            . '<stop offset="0" stop-color="red"/></linearGradient>',
        );

        // The test document's viewBox is 200 wide.
        self::assertSame(100.0, $gradient->coordinates[2]);
    }

    /**
     * A transform list on a gradient has to be multiplied out here: the
     * pattern it becomes takes one matrix, not a sequence of them.
     */
    public function testGradientTransformsAreComposedIntoASingleMatrix(): void
    {
        $gradient = self::gradient(
            '<linearGradient id="g" gradientTransform="translate(10 20) scale(2)">'
            . '<stop offset="0" stop-color="red"/></linearGradient>',
        );

        self::assertSame([2.0, 0.0, 0.0, 2.0, 10.0, 20.0], $gradient->transform);
    }

    public function testAGradientOutsideDefsIsFoundToo(): void
    {
        $document = SvgDocument::fromString(
            '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 200 200">'
            . '<linearGradient id="loose"><stop offset="0" stop-color="red"/></linearGradient>'
            . '<rect width="10" height="10" fill="url(#loose)"/>'
            . '</svg>',
        );

        self::assertArrayHasKey('loose', $document->gradients());
    }

    public function testReadsStopOpacityFromAnAttributeOrAStyle(): void
    {
        $gradient = self::gradient(
            '<linearGradient id="g">'
            . '<stop offset="0" stop-color="#000000" stop-opacity="0.25"/>'
            . '<stop offset="0.5" stop-color="#000000" style="stop-opacity:50%"/>'
            . '<stop offset="1" stop-color="#000000"/>'
            . '</linearGradient>',
        );

        self::assertEqualsWithDelta(0.25, $gradient->stops[0]->opacity, 1e-9);
        self::assertEqualsWithDelta(0.5, $gradient->stops[1]->opacity, 1e-9);
        self::assertSame(1.0, $gradient->stops[2]->opacity);
        self::assertTrue($gradient->hasTransparency());
    }

    /**
     * "Black fading to nothing" is one colour with two opacities -- and
     * painting it as a flat black would draw the whole shape at full
     * strength.
     */
    public function testAGradientThatOnlyFadesIsNotAFlatFill(): void
    {
        $gradient = self::gradient(
            '<linearGradient id="g">'
            . '<stop offset="0" stop-color="#000000" stop-opacity="1"/>'
            . '<stop offset="1" stop-color="#000000" stop-opacity="0"/>'
            . '</linearGradient>',
        );

        self::assertNull($gradient->solidColor());
    }

    public function testAnOutOfRangeStopOpacityIsClamped(): void
    {
        $gradient = self::gradient(
            '<linearGradient id="g">'
            . '<stop offset="0" stop-color="#000000" stop-opacity="-2"/>'
            . '<stop offset="1" stop-color="#000000" stop-opacity="7"/>'
            . '</linearGradient>',
        );

        self::assertSame(0.0, $gradient->stops[0]->opacity);
        self::assertSame(1.0, $gradient->stops[1]->opacity);
    }

    private static function gradient(string $definition): SvgGradient
    {
        return self::document($definition)->gradients()['g'];
    }

    private static function document(string $definitions): SvgDocument
    {
        return SvgDocument::fromString(
            '<svg xmlns="http://www.w3.org/2000/svg" xmlns:xlink="http://www.w3.org/1999/xlink" viewBox="0 0 200 200">'
            . "<defs>$definitions</defs>"
            . '</svg>',
        );
    }
}
