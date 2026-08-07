<?php

declare(strict_types=1);

namespace MightyPDF\Tests\Content;

use MightyPDF\Content\Color;
use PHPUnit\Framework\TestCase;

final class ColorTest extends TestCase
{
    public function testRgb255DividesThroughToPdfsFloatRange(): void
    {
        $color = Color::fromRgb255(255, 128, 0);

        self::assertSame(1.0, $color->r);
        self::assertEqualsWithDelta(0.50196, $color->g, 1e-5);
        self::assertSame(0.0, $color->b);
    }

    public function testHexIsAcceptedWithOrWithoutItsHash(): void
    {
        self::assertTrue(Color::fromHex('#1a2b3c')->equals(Color::fromHex('1a2b3c')));
        self::assertTrue(Color::fromHex('#1A2B3C')->equals(Color::fromHex('#1a2b3c')));
        self::assertTrue(Color::fromHex('  #1a2b3c  ')->equals(Color::fromHex('#1a2b3c')));
    }

    /**
     * The shorthand doubles each digit the way CSS does, so "#abc" is
     * "#aabbcc". Padding with zeroes instead would give "#0a0b0c" -- a
     * near-black where a pale blue-grey was asked for, which is the kind
     * of wrong that gets noticed only in print.
     */
    public function testThreeDigitHexExpandsTheCssWay(): void
    {
        self::assertTrue(Color::fromHex('#abc')->equals(Color::fromRgb255(0xAA, 0xBB, 0xCC)));
        self::assertTrue(Color::fromHex('#fff')->equals(Color::white()));
        self::assertTrue(Color::fromHex('#000')->equals(Color::black()));
    }

    public function testHexRoundTrips(): void
    {
        foreach (['#000000', '#ffffff', '#1a2b3c', '#ff8800'] as $hex) {
            self::assertSame($hex, Color::fromHex($hex)->toHex());
        }
    }

    public function testGrayIsTheSameLevelOnEveryChannel(): void
    {
        $gray = Color::gray(0.25);

        self::assertSame([0.25, 0.25, 0.25], $gray->rgb());
    }

    /** The form the drawing primitives take, ready to spread. */
    public function testRgbComesBackAsAListForSpreading(): void
    {
        self::assertSame([1.0, 0.0, 0.0], Color::fromRgb255(255, 0, 0)->rgb());
    }

    /**
     * Out of range raises rather than clamping: 300 is a bug at the call
     * site, and drawing it as 255 hides which of the two numbers was
     * wrong. Same reasoning as SvgColor refusing an unknown colour name.
     */
    public function testOutOfRangeChannelsAreRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('green channel must be between 0 and 255');

        Color::fromRgb255(0, 300, 0);
    }

    public function testFloatChannelsOutsideZeroToOneAreRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Color::fromRgb255()');

        new Color(255.0, 0.0, 0.0);
    }

    public function testAStringThatIsNotHexIsRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('not a hex colour');

        Color::fromHex('#12345');
    }

    public function testHexRejectsNonHexDigits(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        Color::fromHex('#gggggg');
    }
}
