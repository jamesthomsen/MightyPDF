<?php

declare(strict_types=1);

namespace MightyPDF\Tests\Content\Font;

use MightyPDF\Assembler\Document;
use MightyPDF\Assembler\Dictionary;
use MightyPDF\Assembler\Stream;
use MightyPDF\Content\Font\EmbeddedFont;
use MightyPDF\Content\Font\TrueType\FontException;
use MightyPDF\Content\PageBuilder;
use MightyPDF\Tests\Support\SavedDocument;
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

        $saved = SavedDocument::of($document);
        $font = $saved->font();

        // Followed as a reader follows it: the page names a Type0 font,
        // which names a descendant CID font, which names the programme.
        // Each hop is a claim, and "/FontFile2 appears somewhere" made
        // none of them.
        self::assertSame('Type0', $font->get('Subtype')?->value());
        self::assertSame('Identity-H', $font->get('Encoding')?->value());

        self::assertSame('CIDFontType2', SavedDocument::scalar($saved->from($font, 'DescendantFonts', 0, 'Subtype')));
        self::assertSame('Identity', SavedDocument::scalar($saved->from($font, 'DescendantFonts', 0, 'CIDToGIDMap')));
        self::assertSame('Identity', SavedDocument::scalar($saved->from($font, 'DescendantFonts', 0, 'CIDSystemInfo', 'Ordering')));

        self::assertNotNull($saved->from($font, 'DescendantFonts', 0, 'FontDescriptor', 'FontFile2'));
        self::assertNotNull($saved->from($font, 'ToUnicode'));
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

        self::assertSame('SyntheticTest', SavedDocument::fromBytes($pdf)->font()->get('BaseFont')?->value());
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

    /**
     * A font embedded whole is addressed by character rather than by
     * glyph: its codes are the text's own UTF-16, which is what lets a
     * reader write text in it that this document never drew. See
     * UnicodeCMap.
     */
    public function testAFontEmbeddedWholeIsAddressedByCharacter(): void
    {
        $document = new Document();
        $writer = self::font(subset: false)->writerFor($document);

        self::assertSame('00420041', bin2hex($writer->encode('BA')));
        self::assertSame('d83dde00', bin2hex($writer->encode("\u{1F600}")));
    }

    public function testAFontEmbeddedWholeCarriesItsOwnEncodingCMap(): void
    {
        $pdf = self::documentDrawing('A', subset: false);

        // Named after the font: two different CMaps sharing a name is
        // how a reader that caches them by name draws one font's text
        // through another's mapping.
        $saved = SavedDocument::fromBytes($pdf);
        $encodingStream = $saved->from($saved->font(), 'Encoding');

        self::assertInstanceOf(Stream::class, $encodingStream, 'a whole-embedded font encodes through a CMap stream');
        self::assertSame('CMap', $encodingStream->get('Type')?->value());
        self::assertSame('SyntheticTest-UTF16-H', $encodingStream->get('CMapName')?->value());

        $encoding = self::encodingCMap($pdf);
        self::assertStringContainsString("<0041> <0042> 1\n", $encoding, '"A" and "B" are consecutive both ways');
        self::assertStringContainsString('<D83DDE00> 5', $encoding, 'a character past the BMP is a four-byte code');
        self::assertStringContainsString("<D800DC00> <DBFFDFFF>\n", $encoding, 'the surrogate half of the code space');
    }

    /**
     * The two ends of a cidrange must agree on every byte but the last,
     * so a run of characters is broken every 256 even where the font
     * maps them without a gap.
     *
     * Ghostscript enforces this and poppler does not, which is the worst
     * way for a document to be wrong: it renders perfectly in one reader
     * and as empty boxes in the other.
     */
    public function testARangeIsNeverWrittenAcrossAHighByteBoundary(): void
    {
        // Two characters either side of the boundary, mapping to two
        // consecutive glyphs -- one range, were it allowed.
        $characters = [
            0x00FF => SyntheticTrueTypeFont::GLYPH_A,
            0x0100 => SyntheticTrueTypeFont::GLYPH_B,
        ];

        $document = new Document();
        $content = new PageBuilder($document, $document->newPage());
        $content->drawText(EmbeddedFont::fromBytes(SyntheticTrueTypeFont::build($characters), subset: false), 12.0, 72, 700, "\u{00FF}");

        $encoding = self::encodingCMap($document->save());

        self::assertStringContainsString("<00FF> 1\n", $encoding);
        self::assertStringContainsString("<0100> 2\n", $encoding);
        self::assertStringNotContainsString('<00FF> <0100>', $encoding);
    }

    public function testTheWidthsArrayCoversEveryCharacterIdUsed(): void
    {
        $pdf = self::documentDrawing('AB');

        // Ids 1 and 2, in one run, with the advances of "A" and "B".
        self::assertSame('[1 [600 700]]', self::widthsOf($pdf));
    }

    /**
     * A subset is described by what the document drew, because that is
     * all the file contains. A font embedded whole is described by what
     * it can draw: the text it will show is not settled yet -- that is
     * the only reason to embed one whole -- so the reader that settles it
     * needs widths for characters this document never used.
     */
    public function testAFontEmbeddedWholeDescribesEveryCharacterItCanDraw(): void
    {
        // Only "A" is drawn, but the font maps six characters.
        $pdf = self::documentDrawing('A', subset: false);

        self::assertSame('[1 [600 700 300 600 800 250]]', self::widthsOf($pdf));

        $cmap = self::toUnicodeCMap($pdf);
        self::assertStringContainsString('<0041> <0042> <0041>', $cmap, '"B" was never drawn but the font can draw it');
    }

    /**
     * A /ToUnicode CMap takes one code width throughout: a reader handed
     * a second one drops every entry that uses it. The four-byte codes
     * standing for characters past the BMP are therefore written as the
     * two surrogates they are made of, each standing for itself -- which
     * costs nothing, since the codes are UTF-16 already.
     */
    public function testToUnicodeStaysTwoBytesWideEvenForAFontReachingPastTheBmp(): void
    {
        $cmap = self::toUnicodeCMap(self::documentDrawing('A', subset: false));

        self::assertStringContainsString('<D800> <D8FF> <D800>', $cmap);
        self::assertStringContainsString('<DE00> <DEFF> <DE00>', $cmap);
        self::assertSame(
            0,
            preg_match('/^<[0-9A-F]{8}>/m', $cmap),
            'a four-byte code in a two-byte CMap makes a reader drop the entry',
        );
    }

    /**
     * A subset hands out consecutive ids, so its widths are one run. A
     * font embedded whole keeps its own glyph numbers, and a glyph no
     * character maps to leaves a gap that a single run would claim a
     * width for.
     */
    public function testTheWidthsArrayBreaksIntoRunsWhereIdsAreNotConsecutive(): void
    {
        // A cmap reaching glyphs 1 and 4 only: 2 and 3 are unreachable.
        $characters = [
            0x0041 => SyntheticTrueTypeFont::GLYPH_A,
            0x00C1 => SyntheticTrueTypeFont::GLYPH_A_ACUTE,
        ];

        $document = new Document();
        $content = new PageBuilder($document, $document->newPage());
        $content->drawText(EmbeddedFont::fromBytes(SyntheticTrueTypeFont::build($characters), subset: false), 12.0, 72, 700, 'A');

        self::assertSame('[1 [600] 4 [600]]', self::widthsOf($document->save()));
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

    /**
     * PostScript outlines are embedded whole, in the shape PDF has for
     * them: a CIDFontType0 descendant, the program under /FontFile3 as a
     * whole OpenType file, and no /CIDToGIDMap -- a CIDFontType0 has no
     * such entry, and a font that is not itself CID-keyed uses its
     * character ids as glyph indices directly.
     */
    public function testAnOpenTypeFontIsEmbeddedAsACidFontType0(): void
    {
        $document = new Document();
        $content = new PageBuilder($document, $document->newPage());
        $content->drawText(self::openType(), 12.0, 72, 700, 'A');

        $pdf = $document->save();

        $saved = SavedDocument::fromBytes($pdf);
        $font = $saved->font();

        $descendant = $saved->from($font, 'DescendantFonts', 0);
        self::assertInstanceOf(Dictionary::class, $descendant);
        self::assertSame('CIDFontType0', $descendant->get('Subtype')?->value());

        // /CIDToGIDMap belongs to CIDFontType2 -- asked of the descendant
        // rather than of the file, which would also have been answering
        // for any other font the document happened to carry.
        self::assertNull($descendant->get('CIDToGIDMap'));

        $descriptor = $saved->from($descendant, 'FontDescriptor');
        self::assertInstanceOf(Dictionary::class, $descriptor);
        self::assertNull($descriptor->get('FontFile2'));

        $programme = $saved->from($descriptor, 'FontFile3');
        self::assertInstanceOf(Stream::class, $programme);
        self::assertSame('OpenType', $programme->get('Subtype')?->value());
        self::assertNull($programme->get('Length1'), 'only a /FontFile2 states its length');
    }

    /**
     * Subsetting a CFF font means taking its charstrings apart, which is
     * a second subsetter's worth of work. Refused by name rather than
     * quietly embedding more than was asked for.
     */
    public function testSubsettingAnOpenTypeFontIsRefused(): void
    {
        $this->expectException(FontException::class);
        $this->expectExceptionMessageMatches('/subset: false/');

        EmbeddedFont::fromBytes(SyntheticTrueTypeFont::withPostScriptOutlines());
    }

    /**
     * A CID-keyed CFF's glyphs are not addressed by index at all, so
     * embedding one and writing glyph indices would draw the wrong
     * glyphs -- which looks like a font, just the wrong one.
     */
    public function testACidKeyedOpenTypeFontIsRefused(): void
    {
        $this->expectException(FontException::class);
        $this->expectExceptionMessageMatches('/CID-keyed/');

        EmbeddedFont::fromBytes(SyntheticTrueTypeFont::withPostScriptOutlines(cidKeyed: true), subset: false);
    }

    private static function openType(): EmbeddedFont
    {
        return EmbeddedFont::fromBytes(SyntheticTrueTypeFont::withPostScriptOutlines(), subset: false);
    }

    private static function font(bool $subset = true): EmbeddedFont
    {
        return EmbeddedFont::fromBytes(SyntheticTrueTypeFont::build(), $subset);
    }

    /** The descendant CID font's /W array, as written. */
    private static function widthsOf(string $pdf): string
    {
        $saved = SavedDocument::fromBytes($pdf);
        $widths = $saved->from($saved->font(), 'DescendantFonts', 0, 'W');

        self::assertNotNull($widths, 'the descendant font should carry a /W array');

        return $widths->format();
    }

    private static function documentDrawing(string $text, bool $subset = true): string
    {
        $document = new Document();
        $content = new PageBuilder($document, $document->newPage());
        $content->drawText(self::font($subset), 12.0, 72, 700, $text);

        return $document->save();
    }

    /**
     * The /ToUnicode CMap alone -- a document with a font embedded whole
     * holds a second CMap, its encoding, and the two say different things
     * in the same syntax.
     */
    private static function toUnicodeCMap(string $pdf): string
    {
        return self::cmap($pdf, 2);
    }

    /** The /Encoding CMap alone; see toUnicodeCMap(). */
    private static function encodingCMap(string $pdf): string
    {
        return self::cmap($pdf, 1);
    }

    /** $type is the CMap's own /CMapType: 1 an encoding, 2 a ToUnicode map. */
    private static function cmap(string $pdf, int $type): string
    {
        foreach (self::inflatedStreams($pdf) as $stream) {
            if (str_contains($stream, "/CMapType $type def")) {
                return $stream;
            }
        }

        self::fail("no CMap of type $type was written");
    }

    private static function inflateStreams(string $pdf): string
    {
        return implode("\n", self::inflatedStreams($pdf));
    }

    /** @return list<string> */
    private static function inflatedStreams(string $pdf): array
    {
        $out = [];

        if (preg_match_all('/stream\r?\n(.*?)\r?\nendstream/s', $pdf, $matches) === 0) {
            return $out;
        }

        foreach ($matches[1] as $stream) {
            $inflated = @gzuncompress($stream);

            if ($inflated !== false) {
                $out[] = $inflated;
            }
        }

        return $out;
    }
}
