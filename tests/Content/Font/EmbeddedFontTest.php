<?php

declare(strict_types=1);

namespace MightyPDF\Tests\Content\Font;

use MightyPDF\Assembler\Document;
use MightyPDF\Content\Font\EmbeddedFont;
use MightyPDF\Content\Font\TrueType\FontException;
use MightyPDF\Content\PageBuilder;
use MightyPDF\Tests\Support\SyntheticTrueTypeFont;
use PHPUnit\Framework\TestCase;

final class EmbeddedFontTest extends TestCase
{
    public function testMeasuresTextFromTheFontsOwnAdvanceWidths(): void
    {
        $font = self::font();

        // "AB" is 600 + 700 thousandths of an em, at 10pt.
        self::assertEqualsWithDelta(13.0, $font->widthOfPt('AB', 10.0), 0.0001);
    }

    public function testMeasuresTextPastTheBasicMultilingualPlane(): void
    {
        $font = self::font();

        self::assertEqualsWithDelta(8.0, $font->widthOfPt("\u{1F600}", 10.0), 0.0001);
    }

    public function testReportsItsAscentFromTheFontRatherThanAnApproximation(): void
    {
        self::assertEqualsWithDelta(8.0, self::font()->ascentPt(10.0), 0.0001);
    }

    public function testReportsWhichCharactersItCannotDraw(): void
    {
        $font = self::font();

        // Reported once each, in the order they appear -- the point is
        // to choose a different font, not to be told about "C" twice.
        self::assertSame(['C', 'D'], $font->missingCharacters('ABCDAC'));
        self::assertTrue($font->supports('AB'));
        self::assertFalse($font->supports('ABC'));
    }

    /**
     * Measuring must not throw where drawing does: a caller lays text
     * out before it draws it, and one error from the draw call is
     * enough.
     */
    public function testMeasuringTextWithMissingCharactersDoesNotThrow(): void
    {
        self::assertGreaterThan(0.0, self::font()->widthOfPt('ABC', 10.0));
    }

    public function testDrawingACharacterTheFontLacksSaysSo(): void
    {
        $document = new Document();
        $content = new PageBuilder($document, $document->newPage());

        $this->expectException(FontException::class);
        $this->expectExceptionMessageMatches('/no glyph for "C" \(U\+0043\)/');

        $content->drawText(self::font(), 12.0, 72, 700, 'ABC');
    }

    /**
     * The same font file loaded twice is one font: it is the largest
     * thing in the document, and embedding it a second time would double
     * that for nothing.
     */
    public function testTheSameFontFileSharesOneFontObject(): void
    {
        $document = new Document();
        $content = new PageBuilder($document, $document->newPage());

        $content->drawText(self::font(), 12.0, 72, 700, 'A');
        $content->drawText(self::font(), 12.0, 72, 680, 'B');

        self::assertSame(1, substr_count($document->save(), '/Subtype /Type0'));
    }

    public function testAFontEmbeddedWholeAndTheSameFontSubsetAreNotShared(): void
    {
        $document = new Document();
        $content = new PageBuilder($document, $document->newPage());

        $content->drawText(self::font(), 12.0, 72, 700, 'A');
        $content->drawText(self::font(subset: false), 12.0, 72, 680, 'A');

        self::assertSame(2, substr_count($document->save(), '/Subtype /Type0'));
    }

    public function testBuildsTheCompositeFontStructurePdfRequires(): void
    {
        $document = new Document();
        $content = new PageBuilder($document, $document->newPage());
        $content->drawText(self::font(), 12.0, 72, 700, 'A');

        $pdf = $document->save();

        self::assertStringContainsString('/Subtype /Type0', $pdf);
        self::assertStringContainsString('/Encoding /Identity-H', $pdf);
        self::assertStringContainsString('/Subtype /CIDFontType2', $pdf);
        self::assertStringContainsString('/CIDToGIDMap /Identity', $pdf);
        self::assertStringContainsString('/Ordering (Identity)', $pdf);
        self::assertStringContainsString('/FontFile2', $pdf);
        self::assertStringContainsString('/ToUnicode', $pdf);
    }

    /**
     * A subset font's name carries a six-letter tag, which is how a
     * reader tells two documents' subsets of one font apart. Deriving it
     * from the glyphs rather than at random is what keeps saving the
     * same document twice byte-identical.
     */
    public function testSubsetFontsAreNamedWithAStableTag(): void
    {
        $first = self::documentDrawing('A');
        $again = self::documentDrawing('A');
        $other = self::documentDrawing('B');

        self::assertSame(1, preg_match('/\/BaseFont \/([A-Z]{6})\+SyntheticTest/', $first, $matches));
        self::assertStringContainsString($matches[1] . '+SyntheticTest', $again);
        self::assertStringNotContainsString($matches[1] . '+SyntheticTest', $other);
    }

