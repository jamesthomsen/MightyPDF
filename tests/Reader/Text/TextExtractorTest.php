<?php

declare(strict_types=1);

namespace MightyPDF\Tests\Reader\Text;

use MightyPDF\Assembler\Document;
use MightyPDF\Content\Font\StandardFont;
use MightyPDF\Content\PageBuilder;
use MightyPDF\Editor\PdfEditor;
use MightyPDF\Reader\Text\GlyphNames;
use MightyPDF\Reader\Text\TextExtractor;
use PHPUnit\Framework\TestCase;

final class TextExtractorTest extends TestCase
{
    public function testReadsBackWhatWasWritten(): void
    {
        $document = new Document();
        $page = $document->newPage();

        (new PageBuilder($document, $page))
            ->drawText(StandardFont::Helvetica, 18.0, 60, 700, 'Quarterly Report');

        self::assertSame('Quarterly Report', self::textOf($document->save()));
    }

    public function testReadsTheUpperHalfOfWinAnsi(): void
    {
        $document = new Document();
        $page = $document->newPage();

        (new PageBuilder($document, $page))
            ->drawText(StandardFont::TimesRoman, 11.0, 60, 700, 'Revenue rose to £4.2m — a café');

        self::assertSame('Revenue rose to £4.2m — a café', self::textOf($document->save()));
    }

    public function testSeparatesLinesByBaseline(): void
    {
        $document = new Document();
        $page = $document->newPage();
        $content = new PageBuilder($document, $page);

        $content->drawText(StandardFont::Helvetica, 12.0, 60, 700, 'First line');
        $content->drawText(StandardFont::Helvetica, 12.0, 60, 680, 'Second line');

        self::assertSame(['First line', 'Second line'], self::linesOf($document->save()));
    }

    public function testReadsTopToBottomWhateverOrderThePageDrewIn(): void
    {
        $document = new Document();
        $page = $document->newPage();
        $content = new PageBuilder($document, $page);

        // The footer drawn before the body, as a producer emitting its
        // furniture last-minute would.
        $content->drawText(StandardFont::Helvetica, 9.0, 60, 40, 'Page 1');
        $content->drawText(StandardFont::Helvetica, 12.0, 60, 700, 'The body');

        self::assertSame(['The body', 'Page 1'], self::linesOf($document->save()));
    }

    public function testJoinsRunsOnOneBaselineWithASpaceForTheGap(): void
    {
        $document = new Document();
        $page = $document->newPage();
        $content = new PageBuilder($document, $page);

        // Two positioned runs, no space character between them anywhere.
        $content->drawText(StandardFont::Helvetica, 11.0, 60, 700, 'Left column');
        $content->drawText(StandardFont::Helvetica, 11.0, 300, 700, 'right column');

        self::assertSame(['Left column right column'], self::linesOf($document->save()));
    }

    public function testDoesNotInventASpaceInsideAWord(): void
    {
        $document = new Document();
        $page = $document->newPage();

        // drawText emits one run; the point is that the character advances
        // inside it never accumulate into a spurious gap.
        (new PageBuilder($document, $page))
            ->drawText(StandardFont::Helvetica, 11.0, 60, 700, 'Unbrokenword');

        self::assertSame('Unbrokenword', self::textOf($document->save()));
    }

    public function testFollowsTheCurrentTransformationMatrix(): void
    {
        $document = new Document();
        $page = $document->newPage();

        (new PageBuilder($document, $page))->scaled(
            2.0,
            2.0,
            0.0,
            0.0,
            static fn (PageBuilder $b) => $b->drawText(StandardFont::Helvetica, 6.0, 40, 300, 'Scaled up'),
        );

        $fragments = self::extractorFor($document->save())->page(0)->fragments();

        self::assertCount(1, $fragments);
        self::assertSame('Scaled up', $fragments[0]->text);

        // Drawn at 6pt inside a 2x scale, so it is 12pt on the page at
        // twice the coordinates it was asked for. A reader that tracks
        // only the text matrix reports 6pt at (40, 300).
        self::assertEqualsWithDelta(80.0, $fragments[0]->x, 0.001);
        self::assertEqualsWithDelta(600.0, $fragments[0]->y, 0.001);
        self::assertEqualsWithDelta(12.0, $fragments[0]->fontSize, 0.001);
    }

