<?php

declare(strict_types=1);

namespace MightyPDF\Tests\Content\Svg;

use MightyPDF\Content\Svg\SvgColor;
use PHPUnit\Framework\TestCase;

final class SvgColorTest extends TestCase
{
    public function testParsesSixDigitHex(): void
    {
        self::assertSame([1.0, 0.0, 0.0], SvgColor::parse('#FF0000'));
    }

    public function testParsesThreeDigitHexShorthand(): void
    {
        self::assertSame([1.0, 0.0, 0.0], SvgColor::parse('#F00'));
    }

    public function testParsesRgbFunction(): void
    {
        self::assertSame([0.0, 128 / 255.0, 1.0], SvgColor::parse('rgb(0, 128, 255)'));
    }

    public function testParsesNamedColors(): void
    {
        self::assertSame([0.0, 0.0, 0.0], SvgColor::parse('black'));
        self::assertSame([1.0, 1.0, 1.0], SvgColor::parse('white'));
        // "green" is intentionally dark (0,128,0) per the original HTML
        // color keywords -- "lime" is the bright (0,255,0) one.
        self::assertSame([0.0, 128 / 255, 0.0], SvgColor::parse('green'));
        self::assertSame([0.0, 1.0, 0.0], SvgColor::parse('lime'));
    }

    public function testNoneAndTransparentMeanNoPaint(): void
    {
        self::assertNull(SvgColor::parse('none'));
        self::assertNull(SvgColor::parse('transparent'));
        self::assertNull(SvgColor::parse(null));
    }

    public function testRejectsUnrecognizedColorName(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        SvgColor::parse('cornflowerblue-typo-xyz');
    }

    public function testGradientOrPatternReferenceDegradesToNoPaint(): void
    {
        // Gradients/patterns are out of scope -- rather than failing the
        // whole document over one shape's decorative fill, this degrades
        // to "no paint" for that property, same as a real renderer would
        // if the referenced def couldn't be resolved at all.
        self::assertNull(SvgColor::parse('url(#linearGradient123)'));
    }
}
