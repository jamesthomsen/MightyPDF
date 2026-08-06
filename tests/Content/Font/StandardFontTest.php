<?php

declare(strict_types=1);

namespace MightyPDF\Tests\Content\Font;

use MightyPDF\Content\Font\StandardFont;
use PHPUnit\Framework\TestCase;

final class StandardFontTest extends TestCase
{
    public function testBaseFontNamesMatchPdfConventions(): void
    {
        self::assertSame('Helvetica', StandardFont::Helvetica->baseFontName());
        self::assertSame('Helvetica-BoldOblique', StandardFont::HelveticaBoldOblique->baseFontName());
        self::assertSame('Times-Roman', StandardFont::TimesRoman->baseFontName());
        self::assertSame('Times-BoldItalic', StandardFont::TimesBoldItalic->baseFontName());
        self::assertSame('Courier-Bold', StandardFont::CourierBold->baseFontName());
        self::assertSame('ZapfDingbats', StandardFont::ZapfDingbats->baseFontName());
    }

    public function testSymbolAndZapfDingbatsDoNotUseWinAnsiEncoding(): void
    {
        self::assertFalse(StandardFont::Symbol->usesWinAnsiEncoding());
        self::assertFalse(StandardFont::ZapfDingbats->usesWinAnsiEncoding());
        self::assertTrue(StandardFont::Helvetica->usesWinAnsiEncoding());
    }

    /**
     * Spot-checks against well-known, widely-published Adobe Core 14 AFM
     * metrics (the same reference numbers reproduced across essentially
     * every PDF library).
     */
    public function testKnownHelveticaWidths(): void
    {
        $metrics = StandardFont::Helvetica->metrics();

        self::assertSame(278, $metrics->widthOfCode(ord(' ')));
        self::assertSame(833, $metrics->widthOfCode(ord('M')));
        self::assertSame(278, $metrics->widthOfCode(ord('.')));
        self::assertSame(222, $metrics->widthOfCode(ord('i')));
        self::assertSame(944, $metrics->widthOfCode(ord('W')));
    }

    public function testHelveticaObliqueSharesHelveticaWidths(): void
    {
        self::assertSame(
            StandardFont::Helvetica->metrics()->widthOfCode(ord('M')),
            StandardFont::HelveticaOblique->metrics()->widthOfCode(ord('M')),
        );
    }

    public function testCourierIsFixedWidthAcrossAllVariants(): void
    {
        foreach ([StandardFont::Courier, StandardFont::CourierBold, StandardFont::CourierOblique, StandardFont::CourierBoldOblique] as $font) {
            self::assertSame(600, $font->metrics()->widthOfCode(ord('i')));
            self::assertSame(600, $font->metrics()->widthOfCode(ord('W')));
        }
    }

    public function testKnownTimesRomanWidths(): void
    {
        $metrics = StandardFont::TimesRoman->metrics();

        self::assertSame(250, $metrics->widthOfCode(ord(' ')));
        self::assertSame(722, $metrics->widthOfCode(ord('A')));
    }

    public function testSupportsWhatWinAnsiHasCodesFor(): void
    {
        self::assertTrue(StandardFont::Helvetica->supports('Hello, café — €50'));
        self::assertFalse(StandardFont::Helvetica->supports('Ταβέρνα'));
    }

    public function testMissingCharactersNamesWhatWillNotBeDrawnAsItself(): void
    {
        self::assertSame([], StandardFont::Helvetica->missingCharacters('naïve'));
        self::assertSame(['Ł', 'ź'], StandardFont::TimesRoman->missingCharacters('Łódź'));
    }

    /**
     * The bug this pair of methods exists to make checkable: text with no
     * CP1252 character in it is drawn as an approximation, not refused.
     */
    public function testTextOutsideWinAnsiIsMeasuredRatherThanRefused(): void
    {
        foreach (['Phở Việt Nam', 'Ταβέρνα', 'Łódź'] as $text) {
            self::assertGreaterThan(0.0, StandardFont::Helvetica->widthOfPt($text, 12.0));
        }
    }
}
