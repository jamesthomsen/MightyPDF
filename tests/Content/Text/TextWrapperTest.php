<?php

declare(strict_types=1);

namespace MightyPDF\Tests\Content\Text;

use MightyPDF\Content\Font\FontMetrics;
use MightyPDF\Content\Text\TextWrapper;
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
}
