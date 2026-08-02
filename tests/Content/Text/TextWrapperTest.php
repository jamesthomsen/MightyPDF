<?php

declare(strict_types=1);

namespace MightyPDF\Tests\Content\Text;

use MightyPDF\Content\Font\EmbeddedFont;
use MightyPDF\Content\Font\FontMetrics;
use MightyPDF\Content\Font\StandardFont;
use MightyPDF\Content\Text\TextWrapper;
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
}
