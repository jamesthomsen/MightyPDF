<?php

declare(strict_types=1);

namespace MightyPDF\Tests\Editor\Form;

use MightyPDF\Assembler\Dictionary;
use MightyPDF\Editor\Form\CompositeFieldFont;
use MightyPDF\Editor\PdfEditor;
use PHPUnit\Framework\TestCase;

/**
 * A /Type0 font read back out of a file, which is what a field created
 * with an embedded font points at -- and, since the file may be anyone's,
 * what a hostile /W array arrives through.
 */
final class CompositeFieldFontTest extends TestCase
{
    public function testMeasuresFromTheDescendantFontsWidthArray(): void
    {
        // "cFirst cLast w" over a run, then "c [w w]" per character.
        $font = self::read('/DW 1000 /W [65 66 600 67 [700 800]]');

        self::assertNotNull($font);
        self::assertEqualsWithDelta(6.0, $font->widthOfPt('A', 10.0), 0.001);
        self::assertEqualsWithDelta(7.0, $font->widthOfPt('C', 10.0), 0.001);
        self::assertEqualsWithDelta(8.0, $font->widthOfPt('D', 10.0), 0.001);
    }

    /** A character id the array does not mention falls back to /DW. */
    public function testAnUnmentionedCharacterFallsBackToTheDefaultWidth(): void
    {
        $font = self::read('/DW 250 /W [65 66 600]');

        self::assertNotNull($font);
        self::assertEqualsWithDelta(2.5, $font->widthOfPt('Z', 10.0), 0.001);
    }

    /**
     * A CID is a 16-bit number, so a run reaching past that describes
     * characters that cannot be addressed. Dropping them is a bound as
     * much as a rule: the run form costs a few bytes and used to buy an
     * entry per id, so a nine-kilobyte file exhausted a gigabyte of
     * memory before anything had been drawn.
     */
    public function testARunPastTheLargestCharacterIdDoesNotExpandForever(): void
    {
        $runs = '';

        for ($i = 0; $i < 400; ++$i) {
            $first = $i * 65_536;
            $runs .= $first . ' ' . ($first + 65_535) . ' 500 ';
        }

        $before = memory_get_usage();
        $font = self::read('/DW 1000 /W [' . $runs . ']');
        $used = memory_get_usage() - $before;

        self::assertNotNull($font);

        // The first run is the only one describing ids that exist, and
        // it still measures: "A" is 500, not the 1000 of /DW.
        self::assertEqualsWithDelta(5.0, $font->widthOfPt('A', 10.0), 0.001);
        self::assertLessThan(16 * 1024 * 1024, $used, 'the widths are bounded by the ids that can exist');
    }

    /**
     * A run reaching outside the ids that exist keeps the part that is
     * inside them, and a run written backwards describes nothing at all.
     */
    public function testARunIsClampedToRealIdsAndABackwardsOneIsIgnored(): void
    {
        $font = self::read('/DW 300 /W [-2000000000 4000000000 500 90 80 600]');

        self::assertNotNull($font);
        self::assertEqualsWithDelta(5.0, $font->widthOfPt('A', 10.0), 0.001, 'the clamped run still covers real ids');
        self::assertEqualsWithDelta(
            5.0,
            $font->widthOfPt('Z', 10.0),
            0.001,
            'the backwards run leaves Z as the clamped run left it',
        );
    }

    /** A font with no /ToUnicode says nothing about how to reach a character. */
    public function testAFontWithNoToUnicodeCannotBeDrawnWith(): void
    {
        self::assertNull(self::read('/DW 1000 /W [65 66 600]', toUnicode: false));
    }

    private static function read(string $descendantEntries, bool $toUnicode = true): ?CompositeFieldFont
    {
        // Identity over the printable ASCII the tests draw with, so that
        // a code is the character it stands for.
        $cmap = "1 beginbfrange\n<0020> <007E> <0020>\nendbfrange\n";

        $font = '<< /Type /Font /Subtype /Type0 /BaseFont /Test /Encoding /Identity-H '
            . '/DescendantFonts [3 0 R] ' . ($toUnicode ? '/ToUnicode 2 0 R ' : '') . '>>';

        $objects = [
            1 => $font,
            2 => '<< /Length ' . strlen($cmap) . " >>\nstream\n" . $cmap . 'endstream',
            3 => '<< /Type /Font /Subtype /CIDFontType2 /BaseFont /Test '
                . '/CIDSystemInfo << /Registry (Adobe) /Ordering (Identity) /Supplement 0 >> '
                . $descendantEntries . ' >>',
        ];

        $editor = PdfEditor::fromBytes(self::assemble($objects));
        $dictionary = $editor->resolveDictionary(new \MightyPDF\Assembler\Types\PdfReference(1));

        self::assertInstanceOf(Dictionary::class, $dictionary);

        return CompositeFieldFont::read($editor, $dictionary);
    }

    /** @param array<int, string> $objects */
    private static function assemble(array $objects): string
    {
        // Object 1 is the font rather than a catalog, so the trailer
        // points at a catalog of its own added here -- PdfEditor wants a
        // document, and these tests want one font in it.
        $objects[count($objects) + 1] = '<< /Type /Catalog /Pages ' . (count($objects) + 2) . " 0 R >>";
        $objects[count($objects) + 1] = '<< /Type /Pages /Kids [] /Count 0 >>';

        $root = count($objects) - 1;
        $out = "%PDF-1.7\n";
        $offsets = [];

        foreach ($objects as $id => $body) {
            $offsets[$id] = strlen($out);
            $out .= "$id 0 obj\n$body\nendobj\n";
        }

        $xrefOffset = strlen($out);
        $count = count($objects) + 1;
        $out .= "xref\n0 $count\n0000000000 65535 f \n";

        foreach (array_keys($objects) as $id) {
            $out .= sprintf("%010d 00000 n \n", $offsets[$id]);
        }

        return $out . "trailer\n<< /Size $count /Root $root 0 R >>\nstartxref\n{$xrefOffset}\n%%EOF\n";
    }
}
