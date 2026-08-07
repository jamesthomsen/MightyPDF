<?php

declare(strict_types=1);

namespace MightyPDF\Tests\Content\Text;

use MightyPDF\Content\Font\Font;
use MightyPDF\Content\Font\StandardFont;
use MightyPDF\Content\Text\HorizontalAlign;
use MightyPDF\Content\Text\TextPlacement;
use MightyPDF\Content\Text\VerticalAlign;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The arithmetic that a hardcoded fraction of the type size used to
 * stand in for.
 *
 * Everything here is asserted as a geometric property -- the space above
 * equals the space below, the block bottom lands on the box bottom --
 * rather than against a number computed the same way the code computes
 * it. A test that restates the formula passes for any consistent
 * formula, including a wrong one; a test that says "centred means
 * equidistant" only passes for a centred one.
 *
 * Every case runs across a range of type sizes ending at 270pt, because
 * that is the axis this class exists for: an error that is a fraction of
 * the size is a rounding difference in a 10pt table and centimetres in a
 * cover-page letter, so a test at one small size proves almost nothing.
 */
final class TextPlacementTest extends TestCase
{
    /** Body copy through to the display size that made the bug visible. */
    private const array SIZES = [8.0, 10.0, 12.0, 24.0, 72.0, 270.0];

    /** @return list<array{Font}> */
    public static function fonts(): array
    {
        return [
            'Helvetica' => [StandardFont::Helvetica],
            'Times' => [StandardFont::TimesRoman],
            'Courier' => [StandardFont::Courier],
            'Times bold italic' => [StandardFont::TimesBoldItalic],
        ];
    }

    /**
     * CapMiddle is what centres a grade placard, a table heading or a
     * figure: the cap box sits with equal air above and below it.
     */
    #[DataProvider('fonts')]
    public function testCapMiddleLeavesEqualSpaceAboveAndBelowTheCapitals(Font $font): void
    {
        foreach (self::SIZES as $size) {
            $baseline = TextPlacement::baselineY($font, $size, 100.0, 60.0, VerticalAlign::CapMiddle);

            $below = $baseline - 100.0;
            $above = 160.0 - ($baseline + $font->capHeightPt($size));

            self::assertEqualsWithDelta($below, $above, 1e-9, "at {$size}pt");
        }
    }

    /**
     * Middle centres the em box instead, so a line keeps its place when
     * the wording gains or loses a descender.
     */
    #[DataProvider('fonts')]
    public function testMiddleLeavesEqualSpaceAboveTheAscentAndBelowTheDescent(Font $font): void
    {
        foreach (self::SIZES as $size) {
            $baseline = TextPlacement::baselineY($font, $size, 100.0, 60.0, VerticalAlign::Middle);

            $below = ($baseline - $font->descentPt($size)) - 100.0;
            $above = 160.0 - ($baseline + $font->ascentPt($size));

            self::assertEqualsWithDelta($below, $above, 1e-9, "at {$size}pt");
        }
    }

    /**
     * The two middles are not interchangeable, and the gap between them
     * is half the descent -- a point at body sizes, most of a centimetre
     * on a cover page. Choosing between them is the whole reason both
     * exist, so a change that quietly collapsed them would pass every
     * other test here.
     */
    public function testTheTwoMiddlesDifferByHalfTheDescent(): void
    {
        $font = StandardFont::Helvetica;

        foreach (self::SIZES as $size) {
            $middle = TextPlacement::baselineY($font, $size, 0.0, 60.0, VerticalAlign::Middle);
            $capMiddle = TextPlacement::baselineY($font, $size, 0.0, 60.0, VerticalAlign::CapMiddle);

            self::assertEqualsWithDelta($font->descentPt($size) / 2, $middle - $capMiddle, 1e-9, "at {$size}pt");
        }
    }

    #[DataProvider('fonts')]
    public function testTopHangsTheAscentFromTheBoxTop(Font $font): void
    {
        foreach (self::SIZES as $size) {
            self::assertEqualsWithDelta(
                160.0 - $font->ascentPt($size),
                TextPlacement::baselineY($font, $size, 100.0, 60.0, VerticalAlign::Top),
                1e-9,
                "at {$size}pt",
            );
        }
    }

    #[DataProvider('fonts')]
    public function testBottomStandsTheDescentOnTheBoxBottom(Font $font): void
    {
        foreach (self::SIZES as $size) {
            $baseline = TextPlacement::baselineY($font, $size, 100.0, 60.0, VerticalAlign::Bottom);

            self::assertEqualsWithDelta(100.0, $baseline - $font->descentPt($size), 1e-9, "at {$size}pt");
        }
    }

    /**
     * The identity the whole design rests on: one wrapped line and one
     * unwrapped line are placed by the same calculation, so a paragraph
     * and a cell of the same geometry sit on the same baseline. It holds
     * for every alignment and every line height, since a single line has
     * no leading below it to disagree about.
     *
     * This is the gap that forced hand-wrapping in the port that
     * prompted the layer: drawParagraph() placed by ascent while
     * cell-style layout placed by box height, so the two could not be
     * lined up against each other at all.
     */
    public function testOneWrappedLineLandsExactlyWhereAnUnwrappedOneDoes(): void
    {
        foreach (self::fonts() as [$font]) {
            foreach (self::SIZES as $size) {
                foreach (VerticalAlign::cases() as $valign) {
                    foreach ([0.0, 8.0, $size * 1.15, $size * 3] as $lineHeight) {
                        self::assertSame(
                            TextPlacement::baselineY($font, $size, 12.0, 40.0, $valign),
                            TextPlacement::firstBaselineY($font, $size, 12.0, 40.0, 1, $lineHeight, $valign),
                            sprintf('%s at %gpt, line height %g', $valign->name, $size, $lineHeight),
                        );
                    }
                }
            }
        }
    }