    public function testFollowsTextIntoAFormXObject(): void
    {
        // A page stamped by PageOverlay keeps everything it drew inside a
        // form XObject, so not following them would extract a stamped or
        // flattened page as blank.
        $document = new Document();
        $document->newPage();

        $editor = PdfEditor::fromBytes($document->save());
        $overlay = new \MightyPDF\Editor\PageOverlay($editor, (new \MightyPDF\Editor\PageTree($editor))->page(0));
        $overlay->content()->drawText(StandardFont::Helvetica, 12.0, 60, 700, 'Stamped on afterwards');
        $overlay->apply();

        self::assertSame('Stamped on afterwards', self::textOf($editor->save()));
    }

    /**
     * A form XObject may invoke another, including itself, as many times
     * as it likes. The depth cap bounds how deep that goes and says
     * nothing about how wide, so the work is the fan-out raised to the
     * depth: twelve `Do` operators in one self-referential stream is
     * 12^8 runs -- around four hundred million -- out of six hundred
     * bytes of file. The depth cap fires every time and stops nothing.
     *
     * Asserted on the page's own text, which is drawn outside the
     * XObject and so must survive the expansion being cut short. A
     * regression does not fail this test, it never finishes it.
     */
    public function testAFormXObjectInvokingItselfDoesNotExpandForever(): void
    {
        $inner = str_repeat("/X Do\n", 12);
        $body = 'BT /F1 12 Tf 60 700 Td (Body text) Tj ET /X Do';

        $pdf = self::assemble([
            1 => '<< /Type /Catalog /Pages 2 0 R >>',
            2 => '<< /Type /Pages /Count 1 /Kids [3 0 R] >>',
            3 => '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 612 792] '
                . '/Resources << /Font << /F1 6 0 R >> /XObject << /X 5 0 R >> >> /Contents 4 0 R >>',
            4 => self::stream($body),
            // Its own resources name itself, which is all a cycle needs.
            5 => '<< /Type /XObject /Subtype /Form /BBox [0 0 612 792] '
                . '/Resources << /XObject << /X 5 0 R >> >> /Length ' . strlen($inner) . " >>\nstream\n"
                . $inner . "\nendstream",
            6 => '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>',
        ]);

        self::assertSame('Body text', self::textOf($pdf));
    }

    /**
     * The limits cannot be lifted, so the one thing they must not be is
     * quiet: a caller handed most of a page has to be able to tell that
     * from all of one.
     */
    public function testAPageStoppedByALimitSaysSo(): void
    {
        // A plain self-reference, which reaches the depth limit in eight
        // steps. Any of the three limits sets the flag; this is the one
        // that costs nothing to reach.
        self::assertTrue(self::extractorFor(self::selfReferencingXObject())->page(0)->isTruncated());
    }

    public function testAnOrdinaryPageIsNotReportedAsTruncated(): void
    {
        $document = new Document();
        $page = $document->newPage();

        (new PageBuilder($document, $page))
            ->drawText(StandardFont::Helvetica, 12.0, 60, 700, 'Well within every limit');

        self::assertFalse(self::extractorFor($document->save())->page(0)->isTruncated());
    }

