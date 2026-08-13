<?php

declare(strict_types=1);

namespace MightyPDF\Tests\Content\Text;

use MightyPDF\Content\Font\EmbeddedFont;
use MightyPDF\Content\Font\FontMetrics;
use MightyPDF\Content\Font\StandardFont;
use MightyPDF\Content\Text\TextWrapper;
use MightyPDF\Tests\Support\CountingFont;
use MightyPDF\Tests\Support\SyntheticTrueTypeFont;
use PHPUnit\Framework\TestCase;

final class TextWrapperTest extends TestCase
{
    /** Every character (including space) costs a fixed 600/1000 em -- 6pt at a 10pt size -- for easy arithmetic. */
    private function metrics(): FontMetrics
    {
        return FontMetrics::fixedWidth(600);
    }

    public function testShortTextFitsOnOneLine(): void
    {
        $lines = TextWrapper::wrap('Hello', $this->metrics(), 10.0, 100.0);

        self::assertSame(['Hello'], $lines);
    }

    public function testWrapsOnWordBoundariesWhenTooWide(): void
    {
        // "Hello World" = 11 chars * 6pt = 66pt > 40pt; "World Foo" = 9 * 6 = 54pt > 40pt.
        $lines = TextWrapper::wrap('Hello World Foo', $this->metrics(), 10.0, 40.0);

        self::assertSame(['Hello', 'World', 'Foo'], $lines);
    }

    public function testSingleWordWiderThanMaxWidthIsNotSplit(): void
    {
        $lines = TextWrapper::wrap('Supercalifragilisticexpialidocious short', $this->metrics(), 10.0, 40.0);

        self::assertSame(['Supercalifragilisticexpialidocious', 'short'], $lines);
    }

    public function testExplicitNewlinesForceLineBreaksIndependentOfWidth(): void
    {
        $lines = TextWrapper::wrap("Line1\nLine2", $this->metrics(), 10.0, 1000.0);

        self::assertSame(['Line1', 'Line2'], $lines);
    }

    public function testEmptyTextReturnsOneBlankLine(): void
    {
        self::assertSame([''], TextWrapper::wrap('', $this->metrics(), 10.0, 100.0));
    }

    public function testBlankLineWithinTextIsPreserved(): void
    {
        $lines = TextWrapper::wrap("First\n\nThird", $this->metrics(), 10.0, 1000.0);

        self::assertSame(['First', '', 'Third'], $lines);
    }

    // -- A short first line ---------------------------------------------

    /** Every character costs 6pt at a 10pt size, space included. */
    private function widthOf(): \Closure
    {
        return fn (string $text): float => mb_strlen($text) * 6.0;
    }

    /**
     * What Layout\Flow::write() is: a run starts partway along a line
     * something else is already on, and runs on at full width below it.
     */
    public function testOnlyTheFirstLineIsNarrowed(): void
    {
        // 18pt leaves room for "abc"; 60pt afterwards takes ten.
        $lines = TextWrapper::wrapRagged('abc def ghi jkl', $this->widthOf(), 18.0, 60.0);

        self::assertSame(['abc', 'def ghi', 'jkl'], $lines);
    }

    /**
     * A word that does not fit the space left, but would fit a whole
     * line, takes the whole line. The empty first element is how the
     * caller is told to move down past the space it declined to use.
     */
    public function testAWordTooWideForTheFirstLineStartsTheNextOne(): void
    {
        $lines = TextWrapper::wrapRagged('abcdef gh', $this->widthOf(), 18.0, 60.0);

        self::assertSame(['', 'abcdef gh'], $lines);
    }

    /**
     * A word too wide for *any* line is still placed rather than split
     * -- there is no hyphenation here and an arbitrary break would be
     * worse -- but it is placed on a whole line even so. It overflows
     * either way, and a whole line is the least it can overflow by.
     */
    public function testAWordTooWideForEveryLineTakesAWholeLineAnyway(): void
    {
        $lines = TextWrapper::wrapRagged('abcdefghijklmno p', $this->widthOf(), 18.0, 60.0);

        self::assertSame(['', 'abcdefghijklmno', 'p'], $lines);
    }

    /**
     * A newline puts the cursor back at the left margin, so the line
     * after it has the whole width whatever put it there -- the short
     * first line is the run's, not each paragraph's.
     */
    public function testOnlyTheVeryFirstLineIsShortAcrossExplicitNewlines(): void
    {
        $lines = TextWrapper::wrapRagged("abc\nabcdef", $this->widthOf(), 18.0, 60.0);

        self::assertSame(['abc', 'abcdef'], $lines);
    }

    /** Equal widths are the ordinary wrap, which is how wrapBy() is built. */
    public function testEqualWidthsAreTheSameWrapAsBefore(): void
    {
        $text = 'one two three four five six seven eight';

        self::assertSame(
            TextWrapper::wrapBy($text, $this->widthOf(), 60.0),
            TextWrapper::wrapRagged($text, $this->widthOf(), 60.0, 60.0),
        );
    }

    /** No room at all on this line: down a line, and no text lost. */
    public function testAFirstLineWithNoRoomLeftAtAllIsSkipped(): void
    {
        $lines = TextWrapper::wrapRagged('ab cd', $this->widthOf(), 0.0, 60.0);

        self::assertSame(['', 'ab cd'], $lines);
    }

    /**
     * wrapUtf8() measures through the font rather than through a width
     * table, and gives back text rather than encoded bytes -- what a
     * caller drawing the lines afterwards needs, since an embedded font
     * encodes its own text.
     */
    public function testWrapsThroughAnEmbeddedFontAndReturnsText(): void
    {
        $font = EmbeddedFont::fromBytes(SyntheticTrueTypeFont::build());

        // "A" is 600 and the space 250, so two of them are 1.45 wide at
        // 1pt: a 1.2pt-wide box fits exactly one per line.
        $lines = TextWrapper::wrapUtf8('A A A', $font, 1.0, 1.2);

        self::assertSame(['A', 'A', 'A'], $lines);
    }

