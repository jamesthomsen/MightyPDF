<?php

declare(strict_types=1);

namespace MightyPDF\Tests\Content;

use MightyPDF\Content\CmykColor;
use MightyPDF\Content\ContentStream;
use MightyPDF\Content\SpotColor;
use PHPUnit\Framework\TestCase;

final class SpotColorTest extends TestCase
{
    private function brand(float $tint = 1.0): SpotColor
    {
        return SpotColor::named('PANTONE 300 C', CmykColor::fromPercentages(100, 44, 0, 0), $tint);
    }

    /**
     * The tint is an operand of the paint operator, not part of the
     * colour space, so every tint of one ink shares one /Separation
     * resource -- one plate, as a press would see it.
     */
    public function testTintIsNotPartOfTheColorSpacesIdentity(): void
    {
        self::assertSame($this->brand(1.0)->paintKey(), $this->brand(0.15)->paintKey());
    }

    public function testADifferentAlternateIsADifferentColorSpace(): void
    {
        $other = SpotColor::named('PANTONE 300 C', CmykColor::black());

        self::assertNotSame($this->brand()->paintKey(), $other->paintKey());
    }

    public function testWithTintKeepsTheInkAndItsAlternate(): void
    {
        $half = $this->brand()->withTint(0.5);

        self::assertSame('PANTONE 300 C', $half->name);
        self::assertSame(0.5, $half->tint);
        self::assertTrue($half->alternate->equals(CmykColor::fromPercentages(100, 44, 0, 0)));
    }

    public function testTintScalesTheAlternateLinearly(): void
    {
        $tinted = $this->brand(0.25)->tintedAlternate();

        self::assertEqualsWithDelta(0.25, $tinted->c, 1e-9);
        self::assertEqualsWithDelta(0.11, $tinted->m, 1e-9);
        self::assertSame(0.0, $tinted->y);
        self::assertSame(0.0, $tinted->k);
    }

    public function testAnEmptyNameIsRefusedBecauseItIdentifiesThePlate(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        SpotColor::named('', CmykColor::black());
    }

    public function testTintOutsideZeroToOneIsRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('tint must be between 0.0 and 1.0');

        $this->brand()->withTint(1.5);
    }

    /**
     * Two operators, in this order: switch the space, then set the tint.
     * Reversing them would set a tint in whatever space was previously in
     * effect and then reset it, since "cs" also restores that space's
     * initial colour.
     */
    public function testPaintsAsAColorSpaceSwitchThenATint(): void
    {
        $fill = new ContentStream();
        $stroke = new ContentStream();
        $namer = static fn (SpotColor $spot): string => 'CS7';

        $this->brand(0.5)->applyFill($fill, $namer);
        $this->brand(0.5)->applyStroke($stroke, $namer);

        self::assertSame("/CS7 cs\n0.5 scn\n", $fill->bytes());
        self::assertSame("/CS7 CS\n0.5 SCN\n", $stroke->bytes());
    }

    public function testRgbPreviewGoesThroughTheTintedAlternate(): void
    {
        self::assertSame('#ffffff', $this->brand(0.0)->toRgb()->toHex());
        self::assertSame($this->brand(0.4)->tintedAlternate()->toRgb()->toHex(), $this->brand(0.4)->toRgb()->toHex());
    }
}
