<?php

declare(strict_types=1);

namespace MightyPDF\Tests\Content\Font;

use MightyPDF\Assembler\Document;
use MightyPDF\Content\Font\EmbeddedFont;
use MightyPDF\Content\Font\TrueType\TrueTypeFile;
use MightyPDF\Content\Font\TrueType\TrueTypeSubset;
use MightyPDF\Content\PageBuilder;
use MightyPDF\Content\Text\Utf8;
use MightyPDF\Tests\Support\SavedDocument;
use PHPUnit\Framework\TestCase;

/**
 * The same machinery against a real font rather than the synthetic one.
 *
 * A hand-built fixture can only contain what its author thought to put
 * in it. A shipped font brings thousands of glyphs, hinting programs,
 * several cmap subtables that disagree with each other, and accented
 * characters built as composites -- the things that actually break a
 * subsetter.
 *
 * Skipped where no such font is installed, since one cannot be shipped
 * with the tests (see SyntheticTrueTypeFont). The suite must still pass
 * on a machine with no fonts at all, so nothing here may be the only
 * coverage of anything.
 */
final class EmbeddedRealFontTest extends TestCase
{
    private const array CANDIDATES = [
        '/usr/share/fonts/truetype/dejavu/DejaVuSans.ttf',
        '/usr/share/fonts/truetype/liberation/LiberationSans-Regular.ttf',
        '/usr/share/fonts/truetype/liberation/LiberationSerif-Regular.ttf',
        '/usr/share/fonts/TTF/DejaVuSans.ttf',
        '/Library/Fonts/Arial Unicode.ttf',
        'C:\\Windows\\Fonts\\arial.ttf',
    ];

    public function testSubsettingLeavesOnlyWhatWasUsed(): void
    {
        $path = self::anInstalledFont();
        $font = TrueTypeFile::fromFile($path);

        $glyphs = [];
        foreach (Utf8::codePoints('Hello') as $codePoint) {
            $glyphs[] = $font->glyphForCodePoint($codePoint);
        }

        $subset = TrueTypeSubset::build($font, array_values(array_unique($glyphs)));

        self::assertLessThan(
            filesize($path) / 10,
            strlen($subset),
            'a handful of glyphs should not cost a tenth of the font',
        );
        self::assertLessThanOrEqual(5, TrueTypeFile::fromBytes($subset)->numGlyphs());
    }

    /**
     * An accented character is normally a composite glyph, so this is
     * the closure over components working on a font that really has
     * them -- and the outline surviving is what proves the components
     * came along and were renumbered.
     */
    public function testAccentedCharactersSurviveSubsetting(): void
    {
        $font = TrueTypeFile::fromFile(self::anInstalledFont());
        $eAcute = $font->glyphForCodePoint(0x00E9);

        if ($eAcute === null) {
            self::markTestSkipped('The installed font has no "é".');
        }

        $subset = TrueTypeFile::fromBytes(TrueTypeSubset::build($font, [$eAcute]));

        self::assertNotSame('', $subset->glyphData(1), '"é" lost its outline');
        self::assertSame($font->advanceWidth($eAcute), $subset->advanceWidth(1));
    }

    public function testEmbedsAUsableFontIntoADocument(): void
    {
        $font = EmbeddedFont::load(self::anInstalledFont());
        $text = 'Grüße — naïve';

        if (!$font->supports($text)) {
            self::markTestSkipped('The installed font does not cover the test text.');
        }

        $document = new Document();
        (new PageBuilder($document, $document->newPage()))->drawText($font, 12.0, 72, 700, $text);

        $pdf = $document->save();

        $saved = SavedDocument::fromBytes($pdf);
        $font = $saved->font();

        self::assertSame('CIDFontType2', SavedDocument::scalar($saved->from($font, 'DescendantFonts', 0, 'Subtype')));
        self::assertNotNull($saved->from($font, 'DescendantFonts', 0, 'FontDescriptor', 'FontFile2'));

        // Long enough to be a font program rather than an empty stream
        // the finalize pass never filled in.
        self::assertSame(1, preg_match('/\/Length1 (\d+)/', $pdf, $matches));
        self::assertGreaterThan(1000, (int) $matches[1]);
    }

    private static function anInstalledFont(): string
    {
        foreach (self::CANDIDATES as $path) {
            if (is_file($path) && is_readable($path)) {
                return $path;
            }
        }

        self::markTestSkipped('No TrueType font is installed to test against.');
    }
}
