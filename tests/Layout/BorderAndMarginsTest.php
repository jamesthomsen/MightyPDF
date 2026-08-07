<?php

declare(strict_types=1);

namespace MightyPDF\Tests\Layout;

use MightyPDF\Content\Color;
use MightyPDF\Layout\Border;
use MightyPDF\Layout\Margins;
use MightyPDF\Layout\Unit;
use PHPUnit\Framework\TestCase;

/**
 * The value objects that replace FPDF's overloaded $border argument and
 * its bare millimetre floats.
 */
final class BorderAndMarginsTest extends TestCase
{
    public function testAnEmptyBorderDrawsNothing(): void
    {
        self::assertTrue(Border::none()->isEmpty());
        self::assertTrue((new Border())->isEmpty());
        self::assertFalse(Border::bottom()->isEmpty());
    }

    public function testBoxIsAllFourEdges(): void
    {
        $border = Border::box(0.5);

        self::assertTrue($border->top && $border->right && $border->bottom && $border->left);
        self::assertSame(0.5, $border->widthPt);
    }

    public function testASingleEdgeLeavesTheOthersOff(): void
    {
        $bottom = Border::bottom();

        self::assertTrue($bottom->bottom);
        self::assertFalse($bottom->top || $bottom->left || $bottom->right);
    }

    public function testABorderWithNoColourRulesInBlack(): void
    {
        self::assertTrue(Border::box()->colorOrBlack()->equals(Color::black()));
        self::assertTrue(Border::box(color: Color::white())->colorOrBlack()->equals(Color::white()));
    }

    public function testUniformAndSymmetricMarginsSayWhatTheyMean(): void
    {
        $uniform = Margins::uniform(15.0);
        self::assertSame([15.0, 15.0, 15.0, 15.0], [$uniform->top, $uniform->right, $uniform->bottom, $uniform->left]);

        $symmetric = Margins::symmetric(20.0, 10.0);
        self::assertSame([20.0, 10.0, 20.0, 10.0], [$symmetric->top, $symmetric->right, $symmetric->bottom, $symmetric->left]);
    }

    public function testUnitsConvertBothWaysAndRoundTrip(): void
    {
        self::assertEqualsWithDelta(72.0, Unit::Inches->toPoints(1.0), 1e-9);
        self::assertEqualsWithDelta(28.3465, Unit::Millimetres->toPoints(10.0), 1e-4);
        self::assertSame(42.0, Unit::Points->toPoints(42.0));

        foreach (Unit::cases() as $unit) {
            self::assertEqualsWithDelta(37.5, $unit->fromPoints($unit->toPoints(37.5)), 1e-9, $unit->name);
        }
    }
}