    /** A stamped page follows its XObject and is still complete. */
    public function testAPageWithFormXObjectsIsNotReportedAsTruncated(): void
    {
        $document = new Document();
        $document->newPage();

        $editor = PdfEditor::fromBytes($document->save());
        $overlay = new \MightyPDF\Editor\PageOverlay($editor, (new \MightyPDF\Editor\PageTree($editor))->page(0));
        $overlay->content()->drawText(StandardFont::Helvetica, 12.0, 60, 700, 'Stamped');
        $overlay->apply();

        $text = self::extractorFor($editor->save())->page(0);

        self::assertSame('Stamped', $text->text());
        self::assertFalse($text->isTruncated());
    }

    /**
     * The flag belongs to the page, not to the extractor: a hostile page
     * must not make the next one look incomplete.
     */
    public function testTruncationIsReportedPerPage(): void
    {
        $extractor = self::extractorFor(self::selfReferencingXObject(withSecondPage: true));

        self::assertTrue($extractor->page(0)->isTruncated());

        $second = $extractor->page(1);

        self::assertSame('Second page', $second->text());
        self::assertFalse($second->isTruncated());
    }

    /**
     * A `Do` cannot be turned down until the stream it names has been
     * decoded, because what a stream inflates to is not knowable without
     * inflating it. So a page invoking one bomb repeatedly inflates it
     * repeatedly -- gigabytes of work out of a file measured in
     * kilobytes -- however tightly the *running* of it is bounded.
     *
     * Asserted on the clock's proxy rather than the clock: the memo means
     * one decode, so peak memory stays near one copy instead of climbing
     * with the invocation count.
     */
    public function testAnXObjectInvokedManyTimesIsDecodedOnce(): void
    {
        $payload = gzcompress(str_repeat("\n", 16 * 1024 * 1024), 9);
        $body = str_repeat("/X Do\n", 400);

        $pdf = self::assemble([
            1 => '<< /Type /Catalog /Pages 2 0 R >>',
            2 => '<< /Type /Pages /Count 1 /Kids [3 0 R] >>',
            3 => '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 612 792] '
                . '/Resources << /XObject << /X 5 0 R >> >> /Contents 4 0 R >>',
            4 => self::stream($body),
            5 => '<< /Type /XObject /Subtype /Form /BBox [0 0 612 792] /Filter /FlateDecode /Length '
                . strlen($payload) . " >>\nstream\n" . $payload . "\nendstream",
        ]);

        $before = microtime(true);
        self::extractorFor($pdf)->page(0);
        $elapsed = microtime(true) - $before;

        // Four hundred decodes of sixteen megabytes took nineteen seconds
        // before the memo; one takes a fiftieth of that. The threshold is
        // loose enough not to be a timing test and tight enough that a
        // regression cannot pass it.
        self::assertLessThan(2.0, $elapsed);
    }

    /**
     * The memo cannot absorb streams that are all different, so the
     * budget has to. Sixteen distinct eight-megabyte XObjects is twice
     * the decode budget if every one of them is decoded.
     */
    public function testDistinctXObjectBombsAreBoundedByTheDecodeBudget(): void
    {
        $objects = [
            1 => '<< /Type /Catalog /Pages 2 0 R >>',
            2 => '<< /Type /Pages /Count 1 /Kids [3 0 R] >>',
        ];

        $resources = '';
        $body = '';

        for ($i = 0; $i < 16; ++$i) {
            $id = 5 + $i;
            $resources .= "/X$i $id 0 R ";
            $body .= "/X$i Do\n";

            // A different byte in each, so no two are the same object.
            $payload = gzcompress(str_repeat(chr(65 + $i % 26), 8 * 1024 * 1024), 9);

            $objects[$id] = '<< /Type /XObject /Subtype /Form /BBox [0 0 612 792] /Filter /FlateDecode '
                . '/Length ' . strlen($payload) . " >>\nstream\n" . $payload . "\nendstream";
        }

        $objects[3] = '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 612 792] '
            . "/Resources << /XObject << $resources>> >> /Contents 4 0 R >>";
        $objects[4] = self::stream($body);

        $before = memory_get_usage(true);
        self::extractorFor(self::assemble($objects))->page(0);

        self::assertLessThan(256 * 1024 * 1024, memory_get_usage(true) - $before);
    }

