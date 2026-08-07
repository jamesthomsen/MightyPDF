<?php

declare(strict_types=1);

namespace MightyPDF\Tests\Content;

use MightyPDF\Content\CmykColor;
use MightyPDF\Content\Color;
use MightyPDF\Content\ContentStream;
use PHPUnit\Framework\TestCase;

final class CmykColorTest extends TestCase
{
    public function testPercentagesDivideThroughToPdfsFloatRange(): void
    {
        $color = CmykColor::fromPercentages(100, 44, 0, 0);

        self::assertSame(1.0, $color->c);
        self::assertEqualsWithDelta(0.44, $color->m, 1e-9);
        self::assertSame(0.0, $color->y);
        self::assertSame(0.0, $color->k);
    }

    public function testOutOfRangeChannelsRaiseRatherThanClamp(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('magenta channel must be between 0.0 and 1.0');

        new CmykColor(0.0, 1.5, 0.0, 0.0);
    }

    public function testPercentagesPastAHundredRaise(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        CmykColor::fromPercentages(0, 0, 0, 101);
    }

    /**
     * The whole reason this type exists: plain black and rich black are
     * different ink coverages that print visibly differently, and both
     * are #000000 in RGB. A library holding only RGB cannot carry the
     * distinction, which is what a print specification is specifying.
     */
    public function testRichBlackAndPlainBlackDifferAsInkAndAgreeAsRgb(): void
    {
        $plain = CmykColor::black();
        $rich = CmykColor::richBlack();

        self::assertFalse($plain->equals($rich));
        self::assertNotSame($plain->paintKey(), $rich->paintKey());

        self::assertSame('#000000', $plain->toRgb()->toHex());
        self::assertSame('#000000', $rich->toRgb()->toHex());
    }

    public function testRgbApproximationIsTheNaiveConversion(): void
    {
        self::assertSame('#ffffff', CmykColor::white()->toRgb()->toHex());
        self::assertSame('#00ffff', (new CmykColor(1.0, 0.0, 0.0, 0.0))->toRgb()->toHex());
        self::assertSame('#808080', (new CmykColor(0.0, 0.0, 0.0, 0.4980392156862745))->toRgb()->toHex());
    }

    /**
     * The four numbers go into the file as given, rather than being
     * converted to RGB on the way out -- which is the point. "k" is the
     * nonstroking operator and "K" the stroking one.
     */
    public function testPaintsWithTheDeviceCmykOperators(): void
    {
        $fill = new ContentStream();
        $stroke = new ContentStream();
        $namer = static fn (): string => self::fail('A process colour needs no colour-space resource.');

        $color = CmykColor::fromPercentages(60, 40, 40, 100);
        $color->applyFill($fill, $namer);
        $color->applyStroke($stroke, $namer);

        self::assertSame("0.6 0.4 0.4 1 k\n", $fill->bytes());
        self::assertSame("0.6 0.4 0.4 1 K\n", $stroke->bytes());
    }

    public function testIsAPaintAlongsideColor(): void
    {
        self::assertInstanceOf(Color::class, CmykColor::black()->toRgb());
        self::assertSame('cmyk:0,0,0,1', CmykColor::black()->paintKey());
    }
}
