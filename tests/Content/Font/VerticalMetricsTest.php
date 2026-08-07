<?php

declare(strict_types=1);

namespace MightyPDF\Tests\Content\Font;

use MightyPDF\Content\Font\EmbeddedFont;
use MightyPDF\Content\Font\Font;
use MightyPDF\Content\Font\StandardFont;
use MightyPDF\Tests\Support\SyntheticTrueTypeFont;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The metrics that had to exist before text could be placed vertically
 * at all -- asserted as relationships between them rather than as
 * transcribed numbers, since a table typed in twice is not a check on
 * itself.
 */
final class VerticalMetricsTest extends TestCase
{
    /** @return list<array{StandardFont}> */
    public static function everyStandardFont(): array
    {
        return array_map(static fn (StandardFont $font): array => [$font], StandardFont::cases());
    }

    private static function embedded(): EmbeddedFont
    {
        return EmbeddedFont::fromBytes(SyntheticTrueTypeFont::build(), subset: false);
    }

    /**
     * The three of them have to be consistent with each other or a box
     * can be centred two ways and disagree: caps reach up but not as far
     * as the ascender, and every font drops below the baseline.
     */
    public function testEveryStandardFontsMetricsAreOrderedSensibly(): void
    {
        foreach (StandardFont::cases() as $font) {
            $ascent = $font->ascentPt(100.0);
            $descent = $font->descentPt(100.0);
            $capHeight = $font->capHeightPt(100.0);

            self::assertGreaterThan(0.0, $ascent, $font->name);
            self::assertGreaterThan(0.0, $descent, $font->name);
            self::assertGreaterThan(0.0, $capHeight, $font->name);
            self::assertLessThanOrEqual($ascent, $capHeight, "$font->name: caps cannot out-reach the ascender");
        }
    }

    /**
     * Descent is reported as a positive distance below the baseline,
     * the opposite sign to the AFM and the PDF font descriptor. The sign
     * is the whole reason Font documents it: every placement formula
     * adds it to the ascent.
     */
    public function testDescentIsPositiveDespiteTheAfmWritingItNegative(): void
    {
        self::assertGreaterThan(0.0, StandardFont::Helvetica->descentPt(12.0));

        // The synthetic file states -200 per 1000 units, so a positive
        // 0.2 of the type size is the flip actually happening rather
        // than the number merely being non-zero.
        self::assertSame(-200, SyntheticTrueTypeFont::DESCENT);
        self::assertEqualsWithDelta(2.4, self::embedded()->descentPt(12.0), 1e-9);
    }

    #[DataProvider('everyStandardFont')]
    public function testMetricsScaleLinearlyWithTheTypeSize(StandardFont $font): void
    {
        foreach ([1.0, 8.0, 12.0, 270.0] as $size) {
            self::assertEqualsWithDelta($font->ascentPt(1.0) * $size, $font->ascentPt($size), 1e-9);
            self::assertEqualsWithDelta($font->descentPt(1.0) * $size, $font->descentPt($size), 1e-9);
            self::assertEqualsWithDelta($font->capHeightPt(1.0) * $size, $font->capHeightPt($size), 1e-9);
        }
    }

    /**
     * The correction this release makes. Helvetica rises 0.718 of the
     * nominal size, not the flat 0.8 this enum used to report for every
     * one of the fourteen -- a number that was a stand-in for metrics
     * nothing here had. The old value put top-aligned text 0.082 of the
     * type size low: under a point in body copy, and 22pt at the size a
     * cover-page letter is set in.
     */
    public function testTheStandardFontsReportTheirOwnAscentRatherThanAFlatEightTenths(): void
    {
        self::assertEqualsWithDelta(0.718, StandardFont::Helvetica->ascentPt(1.0), 5e-4);
        self::assertEqualsWithDelta(0.683, StandardFont::TimesRoman->ascentPt(1.0), 5e-4);
        self::assertEqualsWithDelta(0.629, StandardFont::Courier->ascentPt(1.0), 5e-4);

        // They genuinely differ, which is what a single constant cannot express.
        self::assertNotEqualsWithDelta(
            StandardFont::Helvetica->ascentPt(1.0),
            StandardFont::Courier->ascentPt(1.0),
            0.01,
        );
    }

    /**
     * The four Helvetica cuts share one set of metrics, as do the four
     * Courier ones -- an oblique is a shear of the same glyphs, and the
     * AFMs agree. The Times cuts do not: their cap heights were drawn
     * separately, which is why they are listed separately.
     */
    public function testCutsOfOneFamilyAgreeWhereTheAfmsDo(): void
    {
        self::assertSame(
            StandardFont::Helvetica->capHeightPt(10.0),
            StandardFont::HelveticaBoldOblique->capHeightPt(10.0),
        );
        self::assertSame(
            StandardFont::Courier->capHeightPt(10.0),
            StandardFont::CourierBold->capHeightPt(10.0),
        );
        self::assertNotSame(
            StandardFont::TimesRoman->capHeightPt(10.0),
            StandardFont::TimesBold->capHeightPt(10.0),
        );
    }

    /**
     * An embedded font answers from its own tables, so the numbers move
     * with the file rather than with a guess -- which is the difference
     * that makes a document set in a brand typeface line up.
     */
    public function testAnEmbeddedFontReadsItsMetricsFromTheFontFile(): void
    {
        $font = self::embedded();

        self::assertGreaterThan(0.0, $font->ascentPt(10.0));
        self::assertGreaterThan(0.0, $font->capHeightPt(10.0));
        self::assertLessThanOrEqual($font->ascentPt(10.0), $font->capHeightPt(10.0));

        // 800 and 700 per 1000 units per em, as the file's tables say.
        self::assertEqualsWithDelta(8.0, $font->ascentPt(10.0), 1e-9);
        self::assertEqualsWithDelta(7.0, $font->capHeightPt(10.0), 1e-9);
    }

    public function testTheContractIsAnsweredByEveryKindOfFont(): void
    {
        $fonts = [StandardFont::Helvetica, self::embedded()];

        foreach ($fonts as $font) {
            self::assertInstanceOf(Font::class, $font);
            self::assertIsFloat($font->descentPt(10.0));
            self::assertIsFloat($font->capHeightPt(10.0));
        }

        self::assertInstanceOf(EmbeddedFont::class, $fonts[1]);
    }
}