    /**
     * A composite font's /W is a list of ranges, and each range is
     * already capped at the 65 536 codes two bytes can express. Nothing
     * caps how many ranges there are, and each one costs its full span in
     * assignments -- so a few kilobytes of "0 65535 1" triples is
     * hundreds of millions of writes, arriving repeatedly at the same
     * array.
     */
    public function testARepeatedWidthRangeIsNotExpandedOverAndOver(): void
    {
        $ranges = str_repeat('0 65535 500 ', 4_000);

        $pdf = self::assemble([
            1 => '<< /Type /Catalog /Pages 2 0 R >>',
            2 => '<< /Type /Pages /Count 1 /Kids [3 0 R] >>',
            3 => '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 612 792] '
                . '/Resources << /Font << /F1 5 0 R >> >> /Contents 4 0 R >>',
            4 => self::stream("BT /F1 12 Tf 60 700 Td <0041> Tj ET"),
            5 => '<< /Type /Font /Subtype /Type0 /BaseFont /Test /Encoding /Identity-H '
                . '/DescendantFonts [6 0 R] /ToUnicode 7 0 R >>',
            6 => "<< /Type /Font /Subtype /CIDFontType2 /BaseFont /Test /W [$ranges] >>",
            7 => self::stream(
                "/CIDInit /ProcSet findresource begin 12 dict begin begincmap\n"
                . "1 begincodespacerange <0000> <FFFF> endcodespacerange\n"
                . "1 beginbfchar <0041> <0041> endbfchar\n"
                . "endcmap CMapName currentdict /CMap defineresource pop end end",
            ),
        ]);

        self::assertSame('A', self::textOf($pdf));
    }

    public function testStillReadsTheWidthsAWellFormedFontDeclares(): void
    {
        // The budget above must not cost an ordinary CJK-shaped /W its
        // widths: two ranges, well inside it, both applied.
        $pdf = self::assemble([
            1 => '<< /Type /Catalog /Pages 2 0 R >>',
            2 => '<< /Type /Pages /Count 1 /Kids [3 0 R] >>',
            3 => '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 612 792] '
                . '/Resources << /Font << /F1 5 0 R >> >> /Contents 4 0 R >>',
            4 => self::stream('BT /F1 10 Tf 60 700 Td <00410041> Tj ET'),
            5 => '<< /Type /Font /Subtype /Type0 /BaseFont /Test /Encoding /Identity-H '
                . '/DescendantFonts [6 0 R] >>',
            // CID 0x41 is 1000 wide; everything else is the /DW 250.
            6 => '<< /Type /Font /Subtype /CIDFontType2 /BaseFont /Test /DW 250 /W [65 [1000]] >>',
        ]);

        $fragments = self::extractorFor($pdf)->page(0)->fragments();

        self::assertCount(1, $fragments);

        // Two glyphs at 1000/1000 em of 10pt.
        self::assertEqualsWithDelta(20.0, $fragments[0]->width, 0.001);
    }

    public function testReportsWhereEachRunSits(): void
    {
        $document = new Document();
        $page = $document->newPage();

        (new PageBuilder($document, $page))
            ->drawText(StandardFont::Helvetica, 12.0, 72.0, 700.0, 'Positioned');

        $fragments = self::extractorFor($document->save())->page(0)->fragments();

        self::assertCount(1, $fragments);
        self::assertEqualsWithDelta(72.0, $fragments[0]->x, 0.001);
        self::assertEqualsWithDelta(700.0, $fragments[0]->y, 0.001);
        self::assertEqualsWithDelta(12.0, $fragments[0]->fontSize, 0.001);

        // The advance of "Positioned" in 12pt Helvetica, from the same
        // metrics the writer used to lay it out.
        self::assertEqualsWithDelta(
            StandardFont::Helvetica->widthOfPt('Positioned', 12.0),
            $fragments[0]->width,
            0.01,
        );
    }

