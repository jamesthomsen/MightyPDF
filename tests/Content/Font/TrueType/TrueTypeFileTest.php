<?php

declare(strict_types=1);

namespace MightyPDF\Tests\Content\Font\TrueType;

use MightyPDF\Content\Font\TrueType\FontException;
use MightyPDF\Content\Font\TrueType\TrueTypeFile;
use MightyPDF\Tests\Support\SyntheticTrueTypeFont;
use PHPUnit\Framework\TestCase;

final class TrueTypeFileTest extends TestCase
{
    public function testReadsTheHeadlineNumbersOutOfAFont(): void
    {
        $font = TrueTypeFile::fromBytes(SyntheticTrueTypeFont::build());

        self::assertSame(SyntheticTrueTypeFont::UNITS_PER_EM, $font->unitsPerEm());
        self::assertSame(7, $font->numGlyphs());
        self::assertSame(SyntheticTrueTypeFont::POSTSCRIPT_NAME, $font->postScriptName());
        self::assertContains('glyf', $font->tableTags());
    }

    public function testMapsCharactersToGlyphs(): void
    {
        $font = TrueTypeFile::fromBytes(SyntheticTrueTypeFont::build());

        foreach (SyntheticTrueTypeFont::CHARACTERS as $codePoint => $glyph) {
            self::assertSame($glyph, $font->glyphForCodePoint($codePoint), sprintf('U+%04X', $codePoint));
        }
    }

    /**
     * A format 4 subtable is 16-bit and cannot express this character at
     * all, so reading it proves the format 12 subtable was read too --
     * the case a reader that takes only the first subtable it finds gets
     * wrong.
     */
    public function testReachesCharactersPastTheBasicMultilingualPlane(): void
    {
        $font = TrueTypeFile::fromBytes(SyntheticTrueTypeFont::build());

        self::assertSame(
            SyntheticTrueTypeFont::GLYPH_ASTRAL,
            $font->glyphForCodePoint(SyntheticTrueTypeFont::ASTRAL_CODE_POINT),
        );
    }

    public function testReportsAMissingCharacterAsMissing(): void
    {
        $font = TrueTypeFile::fromBytes(SyntheticTrueTypeFont::build());

        self::assertNull($font->glyphForCodePoint(0x0043));
    }

    public function testReadsAdvanceWidths(): void
    {
        $font = TrueTypeFile::fromBytes(SyntheticTrueTypeFont::build());

        foreach (SyntheticTrueTypeFont::ADVANCES as $glyph => $advance) {
            self::assertSame($advance, $font->advanceWidth($glyph), "glyph $glyph");
        }
    }

    /**
     * A font may store one advance for a whole run of trailing glyphs
     * (how monospaced and CJK fonts save space), and reading past the
     * array must return that shared advance rather than nothing.
     */
    public function testGlyphsPastTheMetricsArrayShareTheLastAdvance(): void
    {
        $font = TrueTypeFile::fromBytes(self::withHalfTheMetrics());

        self::assertSame(SyntheticTrueTypeFont::ADVANCES[2], $font->advanceWidth(5));
    }

    public function testReadsTheMetricsADescriptorNeeds(): void
    {
        $metrics = TrueTypeFile::fromBytes(SyntheticTrueTypeFont::build())->metrics();

        self::assertSame(SyntheticTrueTypeFont::ASCENT, $metrics->ascent);
        self::assertSame(SyntheticTrueTypeFont::DESCENT, $metrics->descent);
        self::assertSame(SyntheticTrueTypeFont::CAP_HEIGHT, $metrics->capHeightInGlyphSpace());
        self::assertSame([0, -200, 500, 900], $metrics->boundingBox());
        self::assertFalse($metrics->isItalic);
    }

    /** 2048 units per em is as common as 1000, and PDF only understands the latter. */
    public function testConvertsFontUnitsToPdfGlyphSpace(): void
    {
        $metrics = TrueTypeFile::fromBytes(SyntheticTrueTypeFont::build())->metrics();

        self::assertSame(600, $metrics->toGlyphSpace(600));
    }

