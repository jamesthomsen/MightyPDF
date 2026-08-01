<?php

declare(strict_types=1);

namespace MightyPDF\Tests\Editor;

use MightyPDF\Assembler\Dictionary;
use MightyPDF\Assembler\Types\PdfArray;
use MightyPDF\Assembler\Types\PdfReference;
use MightyPDF\Editor\EditedDocument;
use MightyPDF\Editor\Form\FormFiller;
use MightyPDF\Editor\PageOverlay;
use MightyPDF\Editor\PdfEditor;
use PHPUnit\Framework\TestCase;

/**
 * Adding fields to a document that already has a form.
 *
 * The catalog has room for exactly one /AcroForm, so a second one built
 * alongside the file's own does not half-work -- whichever loses is
 * simply not there, and the fields listed in it are invisible while
 * looking perfectly correct in the object model.
 */
final class AdoptedAcroFormTest extends TestCase
{
    public function testKeepsTheFieldsTheDocumentAlreadyHad(): void
    {
        $editor = PdfEditor::fromBytes(self::formDocument());
        self::addField($editor, 'signed_on');

        self::assertSame(
            ['first_name', 'subscribe', 'signed_on'],
            (new FormFiller($editor))->names(),
        );
    }

    public function testTheAdoptedFormIsStillTheOneTheCatalogPointsAt(): void
    {
        $editor = PdfEditor::fromBytes(self::formDocument());
        $before = $editor->catalog()->get('AcroForm')?->objectId();

        $form = (new EditedDocument($editor))->acroForm();

        self::assertSame($before, $form->objectId());
    }

    public function testCarriesForwardEntriesItDoesNotUnderstand(): void
    {
        // /SigFlags, /DA, /Q and anything else the original form said has
        // to survive: dropping one silently changes how the whole form
        // behaves.
        $editor = PdfEditor::fromBytes(self::formDocument());
        self::addField($editor, 'extra');

        $form = $editor->resolveDictionary($editor->catalog()->get('AcroForm'));

        self::assertSame(3, $form?->get('SigFlags')?->value());
        self::assertSame('/Helv 9 Tf 0 g', $form->get('DA')?->toUtf8());
    }

    public function testLeavesNeedsAppearancesExactlyAsItFoundIt(): void
    {
        // Turning it on would ask readers to redraw every field in the
        // document, not just the new one, which can visibly change fields
        // nobody touched.
        $editor = PdfEditor::fromBytes(self::formDocument());
        self::addField($editor, 'extra');

        $form = $editor->resolveDictionary($editor->catalog()->get('AcroForm'));

        self::assertNull($form?->get('NeedsAppearances'));
    }

    public function testDoesNotReuseAFontNameTheDocumentAlreadyMeansSomethingBy(): void
    {
        // The original /DR names /F1 for its own font. Handing /F1 out
        // again would repoint every existing field whose /DA says /F1.
        $editor = PdfEditor::fromBytes(self::formDocument());
        self::addField($editor, 'extra');

        $form = $editor->resolveDictionary($editor->catalog()->get('AcroForm'));
        $fonts = $editor->resolveDictionary($editor->resolveDictionary($form?->get('DR'))?->get('Font'));

        self::assertInstanceOf(Dictionary::class, $fonts);
        self::assertSame('7 0 R', $fonts->get('F1')?->format(), 'the original entry is untouched');
        self::assertGreaterThan(1, count($fonts->entries()), 'and the new font got a name of its own');
    }

    public function testReusesADrEntryAlreadyPointingAtTheSameFont(): void
    {
        $editor = PdfEditor::fromBytes(self::formDocument());
        $form = (new EditedDocument($editor))->acroForm();

        $font = new Dictionary(7);
        $first = $form->fontResourceName('Helvetica', $font);
        $second = $form->fontResourceName('SomeOtherKey', $font);

        self::assertSame('F1', $first, 'the existing /F1 already points at object 7');
        self::assertSame($first, $second);
    }

    public function testAddedFieldsCanThenBeFilled(): void
    {
        $editor = PdfEditor::fromBytes(self::formDocument());
        self::addField($editor, 'signed_on');

        $filler = new FormFiller($editor);
        $filler->set('signed_on', '31 July 2026');
        $filler->set('first_name', 'Jane');

        self::assertSame(
            ['first_name' => 'Jane', 'subscribe' => 'Off', 'signed_on' => '31 July 2026'],
            $filler->values(),
        );
    }

