<?php

declare(strict_types=1);

namespace MightyPDF\Tests\Editor\Form;

use MightyPDF\Assembler\Dictionary;
use MightyPDF\Assembler\Stream;
use MightyPDF\Content\Font\StandardFont;
use MightyPDF\Editor\Form\FormFiller;
use MightyPDF\Editor\PdfEditor;
use PHPUnit\Framework\TestCase;

/**
 * The appearance stream a filled text field gets, which is what makes it
 * visible in a reader that does not regenerate appearances itself.
 */
final class TextAppearanceBuilderTest extends TestCase
{
    public function testDrawsTheValueIntoAFormXObject(): void
    {
        $appearance = self::fill(self::form(), 'name', 'Jane Roe');

        self::assertInstanceOf(Stream::class, $appearance);
        self::assertSame('XObject', $appearance->get('Type')?->value());
        self::assertSame('Form', $appearance->get('Subtype')?->value());
        self::assertSame('[0 0 200 20]', $appearance->get('BBox')?->format());
    }

    public function testMarksTheRunAsFieldTextAndClipsIt(): void
    {
        // "/Tx BMC ... EMC" is how a reader recognises the part it may
        // replace; the clip is what stops an over-long value spilling
        // across the page instead of being cut off at the field's edge.
        $content = self::content(self::fill(self::form(), 'name', 'Jane Roe'));

        self::assertStringContainsString('/Tx BMC', $content);
        self::assertStringContainsString('EMC', $content);
        self::assertStringContainsString("0 0 200 20 re\nW\nn", $content);
    }

    public function testReplaysTheDefaultAppearanceOperatorsVerbatim(): void
    {
        // Whatever colour or spacing the form's author put in /DA has to
        // survive -- it is the only statement the document makes about how
        // its fields should look.
        $content = self::content(self::fill(self::form('/Helv 9 Tf 0.2 0.3 0.9 rg'), 'name', 'Jane'));

        self::assertStringContainsString('/Helv 9 Tf 0.2 0.3 0.9 rg', $content);
    }

    public function testEncodesTheDrawnTextForTheFont(): void
    {
        // The stored value stays full Unicode; only the picture of it is
        // transliterated into what the font can actually show.
        $content = self::content(self::fill(self::form(), 'name', 'Zoë'));

        self::assertStringContainsString("(Zo\xEB) Tj", $content);
    }

    public function testTheStoredValueIsNotDamagedByWhatTheFontCannotDraw(): void
    {
        $editor = PdfEditor::fromBytes(self::form());
        $filler = new FormFiller($editor);
        $filler->set('name', 'Ω Zoë');

        self::assertSame('Ω Zoë', $filler->values()['name']);
    }

    public function testCentresAndRightAlignsAccordingToQuadding(): void
    {
        $left = self::textOrigin(self::content(self::fill(self::form(quadding: 0), 'name', 'Jane')));
        $centre = self::textOrigin(self::content(self::fill(self::form(quadding: 1), 'name', 'Jane')));
        $right = self::textOrigin(self::content(self::fill(self::form(quadding: 2), 'name', 'Jane')));

        self::assertSame(2.0, $left);
        self::assertGreaterThan($left, $centre);
        self::assertGreaterThan($centre, $right);
    }

    public function testWrapsAMultilineFieldAndStartsAtTheTop(): void
    {
        // Table 228 bit 13.
        $content = self::content(self::fill(
            self::form(flags: 1 << 12, height: 60),
            'name',
            'The quick brown fox jumps over the lazy dog again and again',
        ));

        self::assertGreaterThan(1, substr_count($content, ' Tj'), 'should have wrapped onto several lines');

        // Lines run downwards from near the top of the box.
        preg_match_all('/1 0 0 1 [\d.]+ ([\d.]+) Tm/', $content, $matches);
        $baselines = array_map('floatval', $matches[1]);

        self::assertGreaterThan($baselines[1], $baselines[0]);
        self::assertGreaterThan(40.0, $baselines[0]);
    }

