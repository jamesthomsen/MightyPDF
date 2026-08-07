<?php

declare(strict_types=1);

namespace MightyPDF\Tests\Content\Font;

use MightyPDF\Assembler\Types\WinAnsiEncoding;
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
     * The answer has to be the answer everywhere, so it is read off
     * CP1252's static table rather than from an iconv conversion: a
     * build that transliterates unasked (Apple's libiconv 1.11) hands
     * back "L" for "Ł" instead of false, and a probe built on that calls
     * this text supported on macOS and unsupported on a conforming
     * build. See WinAnsiEncodingTest for the repertoire itself.
     */
    public function testSupportIsDecidedByTheRepertoireAndNotByWhatIconvWillApproximate(): void
    {
        self::assertFalse(StandardFont::Helvetica->supports('Łódź'));
        self::assertFalse(StandardFont::Helvetica->supports('ﬁ'));
        // What it transliterates *to* is supported, which is the whole
        // distinction: "Lodz" is drawable, "Łódź" is what was asked for.
        self::assertTrue(StandardFont::Helvetica->supports('Lodz'));
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

    /**
     * The width tables have to cover every code WinAnsiEncoding can
     * emit, not just the ASCII range they originally held.
     *
     * An absent entry is not an error anyone sees: FontMetrics falls
     * back to 500 units, so the text still draws and is merely measured
     * wrong -- which moves every centred, right-aligned, wrapped and
     * justified line containing one. That silence is why this is
     * asserted structurally rather than by spot-checking a few codes.
     */
    public function testEveryCodeWinAnsiCanEmitHasAWidthOfItsOwn(): void
    {
        // 0x20-0x7E, then the CP1252 punctuation in 0x80-0x9F less the
        // five codes it leaves undefined, then the whole Latin-1 half.
        $expected = range(0x20, 0x7E);
        foreach (range(0x80, 0x9F) as $code) {
            if (!in_array($code, [0x81, 0x8D, 0x8F, 0x90, 0x9D], true)) {
                $expected[] = $code;
            }
        }
        $expected = [...$expected, ...range(0xA0, 0xFF)];

        foreach (['Helvetica', 'HelveticaBold', 'TimesRoman', 'TimesBold', 'TimesItalic', 'TimesBoldItalic'] as $family) {
            $widths = require __DIR__ . "/../../../src/Content/Font/Data/$family.php";

            self::assertSame([], array_diff($expected, array_keys($widths)), "$family is missing codes");
            self::assertSame([], array_diff(array_keys($widths), $expected), "$family has codes WinAnsi cannot emit");
        }
    }

    /**
     * Coverage alone would be satisfied by 218 wrong numbers, so this
     * checks the values against a property of the typeface: Adobe's
     * composed accented letters keep their base letter's advance, which
     * is why "résumé" and "resume" set to exactly the same width.
     */
    public function testAccentedLettersMeasureAsTheirBaseLetter(): void
    {
        foreach ([StandardFont::Helvetica, StandardFont::TimesRoman, StandardFont::TimesBoldItalic] as $font) {
            $metrics = $font->metrics();

            foreach (['é' => 'e', 'ü' => 'u', 'ñ' => 'n', 'Å' => 'A', 'Ç' => 'C', 'Ý' => 'Y'] as $accented => $base) {
                self::assertSame(
                    $metrics->widthOfCode(ord($base)),
                    $metrics->widthOfCode(ord(WinAnsiEncoding::encode($accented))),
                    "$font->name: $accented should measure as $base",
                );
            }

            self::assertSame(
                $font->widthOfPt('resume', 10.0),
                $font->widthOfPt('résumé', 10.0),
                $font->name,
            );
        }
    }

    /**
     * The dashes are the case the 500-unit default got most wrong: an em
     * dash is a full em, so it was measured at half its real width in
     * every line of formatted prose that used one.
     */
    public function testTheDashesAndQuotesAreNotTheDefaultWidth(): void
    {
        $metrics = StandardFont::Helvetica->metrics();

        self::assertSame(1000, $metrics->widthOfCode(0x97), 'em dash');
        self::assertSame(556, $metrics->widthOfCode(0x96), 'en dash');
        self::assertSame(556, $metrics->widthOfCode(0x80), 'euro');
        self::assertSame(350, $metrics->widthOfCode(0x95), 'bullet');
        self::assertSame(222, $metrics->widthOfCode(0x91), 'left single quote');
    }

    /**
     * Non-breaking space and soft hyphen are drawn and measured as the
     * ordinary glyph (ISO 32000-2 Annex D), so they carry those widths
     * rather than falling to the default.
     */
    public function testNonBreakingSpaceAndSoftHyphenMeasureAsTheirPlainForms(): void
    {
        $metrics = StandardFont::TimesRoman->metrics();

        self::assertSame($metrics->widthOfCode(ord(' ')), $metrics->widthOfCode(0xA0));
        self::assertSame($metrics->widthOfCode(ord('-')), $metrics->widthOfCode(0xAD));
    }

    /**
     * Family-and-style resolution, for callers holding a font as data --
     * a config value, an SVG's font-family, a report ported from an API
     * whose call was setFont('Arial', 'B'). Every one of them was
     * writing this match by hand, including this library's own SVG
     * renderer until it started calling here.
     */
    public function testMatchingResolvesAFamilyNameAndStyleToACut(): void
    {
        self::assertSame(StandardFont::Helvetica, StandardFont::matching('Arial'));
        self::assertSame(StandardFont::HelveticaBold, StandardFont::matching('Arial', bold: true));
        self::assertSame(StandardFont::TimesBoldItalic, StandardFont::matching('Times', bold: true, italic: true));
        self::assertSame(StandardFont::CourierOblique, StandardFont::matching('monospace', italic: true));
    }

    public function testMatchingFallsBackToHelveticaForAnythingItCannotPlace(): void
    {
        self::assertSame(StandardFont::Helvetica, StandardFont::matching(null));
        self::assertSame(StandardFont::Helvetica, StandardFont::matching(''));
        self::assertSame(StandardFont::Helvetica, StandardFont::matching('Comic Sans MS'));
    }

    /** A CSS-style list means "the first of these you can honour". */
    public function testMatchingTakesTheFirstFamilyItCanHonour(): void
    {
        self::assertSame(StandardFont::TimesRoman, StandardFont::matching('Baskerville, Georgia, serif'));
        self::assertSame(StandardFont::Courier, StandardFont::matching('"SF Mono", monospace'));
    }
    /**
     * A character WinAnsiEncoding has no glyph for measures nothing.
     *
     * It still encodes -- CP1252 maps a tab to itself, so a tab arriving
     * in a name from a database column reaches the content stream as a
     * tab -- and a reader draws and advances nothing for it. Measuring
     * it at the table's 500-unit default charges half an em for ink that
     * will not be there, and moves every centred, right-aligned, wrapped
     * and justified line containing one.
     */
    public function testCharactersWithNoWinAnsiGlyphAddNoWidth(): void
    {
        foreach (StandardFont::cases() as $font) {
            self::assertSame(
                $font->widthOfPt('ab', 10.0),
                $font->widthOfPt("a\tb", 10.0),
                "$font->name measures a tab as nothing",
            );
            self::assertSame(0.0, $font->widthOfPt("\x7f", 10.0), "$font->name measures DEL as nothing");
        }
    }

    /** And every code that does have a glyph is untouched by that. */
    public function testEveryPrintableCodeKeepsTheWidthItsTableStates(): void
    {
        $helvetica = StandardFont::Helvetica->metrics();

        // H e l l o , _ W o r l d ! out of Adobe's table.
        self::assertEqualsWithDelta(68.676, StandardFont::Helvetica->widthOfPt('Hello, World!', 12.0), 1e-9);
        self::assertSame(278, $helvetica->widthOfCode(0x20), 'space');
        self::assertSame(556, $helvetica->widthOfCode(0xE9), 'e-acute');
        self::assertSame(1000, $helvetica->widthOfCode(0x97), 'em dash');
        self::assertSame(278, $helvetica->widthOfCode(0xA0), 'non-breaking space');
        self::assertSame(333, $helvetica->widthOfCode(0xAD), 'soft hyphen');
    }
}