    public function testEverythingSurvivesSavingAndReopening(): void
    {
        $original = self::formDocument();
        $editor = PdfEditor::fromBytes($original);
        self::addField($editor, 'signed_on');
        (new FormFiller($editor))->set('signed_on', '31 July 2026');

        $saved = $editor->save();
        self::assertStringStartsWith($original, $saved);

        $reopened = new FormFiller(PdfEditor::fromBytes($saved));

        self::assertSame(['first_name', 'subscribe', 'signed_on'], $reopened->names());
        self::assertSame('31 July 2026', $reopened->values()['signed_on']);
    }

    public function testTheNewFieldIsAnnotatedOnThePage(): void
    {
        // A field listed in /Fields but not in any page's /Annots exists
        // in the document and appears on no page.
        $editor = PdfEditor::fromBytes(self::formDocument());
        self::addField($editor, 'signed_on');

        $page = self::firstPage($editor);
        $annots = $editor->resolve($page->get('Annots'));

        self::assertInstanceOf(PdfArray::class, $annots);
        self::assertCount(3, $annots->items(), 'the two original widgets plus the new one');
    }

    public function testPromotesAFormWrittenInlineInTheCatalog(): void
    {
        // An inline dictionary is not an object and cannot be rewritten on
        // its own, so it has to become one.
        $editor = PdfEditor::fromBytes(self::formDocument(inlineForm: true));
        $form = (new EditedDocument($editor))->acroForm();

        $reference = $editor->catalog()->get('AcroForm');

        self::assertInstanceOf(PdfReference::class, $reference);
        self::assertSame($form->objectId(), $reference->objectId());
    }

    /** Adds a text field to the first page through an overlay. */
    private static function addField(PdfEditor $editor, string $name): void
    {
        $overlay = new PageOverlay($editor, self::firstPage($editor));
        $overlay->content()->addTextField($name, x: 100, y: 100, width: 200, height: 20);
        $overlay->apply();
    }

    private static function firstPage(PdfEditor $editor): Dictionary
    {
        $tree = $editor->resolveDictionary($editor->catalog()->get('Pages'));
        $kids = $tree?->get('Kids');
        self::assertInstanceOf(PdfArray::class, $kids);

        $page = $editor->resolveDictionary($kids->items()[0]);
        self::assertNotNull($page);

        return $page;
    }

    /**
     * A document with an existing form: two fields, a /DR naming /F1, and
     * entries this library does not otherwise produce.
     */
    private static function formDocument(bool $inlineForm = false): string
    {
        $form = '<< /Fields [3 0 R 6 0 R] /DR << /Font << /F1 7 0 R >> >>'
            . ' /DA (/Helv 9 Tf 0 g) /SigFlags 3 /Q 1 >>';

        $objects = [
            1 => $inlineForm
                ? "<< /Type /Catalog /Pages 4 0 R /AcroForm $form >>"
                : '<< /Type /Catalog /Pages 4 0 R /AcroForm 2 0 R >>',
            2 => $form,
            3 => '<< /FT /Tx /T (first_name) /DA (/Helv 9 Tf 0 g) /Subtype /Widget /Rect [200 700 400 720] >>',
            4 => '<< /Type /Pages /Count 1 /Kids [5 0 R] >>',
            5 => '<< /Type /Page /Parent 4 0 R /MediaBox [0 0 612 792] /Annots [3 0 R 6 0 R] >>',
            6 => '<< /FT /Btn /T (subscribe) /Subtype /Widget /Rect [200 660 214 674] /V /Off /AS /Off'
                . ' /AP << /N << /Yes 8 0 R /Off 8 0 R >> >> >>',
            7 => '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica /Encoding /WinAnsiEncoding >>',
        ];

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
            $out .= sprintf("%010d 00000 n \n", $offsets[$id]);
        }

        return $out
            . "trailer\n<< /Size " . ($highest + 1) . " /Root 1 0 R >>\n"
            . "startxref\n{$xrefOffset}\n%%EOF\n";
    }
}
