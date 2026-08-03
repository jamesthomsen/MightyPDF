<?php

declare(strict_types=1);

namespace MightyPDF\Tests\Content\Svg;

use MightyPDF\Content\Svg\SvgPathParser;
use MightyPDF\Content\Svg\SvgPathWalk;
use PHPUnit\Framework\TestCase;

final class SvgPathWalkTest extends TestCase
{
    public function testMeasuresAStraightPathAndStandsAnywhereOnIt(): void
    {
        $walk = self::walk('M 0 0 L 100 0');

        self::assertEqualsWithDelta(100.0, $walk->length(), 1e-9);

        [$x, $y, $angle] = $walk->at(25.0);

        self::assertEqualsWithDelta(25.0, $x, 1e-9);
        self::assertEqualsWithDelta(0.0, $y, 1e-9);
        self::assertEqualsWithDelta(0.0, $angle, 1e-9);
    }

    public function testTurnsTheCornersOfAPathThatBends(): void
    {
        $walk = self::walk('M 0 0 L 10 0 L 10 10');

        self::assertEqualsWithDelta(20.0, $walk->length(), 1e-9);

        // Halfway up the second leg: heading straight down the page, a
        // quarter turn from where it started.
        [$x, $y, $angle] = $walk->at(15.0);

        self::assertEqualsWithDelta(10.0, $x, 1e-9);
        self::assertEqualsWithDelta(5.0, $y, 1e-9);
        self::assertEqualsWithDelta(M_PI / 2, $angle, 1e-9);
    }

    /**
     * A curve is flattened into steps, so its length is approximate --
     * but only just. The bound here is a tenth of a percent, well under
     * a glyph's width on any real path.
     */
    public function testMeasuresACurveCloselyEnoughToPlaceGlyphsOn(): void
    {
        // A half-circle of radius 10, as the two Beziers a drawing tool
        // would write it: pi * 10 long.
        $walk = self::walk('M 0 0 C 0 5.5228 4.4772 10 10 10 C 15.5228 10 20 5.5228 20 0');

        self::assertEqualsWithDelta(M_PI * 10, $walk->length(), M_PI * 10 * 0.001);
    }

    public function testAClosedPathComesBackToWhereItStarted(): void
    {
        $walk = self::walk('M 0 0 L 10 0 L 10 10 L 0 10 Z');

        self::assertEqualsWithDelta(40.0, $walk->length(), 1e-9);

        [$x, $y] = $walk->at(40.0);

        self::assertEqualsWithDelta(0.0, $x, 1e-9);
        self::assertEqualsWithDelta(0.0, $y, 1e-9);
    }

    /**
     * SVG does not render the glyphs that fall off a path, rather than
     * piling them up at its ends.
     */
    public function testThereIsNowhereToStandPastEitherEnd(): void
    {
        $walk = self::walk('M 0 0 L 10 0');

        self::assertNull($walk->at(-0.5));
        self::assertNull($walk->at(10.5));
        self::assertNotNull($walk->at(10.0));
    }

    /**
     * A move is a jump, not a journey: text carries on across the gap
     * between subpaths, since a path's subpaths are one path as far as
     * text on it is concerned.
     */
    public function testAJumpBetweenSubpathsCostsNoDistance(): void
    {
        $walk = self::walk('M 0 0 L 10 0 M 100 0 L 110 0');

        self::assertEqualsWithDelta(20.0, $walk->length(), 1e-9);

        [$x] = $walk->at(15.0);
        self::assertEqualsWithDelta(105.0, $x, 1e-9);
    }

    public function testAPathWithNothingInItIsEmpty(): void
    {
        self::assertTrue(self::walk('')->isEmpty());
        self::assertTrue(self::walk('M 5 5')->isEmpty());
        self::assertFalse(self::walk('M 5 5 L 6 6')->isEmpty());
    }

    private static function walk(string $d): SvgPathWalk
    {
        $walk = new SvgPathWalk();
        SvgPathParser::apply($d, $walk);

        return $walk;
    }
}