    public function testWrappingThroughAStandardFontAgreesWithItsWidthTable(): void
    {
        $text = 'Hello World Foo';

        self::assertSame(
            TextWrapper::wrap($text, StandardFont::Helvetica->metrics(), 10.0, 40.0),
            TextWrapper::wrapUtf8($text, StandardFont::Helvetica, 10.0, 40.0),
        );
    }

    /**
     * Widths accumulate along the line rather than being re-measured
     * from its start, which is what turned a quadratic wrap linear: a
     * line of k words used to measure its own prefix k times.
     *
     * The wrap that produces has to be the wrap the naive loop produced,
     * so this drives both over the same inputs and compares. The widths
     * are deliberately chosen to land exactly on a word boundary, which
     * is where an accumulated total and a re-measured one can disagree
     * by a rounding error and pick different sides of the limit.
     */
    public function testAccumulatedWidthsWrapExactlyWhereRemeasuringWould(): void
    {
        $font = StandardFont::Helvetica;
        $size = 11.0;
        $text = 'the quick brown fox jumped over a notably lazy dog again and again';
        $words = explode(' ', $text);
        $widthOf = static fn (string $s): float => $font->widthOfPt($s, $size);

        $widths = [];

        foreach (array_keys($words) as $index) {
            $exact = $widthOf(implode(' ', array_slice($words, 0, $index + 1)));
            array_push($widths, $exact, $exact + 1e-12, $exact - 1e-12);
        }

        foreach ($widths as $maxWidthPt) {
            self::assertSame(
                self::naiveWrap($text, $widthOf, $maxWidthPt),
                TextWrapper::wrapBy($text, $widthOf, $maxWidthPt),
                "wrapping at $maxWidthPt",
            );
        }
    }

    /** The measure-every-prefix loop this replaced, kept as the oracle. */
    private static function naiveWrap(string $paragraph, \Closure $widthOf, float $maxWidthPt): array
    {
        $lines = [];
        $current = '';

        foreach (preg_split('/ +/', $paragraph) as $word) {
            $candidate = $current === '' ? $word : "$current $word";

            if ($current !== '' && $widthOf($candidate) > $maxWidthPt) {
                $lines[] = $current;
                $current = $word;
            } else {
                $current = $candidate;
            }
        }

        $lines[] = $current;

        return $lines;
    }

    /**
     * A line's words are measured once each, not once per word that
     * follows them.
     *
     * Measured in characters handed to the font rather than in seconds,
     * which is both deterministic and the thing that actually grew: the
     * old loop measured the accumulated line every time, so a line of k
     * words measured its own prefix k times. On a page-width box that is
     * invisible; on a wide one, 4000 words took 1.6 seconds and four
     * times the words took sixteen times as long.
     */
    public function testALongLineIsMeasuredOnceThroughRatherThanOncePerWord(): void
    {
        $words = 400;
        $font = new CountingFont(StandardFont::Helvetica);

        // A box nothing can fill, so every word lands on one line --
        // which is precisely the case the old loop was quadratic in.
        $text = rtrim(str_repeat('word ', $words));
        TextWrapper::wrapUtf8($text, $font, 10.0, 1.0e9);

        // Linear means the whole line is measured about once: 400 words
        // of four letters is 1600 characters, plus the space measured
        // once. Quadratic would be the sum of the prefixes, which for
        // this line is a little over 400,000.
        self::assertLessThan(strlen($text) * 2, $font->measuredBytes);
    }

    /**
     * Asking twice is free, which is what makes measure-then-draw cost
     * one wrap: Layout\Flow::paragraph() sizes a box from the line count
     * and then hands the same text to a drawing call that wraps it again
     * to place it.
     */
    public function testRepeatingTheLastWrapIsAnsweredFromTheLastWrap(): void
    {
        $font = new CountingFont(StandardFont::Helvetica);
        $text = str_repeat('the quick brown fox ', 40);

        TextWrapper::wrapUtf8($text, $font, 10.0, 200.0);
        $afterFirst = $font->calls;

        self::assertGreaterThan(0, $afterFirst);

        TextWrapper::wrapUtf8($text, $font, 10.0, 200.0);

        self::assertSame($afterFirst, $font->calls);
    }

    /**
     * ...and the answer is still the right one for the arguments given.
     *
     * Every argument that changes the wrap is part of what is
     * remembered, so each of these is compared against the wrap computed
     * from scratch rather than against the others: two fonts can agree
     * on where a particular string breaks, and a test that only checked
     * the results differ would pass on a key that ignored the font.
     */
    public function testTheRememberedWrapIsNotReusedForDifferentArguments(): void
    {
        $text = 'Hello World Foo Bar';

        $calls = [
            [StandardFont::Helvetica, 10.0, 40.0],
            [StandardFont::Helvetica, 10.0, 400.0],
            [StandardFont::Helvetica, 10.0, 40.0],
            [StandardFont::Helvetica, 24.0, 40.0],
            [StandardFont::TimesRoman, 10.0, 40.0],
            [StandardFont::Courier, 10.0, 40.0],
        ];

        foreach ($calls as [$font, $sizePt, $maxWidthPt]) {
            self::assertSame(
                TextWrapper::wrapBy(
                    $text,
                    static fn (string $s): float => $font->widthOfPt($s, $sizePt),
                    $maxWidthPt,
                ),
                TextWrapper::wrapUtf8($text, $font, $sizePt, $maxWidthPt),
                "$font->name at {$sizePt}pt in a {$maxWidthPt}pt box",
            );
        }
    }
}