    public function testAFontEmbeddedWholeIsNotTagged(): void
    {
        $pdf = self::documentDrawing('A', subset: false);

        self::assertStringContainsString('/BaseFont /SyntheticTest', $pdf);
        self::assertSame(0, preg_match('/\/BaseFont \/[A-Z]{6}\+/', $pdf));
    }

    public function testTheSameDocumentSavedTwiceIsTheSameBytes(): void
    {
        $document = new Document();
        $content = new PageBuilder($document, $document->newPage());
        $content->drawText(self::font(), 12.0, 72, 700, 'AB');

        self::assertSame($document->save(), $document->save());
    }

    /**
     * Glyph numbers are handed out as text is drawn, so the character
     * ids in the content stream are 1, 2, 3... in first-drawn order --
     * which is exactly the order the subset font renumbers them into.
     */
    public function testCharacterIdsAreAssignedInTheOrderCharactersAreFirstDrawn(): void
    {
        $document = new Document();
        $content = new PageBuilder($document, $document->newPage());
        $font = self::font();

        $writer = $font->writerFor($document);

        self::assertSame('00010002', bin2hex($writer->encode('BA')));
        self::assertSame('000200010002', bin2hex($writer->encode('ABA')));
    }

    /** Embedding whole keeps the font's own numbering, since no glyph moves. */
    public function testEmbeddingWholeUsesTheFontsOwnGlyphNumbers(): void
    {
        $document = new Document();
        $writer = self::font(subset: false)->writerFor($document);

        self::assertSame(
            sprintf('%04x%04x', SyntheticTrueTypeFont::GLYPH_B, SyntheticTrueTypeFont::GLYPH_A),
            bin2hex($writer->encode('BA')),
        );
    }

    public function testTheWidthsArrayCoversEveryCharacterIdUsed(): void
    {
        $pdf = self::documentDrawing('AB');

        // Ids 1 and 2, in one run, with the advances of "A" and "B".
        self::assertStringContainsString('/W [1 [600 700]]', $pdf);
    }

    /**
     * A subset hands out consecutive ids, so its widths are one run. A
     * font embedded whole keeps its own glyph numbers, and the glyphs
     * the document never drew leave gaps that a single run would claim
     * widths for.
     */
    public function testTheWidthsArrayBreaksIntoRunsWhereIdsAreNotConsecutive(): void
    {
        // "A" is glyph 1 and "Á" is glyph 4; glyphs 2 and 3 go undrawn.
        $pdf = self::documentDrawing("A\u{00C1}", subset: false);

        self::assertStringContainsString('/W [1 [600] 4 [600]]', $pdf);
    }

    public function testWritesAToUnicodeMapSoTheTextCanBeCopiedBackOut(): void
    {
        $cmap = self::toUnicodeCMap(self::documentDrawing('AB'));

        self::assertStringContainsString('<0001> <0041>', $cmap);
        self::assertStringContainsString('<0002> <0042>', $cmap);
        self::assertStringContainsString('/CMapName /Adobe-Identity-UCS def', $cmap);
    }

    /**
     * Characters past the BMP are two UTF-16 code units, and a bfchar
     * entry holds UTF-16 -- a bare 21-bit value there is not something a
     * reader can use.
     */
    public function testToUnicodeWritesSurrogatePairsForAstralCharacters(): void
    {
        $cmap = self::toUnicodeCMap(self::documentDrawing("\u{1F600}"));

        self::assertStringContainsString('<0001> <D83DDE00>', $cmap);
    }

    private static function font(bool $subset = true): EmbeddedFont
    {
        return EmbeddedFont::fromBytes(SyntheticTrueTypeFont::build(), $subset);
    }

    private static function documentDrawing(string $text, bool $subset = true): string
    {
        $document = new Document();
        $content = new PageBuilder($document, $document->newPage());
        $content->drawText(self::font($subset), 12.0, 72, 700, $text);

        return $document->save();
    }

    /**
     * The document's streams, inflated -- which for these documents is
     * the content stream, the font program and the /ToUnicode CMap, and
     * only the last of those is text.
     */
    private static function toUnicodeCMap(string $pdf): string
    {
        $streams = self::inflateStreams($pdf);

        self::assertStringContainsString('begincmap', $streams, 'no /ToUnicode CMap was written');

        return $streams;
    }

    private static function inflateStreams(string $pdf): string
    {
        $out = '';

        if (preg_match_all('/stream\r?\n(.*?)\r?\nendstream/s', $pdf, $matches) === 0) {
            return $out;
        }

        foreach ($matches[1] as $stream) {
            $inflated = @gzuncompress($stream);

            if ($inflated !== false) {
                $out .= $inflated . "\n";
            }
        }

        return $out;
    }
}
