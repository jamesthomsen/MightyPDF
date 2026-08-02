<?php

declare(strict_types=1);

namespace MightyPDF\Tests\Content\Font\TrueType;

use MightyPDF\Content\Font\TrueType\TrueTypeFile;
use MightyPDF\Content\Font\TrueType\TrueTypeSubset;
use MightyPDF\Tests\Support\SyntheticTrueTypeFont;
use PHPUnit\Framework\TestCase;

final class TrueTypeSubsetTest extends TestCase
{
    public function testKeepsOnlyTheGlyphsAskedFor(): void
    {
        $font = self::font();

        $subset = TrueTypeFile::fromBytes(
            TrueTypeSubset::build($font, [SyntheticTrueTypeFont::GLYPH_B]),
        );

        // .notdef, which the format reserves, plus the one asked for.
        self::assertSame(2, $subset->numGlyphs());
    }

    public function testRenumbersGlyphsInTheOrderAskedFor(): void
    {
        $font = self::font();

        $subset = TrueTypeFile::fromBytes(TrueTypeSubset::build($font, [
            SyntheticTrueTypeFont::GLYPH_B,
            SyntheticTrueTypeFont::GLYPH_A,
        ]));

        // The order is what the PDF side writes into content streams as
        // character ids, so it is part of the contract, not an accident.
        self::assertSame(SyntheticTrueTypeFont::ADVANCES[SyntheticTrueTypeFont::GLYPH_B], $subset->advanceWidth(1));
        self::assertSame(SyntheticTrueTypeFont::ADVANCES[SyntheticTrueTypeFont::GLYPH_A], $subset->advanceWidth(2));
    }

    public function testCarriesGlyphOutlinesOverUnchanged(): void
    {
        $font = self::font();

        $subset = TrueTypeFile::fromBytes(
            TrueTypeSubset::build($font, [SyntheticTrueTypeFont::GLYPH_A]),
        );

        self::assertSame($font->glyphData(SyntheticTrueTypeFont::GLYPH_A), $subset->glyphData(1));
    }

    /**
     * An accented letter is drawn as a base glyph plus a mark, by
     * number. Subsetting has to bring those components along -- a
     * composite whose components were dropped draws nothing at all.
     */
    public function testPullsInTheComponentsOfCompositeGlyphs(): void
    {
        $font = self::font();

        $subset = TrueTypeFile::fromBytes(
            TrueTypeSubset::build($font, [SyntheticTrueTypeFont::GLYPH_A_ACUTE]),
        );

        // .notdef, the composite itself, and its two components.
        self::assertSame(4, $subset->numGlyphs());
    }

    public function testRenumbersTheComponentsInsideACompositeGlyph(): void
    {
        $font = self::font();

        $subset = TrueTypeFile::fromBytes(
            TrueTypeSubset::build($font, [SyntheticTrueTypeFont::GLYPH_A_ACUTE]),
        );

        // The composite asked for is glyph 1; its components were
        // appended after it, becoming 2 and 3. Left pointing at their
        // original numbers, the glyph would draw whatever now sits at
        // those positions -- which is the kind of wrong that renders
        // perfectly and shows the wrong letter.
        self::assertSame([2, 3], self::componentsOf($subset->glyphData(1)));
    }

    public function testComponentsAlreadyInTheSubsetAreNotDuplicated(): void
    {
        $font = self::font();

        $subset = TrueTypeFile::fromBytes(TrueTypeSubset::build($font, [
            SyntheticTrueTypeFont::GLYPH_A,
            SyntheticTrueTypeFont::GLYPH_ACUTE,
            SyntheticTrueTypeFont::GLYPH_A_ACUTE,
        ]));

        self::assertSame(4, $subset->numGlyphs());
        self::assertSame([1, 2], self::componentsOf($subset->glyphData(3)));
    }

    public function testKeepsTheHintingTables(): void
    {
        $font = self::font();

        $subset = TrueTypeFile::fromBytes(
            TrueTypeSubset::build($font, [SyntheticTrueTypeFont::GLYPH_A]),
        );

        // Nothing in the synthetic font is hinted, so what is checked
        // here is the shape of the result: the tables a CIDFontType2
        // program is required to carry, and no others.
        self::assertSame(['glyf', 'head', 'hhea', 'hmtx', 'loca', 'maxp'], $subset->tableTags());
    }

    /**
     * The short 'loca' format stores halved offsets and so cannot
     * describe a table over 128KB. One page of CJK text is enough to
     * pass that, and the failure is silent -- glyphs read from the wrong
     * offsets are still outlines, just not the right ones.
     */
    public function testAlwaysUsesTheLongGlyphOffsetFormat(): void
    {
        $font = self::font();

        $subset = TrueTypeFile::fromBytes(
            TrueTypeSubset::build($font, [SyntheticTrueTypeFont::GLYPH_A]),
        );

        self::assertSame(1, $subset->indexToLocFormat());
    }

    public function testTheSameGlyphsAlwaysProduceTheSameBytes(): void
    {
        $font = self::font();
        $glyphs = [SyntheticTrueTypeFont::GLYPH_A, SyntheticTrueTypeFont::GLYPH_A_ACUTE];

        self::assertSame(TrueTypeSubset::build($font, $glyphs), TrueTypeSubset::build($font, $glyphs));
    }

    /** A subset of nothing is still a font: .notdef alone. */
    public function testASubsetWithNoGlyphsStillHasNotdef(): void
    {
        $subset = TrueTypeFile::fromBytes(TrueTypeSubset::build(self::font(), []));

        self::assertSame(1, $subset->numGlyphs());
    }

    private static function font(): TrueTypeFile
    {
        return TrueTypeFile::fromBytes(SyntheticTrueTypeFont::build());
    }

    /**
     * The glyph numbers a composite glyph refers to, read straight out
     * of its bytes -- deliberately not through the subsetter's own
     * component walk, so that a bug there cannot hide itself.
     *
     * @return list<int>
     */
    private static function componentsOf(string $glyph): array
    {
        $components = [];
        $offset = 10;

        do {
            $flags = unpack('n', substr($glyph, $offset, 2))[1];
            $components[] = unpack('n', substr($glyph, $offset + 2, 2))[1];

            $offset += 4 + (($flags & 0x0001) !== 0 ? 4 : 2) + match (true) {
                ($flags & 0x0080) !== 0 => 8,
                ($flags & 0x0040) !== 0 => 4,
                ($flags & 0x0008) !== 0 => 2,
                default => 0,
            };
        } while (($flags & 0x0020) !== 0);

        return $components;
    }
}
