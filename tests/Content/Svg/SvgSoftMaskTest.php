<?php

declare(strict_types=1);

namespace MightyPDF\Tests\Content\Svg;

use MightyPDF\Content\Svg\SvgGradient;
use MightyPDF\Content\Svg\SvgGradientStop;
use MightyPDF\Content\Svg\SvgSoftMask;
use PHPUnit\Framework\TestCase;

final class SvgSoftMaskTest extends TestCase
{
    private const array BOX = [10.0, 20.0, 40.0, 60.0];

    public function testTheMaskIsALuminosityGroupInGrey(): void
    {
        [$group, $state] = self::build(self::fading());

        self::assertSame('/Luminosity', $state->get('SMask')?->get('S')?->format());
        self::assertSame('/Mask', $state->get('SMask')?->get('Type')?->format());
        self::assertSame('/Transparency', $group->get('Group')?->get('S')?->format());
        self::assertSame('/DeviceGray', $group->get('Group')?->get('CS')?->format());
    }

    /**
     * The mask carries each stop's opacity as a grey level, not its
     * colour: the shading says what to paint and the mask says how much
     * of it survives.
     */
    public function testTheMasksShadingRampsBetweenTheStopsOpacities(): void
    {
        [$group] = self::build(self::fading());

        $shading = $group->get('Resources')?->get('Shading')?->get('Sh1');
        self::assertNotNull($shading);

        self::assertSame('/DeviceGray', $shading->get('ColorSpace')?->format());
        self::assertSame('[1]', $shading->get('Function')?->get('C0')?->format());
        self::assertSame('[0]', $shading->get('Function')?->get('C1')?->format());
    }

    /**
     * The group is clipped to its own /BBox, and what is clipped out is
     * black, meaning invisible. A stroke straddles the shape's box, so a
     * mask stopping exactly at it cuts the outer half of every stroked
     * edge away.
     */
    public function testTheMaskReachesPastTheShapeFarEnoughForItsStroke(): void
    {
        [$group] = self::build(self::fading(), strokeWidth: 4.0);

        // The box is (10, 20) to (50, 80), plus the stroke and a margin.
        self::assertSame('[5 15 55 85]', $group->get('BBox')?->format());
    }

    /**
     * A gradient in objectBoundingBox units is authored in a unit square
     * over the shape's box; the mask has to be drawn through the same
     * mapping as the colour, or it slides out of step with it.
     */
    public function testTheMasksContentsAreDrawnThroughTheGradientsOwnMatrix(): void
    {
        [$group] = self::build(self::fading());

        self::assertStringContainsString('40 0 0 60 10 20 cm', $group->rawBytes());
        self::assertStringContainsString('/Sh1 sh', $group->rawBytes());
    }

    /**
     * In user space there is no box to map through: the gradient's
     * coordinates are already the ones the shape is drawn in.
     */
    public function testAUserSpaceGradientsMaskIsDrawnWhereItStands(): void
    {
        [$group] = self::build(new SvgGradient(
            SvgGradient::LINEAR,
            [0.0, 0.0, 50.0, 0.0],
            [new SvgGradientStop(0.0, [0.0, 0.0, 0.0], 1.0), new SvgGradientStop(1.0, [0.0, 0.0, 0.0], 0.0)],
            userSpace: true,
            transform: null,
        ));

        self::assertStringContainsString('1 0 0 1 0 0 cm', $group->rawBytes());
    }

    /** @return array{0: \MightyPDF\Assembler\Stream, 1: \MightyPDF\Assembler\Dictionary} */
    private static function build(SvgGradient $gradient, float $strokeWidth = 0.0): array
    {
        return SvgSoftMask::build(1, 2, $gradient, self::BOX, $strokeWidth);
    }

    /** Black, fading to nothing -- how a fade-out is actually written. */
    private static function fading(): SvgGradient
    {
        return new SvgGradient(
            SvgGradient::LINEAR,
            [0.0, 0.0, 1.0, 0.0],
            [
                new SvgGradientStop(0.0, [0.0, 0.0, 0.0], 1.0),
                new SvgGradientStop(1.0, [0.0, 0.0, 0.0], 0.0),
            ],
            userSpace: false,
            transform: null,
        );
    }
}
