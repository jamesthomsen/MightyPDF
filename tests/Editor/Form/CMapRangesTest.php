<?php

declare(strict_types=1);

namespace MightyPDF\Tests\Editor\Form;

use MightyPDF\Editor\Form\CMapRanges;
use PHPUnit\Framework\TestCase;

final class CMapRangesTest extends TestCase
{
    public function testReadsTheCidRangesOfAnEncodingCMap(): void
    {
        $cmap = CMapRanges::encoding(
            "2 begincidrange\n<0041> <005A> 36\n<0061> <007A> 68\nendcidrange\n"
            . "1 begincidchar\n<0020> 3\nendcidchar\n",
        );

        self::assertSame(36, $cmap->valueFor("\x00\x41"));
        self::assertSame(38, $cmap->valueFor("\x00\x43"));
        self::assertSame(68, $cmap->valueFor("\x00\x61"));
        self::assertSame(3, $cmap->valueFor("\x00\x20"));
        self::assertNull($cmap->valueFor("\x00\x01"));
    }

    /**
     * Filling a field needs the map backwards: the value is text, and
     * what has to be written is codes. That is what a reader does to lay
     * a typed value out, so an appearance built this way agrees with the
     * one the reader would have drawn.
     */
    public function testReadsAToUnicodeCMapBackwards(): void
    {
        $cmap = CMapRanges::toUnicode(
            "1 beginbfrange\n<0041> <005A> <0041>\nendbfrange\n"
            . "1 beginbfchar\n<0003> <00E9>\nendbfchar\n",
        );

        self::assertSame("\x00\x41", $cmap->codeFor(0x41));
        self::assertSame("\x00\x5A", $cmap->codeFor(0x5A));
        self::assertSame("\x00\x03", $cmap->codeFor(0xE9));
        self::assertNull($cmap->codeFor(0x5B));
    }

    /** Identity-H's own /ToUnicode: codes are glyph numbers, one per character. */
    public function testReadsAGlyphKeyedToUnicodeMap(): void
    {
        $cmap = CMapRanges::toUnicode("2 beginbfchar\n<0001> <0048>\n<0002> <0069>\nendbfchar\n");

        self::assertSame("\x00\x01", $cmap->codeFor(0x48));
        self::assertSame("\x00\x02", $cmap->codeFor(0x69));
    }

    /**
     * A character past the BMP is written as the surrogate pair UTF-16
     * makes of it, which has to be read back as the one character it
     * stands for.
     */
    public function testReadsASurrogatePairAsOneCharacter(): void
    {
        $cmap = CMapRanges::toUnicode("1 beginbfchar\n<0005> <D83DDE00>\nendbfchar\n");

        self::assertSame("\x00\x05", $cmap->codeFor(0x1F600));
    }

    /**
     * A code standing for several characters -- a ligature -- has no one
     * code point, and no way back from text that this could use. Skipped
     * rather than guessed at.
     */
    public function testACodeStandingForSeveralCharactersIsSkipped(): void
    {
        $cmap = CMapRanges::toUnicode("2 beginbfchar\n<0004> <00660066>\n<0005> <0066>\nendbfchar\n");

        self::assertSame("\x00\x05", $cmap->codeFor(0x66));
        self::assertNull($cmap->codeFor(0x660066));
    }

    /** Codes are not always two bytes wide, and their width is how they are written. */
    public function testCodeWidthComesFromHowTheCodeIsWritten(): void
    {
        $cmap = CMapRanges::encoding("1 begincidrange\n<20> <7E> 1\nendcidrange\n");

        self::assertSame(1, $cmap->valueFor("\x20"));
        self::assertNull($cmap->valueFor("\x00\x20"), 'a two-byte code is not the same code');
        self::assertSame("\x21", $cmap->codeFor(2));
    }

    public function testANonsenseCMapIsEmptyRatherThanWrong(): void
    {
        self::assertTrue(CMapRanges::toUnicode('this is not a CMap at all')->isEmpty());
        self::assertTrue(CMapRanges::empty()->isEmpty());
    }
}