    public function testSpacesACombFieldIntoEqualCells(): void
    {
        // Table 228 bit 25. Drawn as running text it lines up with none of
        // the printed boxes it is meant to sit in.
        $content = self::content(self::fill(
            self::form(flags: 1 << 24, maxLength: 5),
            'name',
            'ABCDE',
        ));

        preg_match_all('/1 0 0 1 ([\d.]+) [\d.]+ Tm\n\((.)\) Tj/', $content, $matches, PREG_SET_ORDER);

        self::assertCount(5, $matches, 'one run per character');

        // The *cells* are evenly spaced, not the glyph origins: each
        // character is centred in its own cell, so a narrow "I" starts
        // further into its cell than a wide "W" does.
        $metrics = StandardFont::Helvetica->metrics();

        foreach ($matches as $cell => [, $x, $character]) {
            $centre = (float) $x + $metrics->widthOf($character, 10.0) / 2.0;

            self::assertEqualsWithDelta(40.0 * $cell + 20.0, $centre, 0.001, "cell $cell");
        }
    }

    public function testChoosesASizeForAnAutoSizedField(): void
    {
        // A /DA size of 0 means "fit it", not "draw nothing".
        $content = self::content(self::fill(self::form('/Helv 0 Tf 0 g'), 'name', 'Jane Roe'));

        self::assertMatchesRegularExpression('/\/Helv ([\d.]+) Tf/', $content);

        preg_match('/\/Helv ([\d.]+) Tf/', $content, $matches);
        self::assertGreaterThan(0.0, (float) $matches[1]);
        self::assertLessThanOrEqual(20.0, (float) $matches[1], 'must still fit the 20pt-high box');
    }

    public function testAutoSizeShrinksToFitALongValue(): void
    {
        $short = self::sizeIn(self::content(self::fill(self::form('/Helv 0 Tf 0 g'), 'name', 'Jo')));
        $long = self::sizeIn(self::content(self::fill(
            self::form('/Helv 0 Tf 0 g'),
            'name',
            'A considerably longer value than the box comfortably holds',
        )));

        self::assertLessThan($short, $long);
    }

    public function testFallsBackToTheReaderWhenThereIsNoDefaultAppearance(): void
    {
        // Nothing says what font to draw with, so drawing anything would
        // be a guess. The stale stream goes and the flag is set instead.
        $editor = PdfEditor::fromBytes(self::form(defaultAppearance: null));
        (new FormFiller($editor))->set('name', 'Jane');

        $widget = self::widget($editor, 'name');
        self::assertNull($widget->get('AP'));

        $acroForm = $editor->resolveDictionary($editor->catalog()->get('AcroForm'));
        self::assertTrue($acroForm?->get('NeedsAppearances')?->value());
    }

    public function testFallsBackWhenTheFontsWidthsAreNowhereInTheFile(): void
    {
        // Without widths the text cannot be measured, so it could only be
        // positioned by guesswork -- which for a centred field is visibly
        // wrong rather than approximately right.
        $editor = PdfEditor::fromBytes(self::form(baseFont: 'SomeEmbeddedFont'));
        (new FormFiller($editor))->set('name', 'Jane');

        self::assertNull(self::widget($editor, 'name')->get('AP'));
    }

    public function testClearingAFieldLeavesAnEmptyAppearanceRatherThanAStaleOne(): void
    {
        $editor = PdfEditor::fromBytes(self::form());
        $filler = new FormFiller($editor);
        $filler->set('name', 'Jane Roe');
        $filler->set('name', null);

        $appearance = self::appearanceOf($editor, 'name');

        self::assertInstanceOf(Stream::class, $appearance);
        self::assertStringNotContainsString('Jane Roe', $appearance->rawBytes());
    }