    /**
     * A block of several lines is centred as a block: the air above the
     * first line's ascent equals the air below the last line's descent.
     */
    public function testAMultiLineBlockIsCentredAsAWhole(): void
    {
        $font = StandardFont::Helvetica;

        foreach (self::SIZES as $size) {
            $lineHeight = $size * 1.15;
            $first = TextPlacement::firstBaselineY($font, $size, 0.0, 400.0, 4, $lineHeight, VerticalAlign::Middle);
            $last = $first - 3 * $lineHeight;

            $above = 400.0 - ($first + $font->ascentPt($size));
            $below = ($last - $font->descentPt($size)) - 0.0;

            self::assertEqualsWithDelta($above, $below, 1e-9, "at {$size}pt");
        }
    }

    /**
     * Bottom-aligning a block puts its *last* descender on the box
     * bottom, however many lines it has -- which is what lines the final
     * line of two unequal columns up with each other.
     */
    public function testABottomAlignedBlockStandsItsLastLineOnTheBoxBottom(): void
    {
        $font = StandardFont::TimesRoman;

        foreach ([1, 2, 5] as $lines) {
            $first = TextPlacement::firstBaselineY($font, 12.0, 30.0, 200.0, $lines, 14.0, VerticalAlign::Bottom);
            $last = $first - ($lines - 1) * 14.0;

            self::assertEqualsWithDelta(30.0, $last - $font->descentPt(12.0), 1e-9, "$lines lines");
        }
    }

    /**
     * Top alignment is deliberately independent of how much text there
     * is, so a row of boxes holding one, three and six lines still share
     * a top rule.
     */
    public function testTopAlignmentIgnoresTheLineCount(): void
    {
        $font = StandardFont::Helvetica;
        $expected = TextPlacement::firstBaselineY($font, 11.0, 0.0, 90.0, 1, 13.0, VerticalAlign::Top);

        foreach ([2, 3, 7] as $lines) {
            self::assertSame(
                $expected,
                TextPlacement::firstBaselineY($font, 11.0, 0.0, 90.0, $lines, 13.0, VerticalAlign::Top),
            );
        }
    }

    /**
     * Why a metric was needed rather than a better constant.
     *
     * FPDF centres with `0.3 * fontSize`, which is a stand-in for half
     * the cap height. But cap height is a property of the typeface:
     * centring Helvetica needs 0.359 and Courier 0.281, so any single
     * constant is wrong for at least one of them, and it is wrong by a
     * fraction of the type size -- which is how the same code passes
     * review in a 10pt table and ships a display letter centimetres out.
     */
    public function testNoOneConstantFractionCanCentreEveryStandardFont(): void
    {
        $required = [];

        foreach ([StandardFont::Helvetica, StandardFont::TimesRoman, StandardFont::Courier] as $font) {
            // The offset from the box's centre line to the baseline,
            // as a fraction of the type size, for every size at once.
            $fractions = [];

            foreach (self::SIZES as $size) {
                $baseline = TextPlacement::baselineY($font, $size, 0.0, 100.0, VerticalAlign::CapMiddle);
                $fractions[] = round((50.0 - $baseline) / $size, 12);
            }

            self::assertCount(1, array_unique($fractions), 'the fraction is scale-free within one font');
            $required[$font->name] = $fractions[0];
        }

        self::assertEqualsWithDelta(0.359, $required['Helvetica'], 0.0005);
        self::assertEqualsWithDelta(0.281, $required['Courier'], 0.0005);

        // At 270pt that spread is 21pt -- 7.4mm -- of misplacement for
        // whichever font the constant was not chosen for.
        $spread = abs($required['Helvetica'] - $required['Courier']) * 270.0;
        self::assertGreaterThan(15.0, $spread);
    }

    public function testLineXPlacesALineWithinItsBox(): void
    {
        self::assertSame(100.0, TextPlacement::lineX(HorizontalAlign::Left, 100.0, 60.0, 20.0));
        self::assertSame(120.0, TextPlacement::lineX(HorizontalAlign::Center, 100.0, 60.0, 20.0));
        self::assertSame(140.0, TextPlacement::lineX(HorizontalAlign::Right, 100.0, 60.0, 20.0));

        // Justified lines are stretched by the caller, which alone knows
        // whether this is the last line of the paragraph.
        self::assertSame(100.0, TextPlacement::lineX(HorizontalAlign::Justify, 100.0, 60.0, 20.0));
    }

    public function testBlockHeightMeasuresInkToInkRatherThanByLineHeight(): void
    {
        $font = StandardFont::Helvetica;

        // One line occupies its ascent plus its descent -- not a whole
        // line height, which would count leading below the last line.
        self::assertEqualsWithDelta(
            $font->ascentPt(10.0) + $font->descentPt(10.0),
            TextPlacement::blockHeightPt($font, 10.0, 1, 11.5),
            1e-9,
        );

        self::assertEqualsWithDelta(
            $font->ascentPt(10.0) + $font->descentPt(10.0) + 2 * 11.5,
            TextPlacement::blockHeightPt($font, 10.0, 3, 11.5),
            1e-9,
        );
    }
}