    public function testAPageThatDrawsNoTextIsEmpty(): void
    {
        $document = new Document();
        $page = $document->newPage();

        (new PageBuilder($document, $page))->fillRectangle(10, 10, 100, 100);

        $text = self::extractorFor($document->save())->page(0);

        self::assertTrue($text->isEmpty());
        self::assertSame('', $text->text());
    }

    public function testReadsEveryPage(): void
    {
        $document = new Document();

        foreach (['One', 'Two', 'Three'] as $label) {
            $page = $document->newPage();
            (new PageBuilder($document, $page))->drawText(StandardFont::Helvetica, 12.0, 60, 700, $label);
        }

        $extractor = self::extractorFor($document->save());

        self::assertSame(3, $extractor->pageCount());
        self::assertSame('Two', $extractor->page(1)->text());
        self::assertSame("One\n\fTwo\n\fThree", $extractor->text());
    }

    public function testRefusesAPageThatIsNotThere(): void
    {
        $document = new Document();
        $document->newPage();

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('This document has 1 page, numbered 0 to 0; there is no page 4.');

        self::extractorFor($document->save())->page(4);
    }

    public function testDecodesGlyphNamesFromAnEncodingDifference(): void
    {
        // A font whose /Differences renames codes by glyph name -- what a
        // producer emits when it re-encodes a subset.
        $pdf = self::assemble([
            1 => '<< /Type /Catalog /Pages 2 0 R >>',
            2 => '<< /Type /Pages /Count 1 /Kids [3 0 R] >>',
            3 => '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 612 792] /Contents 4 0 R'
                . ' /Resources << /Font << /F1 5 0 R >> >> >>',
            4 => self::stream("BT /F1 12 Tf 72 700 Td <0102030405> Tj ET"),
            5 => '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica /FirstChar 1 /LastChar 5'
                . ' /Widths [500 500 500 500 500]'
                . ' /Encoding << /Differences [1 /C /a /f /eacute /exclam] >> >>',
        ]);

        self::assertSame('Café!', self::textOf($pdf));
    }

    public function testCodesThatCannotBeDecodedAreMarkedRatherThanDropped(): void
    {
        // A subset font with invented glyph names and no /ToUnicode: the
        // text genuinely is not recoverable, and saying so beats silently
        // returning a shorter string.
        $pdf = self::assemble([
            1 => '<< /Type /Catalog /Pages 2 0 R >>',
            2 => '<< /Type /Pages /Count 1 /Kids [3 0 R] >>',
            3 => '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 612 792] /Contents 4 0 R'
                . ' /Resources << /Font << /F1 5 0 R >> >> >>',
            4 => self::stream("BT /F1 12 Tf 72 700 Td <0102> Tj ET"),
            5 => '<< /Type /Font /Subtype /Type1 /BaseFont /AAAAAA+Custom /FirstChar 1 /LastChar 2'
                . ' /Widths [500 500] /Encoding << /Differences [1 /g17 /g42] >> >>',
        ]);

        self::assertSame("\u{FFFD}\u{FFFD}", self::textOf($pdf));
    }

    public function testSkipsAnInlineImagesBinaryData(): void
    {
        // The bytes between ID and EI are not tokens; reading them as
        // operators would produce noise and lose the text after them.
        $pdf = self::assemble([
            1 => '<< /Type /Catalog /Pages 2 0 R >>',
            2 => '<< /Type /Pages /Count 1 /Kids [3 0 R] >>',
            3 => '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 612 792] /Contents 4 0 R'
                . ' /Resources << /Font << /F1 5 0 R >> >> >>',
            4 => self::stream(
                "q 10 0 0 10 0 0 cm BI /W 2 /H 2 /CS /G /BPC 8 ID \x00(Tj\xFF EI Q\n"
                . 'BT /F1 12 Tf 72 700 Td (After the image) Tj ET',
            ),
            5 => '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>',
        ]);

        self::assertSame('After the image', self::textOf($pdf));
    }