    public function testEmptyGlyphsHaveNoOutline(): void
    {
        $font = TrueTypeFile::fromBytes(SyntheticTrueTypeFont::build());

        self::assertSame('', $font->glyphData(0));
        self::assertNotSame('', $font->glyphData(SyntheticTrueTypeFont::GLYPH_A));
    }

    /**
     * Everything around the outlines is the same sfnt container in both
     * formats, which is why one parser reads both -- what differs is
     * where the outlines are and what can be done with them.
     */
    public function testReadsAnOpenTypeFontWithPostScriptOutlines(): void
    {
        $font = TrueTypeFile::fromBytes(SyntheticTrueTypeFont::withPostScriptOutlines());

        self::assertTrue($font->hasCffOutlines());
        self::assertFalse($font->hasCidKeyedCff());
        self::assertSame(SyntheticTrueTypeFont::POSTSCRIPT_NAME, $font->postScriptName());
        self::assertSame(SyntheticTrueTypeFont::GLYPH_A, $font->glyphForCodePoint(0x41));
        self::assertSame(SyntheticTrueTypeFont::ADVANCES[SyntheticTrueTypeFont::GLYPH_B], $font->advanceWidth(SyntheticTrueTypeFont::GLYPH_B));
    }

    /**
     * A CID-keyed CFF addresses its glyphs through a character
     * collection of its own rather than by index, so embedding one and
     * addressing it by index would draw the wrong glyphs -- both
     * numbering schemes being dense small integers, it would look like a
     * font, just the wrong one.
     */
    public function testFindsWhetherACffIsCidKeyed(): void
    {
        $font = TrueTypeFile::fromBytes(SyntheticTrueTypeFont::withPostScriptOutlines(cidKeyed: true));

        self::assertTrue($font->hasCidKeyedCff());
    }

    public function testRefusesAFontWithNoOutlinesAtAll(): void
    {
        $this->expectException(FontException::class);
        $this->expectExceptionMessageMatches('/no outlines/');

        TrueTypeFile::fromBytes(SyntheticTrueTypeFont::withoutOutlines());
    }

    public function testRefusesFontCollections(): void
    {
        $this->expectException(FontException::class);
        $this->expectExceptionMessageMatches('/collection/');

        TrueTypeFile::fromBytes('ttcf' . substr(SyntheticTrueTypeFont::build(), 4));
    }

    public function testRefusesSomethingThatIsNotAFont(): void
    {
        $this->expectException(FontException::class);

        TrueTypeFile::fromBytes('%PDF-1.7 this is not a font at all');
    }

    /**
     * Truncation has to be an error rather than a short read: unpack()
     * past the end of a string yields nothing, which would otherwise
     * read as a zero and turn a broken file into a font with no glyphs.
     */
    public function testRefusesATruncatedFont(): void
    {
        $this->expectException(FontException::class);

        TrueTypeFile::fromBytes(substr(SyntheticTrueTypeFont::build(), 0, 400));
    }

    public function testRefusesAFontWhoseTablePointsOutsideTheFile(): void
    {
        $bytes = SyntheticTrueTypeFont::build();
        $directoryEntry = 12 + 16; // the second table's record

        $this->expectException(FontException::class);
        $this->expectExceptionMessageMatches('/outside the file/');

        TrueTypeFile::fromBytes(substr_replace($bytes, pack('N', 0xFFFFFF), $directoryEntry + 8, 4));
    }

    /** The same font with 'hhea' claiming only three of its six glyphs carry an advance. */
    private static function withHalfTheMetrics(): string
    {
        $bytes = SyntheticTrueTypeFont::build();
        $count = unpack('n', substr($bytes, 4, 2))[1];

        for ($i = 0; $i < $count; ++$i) {
            $record = 12 + $i * 16;

            if (substr($bytes, $record, 4) === 'hhea') {
                $offset = unpack('N', substr($bytes, $record + 8, 4))[1];

                return substr_replace($bytes, pack('n', 3), $offset + 34, 2);
            }
        }

        self::fail('The synthetic font has no "hhea" table.');
    }
}