    public function testTheAppearanceCarriesTheFontItDrawsWith(): void
    {
        // A Form XObject has its own resources; the /DA font name means
        // nothing inside it unless it is defined there too.
        $appearance = self::fill(self::form(), 'name', 'Jane');
        self::assertInstanceOf(Stream::class, $appearance);

        $resources = $appearance->get('Resources');
        self::assertInstanceOf(Dictionary::class, $resources);

        $fonts = $resources->get('Font');
        self::assertInstanceOf(Dictionary::class, $fonts);
        self::assertNotNull($fonts->get('Helv'));
    }

    private static function fill(string $pdf, string $field, string $value): ?Stream
    {
        $editor = PdfEditor::fromBytes($pdf);
        (new FormFiller($editor))->set($field, $value);

        return self::appearanceOf($editor, $field);
    }

    private static function appearanceOf(PdfEditor $editor, string $field): ?Stream
    {
        $appearances = $editor->resolveDictionary(self::widget($editor, $field)->get('AP'));
        $normal = $editor->resolve($appearances?->get('N'));

        return $normal instanceof Stream ? $normal : null;
    }

    private static function widget(PdfEditor $editor, string $field): Dictionary
    {
        $found = (new FormFiller($editor))->field($field);
        self::assertNotNull($found);

        return $found->widgets[0];
    }

    /**
     * A stream that has just been built holds its bytes uncompressed --
     * compression happens on the way out (see Stream::rawBytes()), so
     * there is nothing to inflate until it has been written.
     */
    private static function content(?Stream $appearance): string
    {
        self::assertInstanceOf(Stream::class, $appearance);

        return $appearance->rawBytes();
    }

    private static function textOrigin(string $content): float
    {
        self::assertSame(1, preg_match('/1 0 0 1 ([\d.]+) [\d.]+ Tm/', $content, $matches));

        return (float) $matches[1];
    }

    private static function sizeIn(string $content): float
    {
        self::assertSame(1, preg_match('/\/Helv ([\d.]+) Tf/', $content, $matches));

        return (float) $matches[1];
    }

    /** A one-field form whose /DR defines /Helv as a standard font. */
    private static function form(
        ?string $defaultAppearance = '/Helv 10 Tf 0 g',
        int $quadding = 0,
        int $flags = 0,
        ?int $maxLength = null,
        float $height = 20.0,
        string $baseFont = 'Helvetica',
    ): string {
        $da = $defaultAppearance === null ? '' : "/DA ($defaultAppearance) ";
        $ff = $flags === 0 ? '' : "/Ff $flags ";
        $max = $maxLength === null ? '' : "/MaxLen $maxLength ";
        $top = 100 + $height;

        $objects = [
            1 => '<< /Type /Catalog /Pages 4 0 R /AcroForm 2 0 R >>',
            2 => '<< /Fields [3 0 R] /DR << /Font << /Helv 6 0 R >> >> >>',
            3 => "<< /FT /Tx /T (name) {$da}{$ff}{$max}/Q $quadding /Subtype /Widget"
                . " /Rect [100 100 300 $top] >>",
            4 => '<< /Type /Pages /Count 1 /Kids [5 0 R] >>',
            5 => '<< /Type /Page /Parent 4 0 R /MediaBox [0 0 612 792] /Annots [3 0 R] >>',
            6 => "<< /Type /Font /Subtype /Type1 /BaseFont /$baseFont /Encoding /WinAnsiEncoding >>",
        ];

        $out = "%PDF-1.7\n";
        $offsets = [];

        foreach ($objects as $id => $body) {
            $offsets[$id] = strlen($out);
            $out .= "$id 0 obj\n$body\nendobj\n";
        }

        $xrefOffset = strlen($out);
        $out .= "xref\n0 7\n0000000000 65535 f \n";

        for ($id = 1; $id <= 6; ++$id) {
            $out .= sprintf("%010d 00000 n \n", $offsets[$id]);
        }

        return $out . "trailer\n<< /Size 7 /Root 1 0 R >>\nstartxref\n{$xrefOffset}\n%%EOF\n";
    }
}