    public function testGlyphNamesUnderstandTheAlgorithmicForms(): void
    {
        self::assertSame('A', GlyphNames::toText('A'));
        self::assertSame(' ', GlyphNames::toText('space'));
        self::assertSame('é', GlyphNames::toText('eacute'));
        self::assertSame('€', GlyphNames::toText('Euro'));

        // Named by code point outright.
        self::assertSame('☃', GlyphNames::toText('uni2603'));
        self::assertSame('☃', GlyphNames::toText('u2603'));

        // A variant suffix names the same character.
        self::assertSame('a', GlyphNames::toText('a.sc'));

        // Names that say nothing about what the glyph is.
        self::assertNull(GlyphNames::toText('g17'));
        self::assertNull(GlyphNames::toText('.notdef'));
        self::assertNull(GlyphNames::toText(''));
    }

    private static function textOf(string $pdf): string
    {
        return self::extractorFor($pdf)->page(0)->text();
    }

    /** @return list<string> */
    private static function linesOf(string $pdf): array
    {
        return self::extractorFor($pdf)->page(0)->lines();
    }

    private static function extractorFor(string $pdf): TextExtractor
    {
        return new TextExtractor(PdfEditor::fromBytes($pdf));
    }

    /**
     * A page whose one form XObject names itself, so following it reaches
     * the depth limit and stops with content still unfollowed.
     *
     * @param bool $withSecondPage add an ordinary page after it, to show
     *        the first page's limit is not carried over to it
     */
    private static function selfReferencingXObject(bool $withSecondPage = false): string
    {
        $objects = [
            1 => '<< /Type /Catalog /Pages 2 0 R >>',
            2 => '<< /Type /Pages /Count ' . ($withSecondPage ? 2 : 1) . ' /Kids ['
                . ($withSecondPage ? '3 0 R 6 0 R' : '3 0 R') . '] >>',
            3 => '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 612 792] '
                . '/Resources << /XObject << /X 5 0 R >> >> /Contents 4 0 R >>',
            4 => self::stream('/X Do'),
            5 => '<< /Type /XObject /Subtype /Form /BBox [0 0 612 792] '
                . "/Resources << /XObject << /X 5 0 R >> >> /Length 5 >>\nstream\n/X Do\nendstream",
        ];

        if ($withSecondPage) {
            $objects[6] = '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 612 792] '
                . '/Resources << /Font << /F1 8 0 R >> >> /Contents 7 0 R >>';
            $objects[7] = self::stream('BT /F1 12 Tf 60 700 Td (Second page) Tj ET');
            $objects[8] = '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>';
        }

        return self::assemble($objects);
    }

    private static function stream(string $body): string
    {
        return sprintf("<< /Length %d >>\nstream\n%s\nendstream", strlen($body), $body);
    }

    /** @param array<int, string> $objects */
    private static function assemble(array $objects): string
    {
        ksort($objects);

        $out = "%PDF-1.7\n";
        $offsets = [];

        foreach ($objects as $id => $body) {
            $offsets[$id] = strlen($out);
            $out .= "$id 0 obj\n$body\nendobj\n";
        }

        $xrefOffset = strlen($out);
        $highest = max(array_keys($objects));

        $out .= "xref\n0 " . ($highest + 1) . "\n0000000000 65535 f \n";

        for ($id = 1; $id <= $highest; ++$id) {
            $out .= isset($offsets[$id])
                ? sprintf("%010d 00000 n \n", $offsets[$id])
                : "0000000000 65535 f \n";
        }

        return $out
            . "trailer\n<< /Size " . ($highest + 1) . " /Root 1 0 R >>\n"
            . "startxref\n{$xrefOffset}\n%%EOF\n";
    }
}
