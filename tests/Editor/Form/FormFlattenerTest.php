<?php

declare(strict_types=1);

namespace MightyPDF\Tests\Editor\Form;

use MightyPDF\Assembler\Document;
use MightyPDF\Assembler\Stream;
use MightyPDF\Assembler\Types\PdfArray;
use MightyPDF\Assembler\Types\PdfValue;
use MightyPDF\Content\PageBuilder;
use MightyPDF\Editor\Form\FormException;
use MightyPDF\Editor\Form\FormFiller;
use MightyPDF\Editor\Form\FormFlattener;
use MightyPDF\Editor\PageTree;
use MightyPDF\Editor\PdfEditor;
use PHPUnit\Framework\TestCase;

final class FormFlattenerTest extends TestCase
{
    public function testDoesNothingToADocumentWithNoForm(): void
    {
        $document = new Document();
        $document->newPage();
        $original = $document->save();

        $editor = PdfEditor::fromBytes($original);
        (new FormFlattener($editor))->flatten();

        self::assertSame($original, $editor->save());
    }

    public function testRemovesTheFormAndItsWidgets(): void
    {
        $editor = PdfEditor::fromBytes(self::filledForm());
        (new FormFlattener($editor))->flatten();

        $flattened = PdfEditor::fromBytes($editor->save());

        self::assertNull($flattened->catalog()->get('AcroForm'));
        self::assertFalse((new FormFiller($flattened))->hasForm());
        self::assertSame([], (new FormFiller($flattened))->names());
        self::assertNull((new PageTree($flattened))->page(0)?->get('Annots'));
    }

    public function testTheValueBecomesPageContent(): void
    {
        $editor = PdfEditor::fromBytes(self::filledForm());
        (new FormFlattener($editor))->flatten();

        // The point of the whole exercise: what the form showed is now
        // drawn by the page, so it survives having no form at all.
        //
        // WinAnsi rather than UTF-8, because the appearance stream is
        // placed exactly as the form filler drew it -- nothing is
        // re-encoded on the way onto the page, which is the same reason
        // nothing is re-drawn.
        self::assertStringContainsString("Zo\xEB Mikkelsen", self::drawnText($editor->save()));
    }

    public function testPlacesTheAppearanceWhereTheWidgetWas(): void
    {
        $editor = PdfEditor::fromBytes(self::filledForm());
        (new FormFlattener($editor))->flatten();

        // The text field was declared at x: 200, y: 700 with a /BBox
        // starting at the origin, so an unrotated appearance is simply
        // translated there.
        self::assertStringContainsString('1 0 0 1 200 700 cm', self::drawnOperators($editor->save()));
    }

    /**
     * The §12.5.5 mapping, on the one case that tells a correct
     * implementation from the usual one.
     *
     * /Matrix turns the appearance a quarter turn, so its 100x50 /BBox
     * occupies x from -50 to 0 and y from 0 to 100 once transformed. The
     * *transformed* box is what maps onto /Rect, which means the
     * translation has to be +60 in x. An implementation that merely
     * shifts to the rect's corner emits 10 there, and puts the field 50
     * points off the mark.
     */
    public function testMapsTheTransformedBoundingBoxOntoTheRectangle(): void
    {
        $editor = PdfEditor::fromBytes(self::rotatedAppearanceForm());
        (new FormFlattener($editor))->flatten();

        self::assertStringContainsString('1 0 0 1 60 20 cm', self::drawnOperators($editor->save()));
    }

    public function testScalesAnAppearanceThatDoesNotMatchItsRectangle(): void
    {
        $editor = PdfEditor::fromBytes(self::scaledAppearanceForm());
        (new FormFlattener($editor))->flatten();

        // /BBox is 100x50 and /Rect is 200x200, so it is stretched by 2
        // horizontally and 4 vertically rather than drawn at its own size.
        self::assertStringContainsString('2 0 0 4 0 0 cm', self::drawnOperators($editor->save()));
    }

    public function testRefusesFieldsThatWouldFlattenToBlankPaper(): void
    {
        // A form straight from the writer: its text fields rely on
        // /NeedAppearances and have no stream of their own yet.
        $editor = PdfEditor::fromBytes(self::writtenForm());
        $flattener = new FormFlattener($editor);

        try {
            $flattener->flatten();
            self::fail('Expected a FormException.');
        } catch (FormException $e) {
            self::assertStringContainsString('first_name', $e->getMessage());
            self::assertStringContainsString('/NeedAppearances', $e->getMessage());
        }

        self::assertSame(['first_name'], $flattener->withoutAppearance());
    }

    public function testARefusalLeavesTheDocumentUntouched(): void
    {
        $original = self::writtenForm();
        $editor = PdfEditor::fromBytes($original);

        try {
            (new FormFlattener($editor))->flatten();
        } catch (FormException) {
            // Expected; the point is what did not happen to the document.
        }

        self::assertSame([], $editor->changedObjects());
        self::assertSame($original, $editor->save());
    }

    public function testFlattensBlankFieldsWhenAskedTo(): void
    {
        $editor = PdfEditor::fromBytes(self::writtenForm());
        $flattener = new FormFlattener($editor);
        $flattener->flatten(allowBlankFields: true);

        self::assertSame(['first_name'], $flattener->withoutAppearance());
        self::assertFalse((new FormFiller(PdfEditor::fromBytes($editor->save())))->hasForm());
    }

    public function testFlatteningSomeFieldsLeavesTheRestFillable(): void
    {
        $editor = PdfEditor::fromBytes(self::filledForm());
        (new FormFlattener($editor))->flatten(['subscribe']);

        $filler = new FormFiller(PdfEditor::fromBytes($editor->save()));

        self::assertSame(['first_name', 'plan'], $filler->names());

        // The text field was not named, so it is still a field and its
        // value has not been drawn onto the page.
        self::assertStringNotContainsString('Mikkelsen', self::drawnText($editor->save()));
    }

    public function testRefusesAnUnknownFieldName(): void
    {
        $editor = PdfEditor::fromBytes(self::filledForm());

        $this->expectException(FormException::class);
        $this->expectExceptionMessage('no form field named "postcode"');

        (new FormFlattener($editor))->flatten(['postcode']);
    }

    public function testRefusesToFlattenASignedSignature(): void
    {
        $editor = PdfEditor::fromBytes(self::signedForm());

        $this->expectException(FormException::class);
        $this->expectExceptionMessage('holds a digital signature');

        (new FormFlattener($editor))->flatten();
    }

    public function testKeepsAnnotationsThatAreNotFormFields(): void
    {
        $editor = PdfEditor::fromBytes(self::formWithALink());
        (new FormFlattener($editor))->flatten(allowBlankFields: true);

        $annotations = $editor->resolve((new PageTree($editor))->page(0)?->get('Annots'));

        self::assertInstanceOf(PdfArray::class, $annotations);
        self::assertCount(1, $annotations->items());

        // The link, not the widget: flattening a form is not a licence to
        // strip a page of everything annotation-shaped.
        self::assertSame(
            '/Link',
            $editor->resolveDictionary($annotations->items()[0])?->get('Subtype')?->format(),
        );
    }

    public function testAHiddenWidgetIsRemovedWithoutBeingDrawn(): void
    {
        $editor = PdfEditor::fromBytes(self::hiddenWidgetForm());
        $flattener = new FormFlattener($editor);
        $flattener->flatten();

        // Hidden means it was showing nothing, so it does not count as a
        // field that flattens to blank paper...
        self::assertSame([], $flattener->withoutAppearance());

        // ...and nothing is drawn for it.
        self::assertStringNotContainsString('Do', self::drawnOperators($editor->save()));
        self::assertNull((new PageTree(PdfEditor::fromBytes($editor->save())))->page(0)?->get('Annots'));
    }

    public function testFlattenedFieldsAreReported(): void
    {
        $editor = PdfEditor::fromBytes(self::filledForm());
        $flattener = new FormFlattener($editor);
        $flattener->flatten();

        self::assertSame(['first_name', 'subscribe', 'plan'], $flattener->flattened());
    }

    /** Every content stream a page draws, decoded and concatenated. */
    private static function drawnOperators(string $pdf): string
    {
        $editor = PdfEditor::fromBytes($pdf);
        $page = (new PageTree($editor))->page(0);

        self::assertNotNull($page);

        $out = '';

        foreach (self::streamsOf($editor, $page->get('Contents')) as $stream) {
            $out .= $editor->store()->decodedStream($stream) . "\n";
        }

        // Flattening draws through a form XObject (see PageOverlay), so
        // the operators of interest are one level down from the page.
        $resources = $editor->resolveDictionary($page->get('Resources'));
        $xObjects = $editor->resolveDictionary($resources?->get('XObject'));

        foreach ($xObjects?->entries() ?? [] as $value) {
            $form = $editor->resolve($value);

            if ($form instanceof Stream && $editor->store()->canDecode($form)) {
                $out .= $editor->store()->decodedStream($form) . "\n";
            }
        }

        return $out;
    }

    /** The text inside the appearance streams the flattened page places. */
    private static function drawnText(string $pdf): string
    {
        $editor = PdfEditor::fromBytes($pdf);
        $page = (new PageTree($editor))->page(0);

        self::assertNotNull($page);

        $resources = $editor->resolveDictionary($page->get('Resources'));
        $out = '';

        foreach ($editor->resolveDictionary($resources?->get('XObject'))?->entries() ?? [] as $value) {
            $form = $editor->resolve($value);

            if (!$form instanceof Stream) {
                continue;
            }

            $inner = $editor->resolveDictionary($form->get('Resources'));

            foreach ($editor->resolveDictionary($inner?->get('XObject'))?->entries() ?? [] as $nested) {
                $appearance = $editor->resolve($nested);

                if ($appearance instanceof Stream && $editor->store()->canDecode($appearance)) {
                    $out .= $editor->store()->decodedStream($appearance) . "\n";
                }
            }
        }

        return $out;
    }

    /** @return list<Stream> */
    private static function streamsOf(PdfEditor $editor, ?PdfValue $contents): array
    {
        $resolved = $editor->resolve($contents);
        $items = $resolved instanceof PdfArray ? $resolved->items() : [$contents];

        $streams = [];

        foreach ($items as $item) {
            $stream = $editor->resolve($item);

            if ($stream instanceof Stream && $editor->store()->canDecode($stream)) {
                $streams[] = $stream;
            }
        }

        return $streams;
    }

    /** A form as the writer produces it: no appearance streams for text. */
    private static function writtenForm(): string
    {
        $document = new Document();
        $page = $document->newPage();
        $content = new PageBuilder($document, $page);

        $content->addTextField('first_name', x: 200, y: 700, width: 250, height: 20);

        return $document->save();
    }

    /** The same form after FormFiller has been over it and drawn it. */
    private static function filledForm(): string
    {
        $document = new Document();
        $page = $document->newPage();
        $content = new PageBuilder($document, $page);

        $content->addTextField('first_name', x: 200, y: 700, width: 250, height: 20);
        $content->addCheckbox('subscribe', x: 200, y: 640, size: 14, checked: false);
        $content->addRadioGroup('plan', [
            ['exportValue' => 'basic', 'x' => 200, 'y' => 610, 'size' => 14],
            ['exportValue' => 'pro', 'x' => 240, 'y' => 610, 'size' => 14],
        ], checkedExportValue: 'basic');

        $editor = PdfEditor::fromBytes($document->save());

        (new FormFiller($editor))->fill([
            'first_name' => 'Zoë Mikkelsen',
            'subscribe' => true,
            'plan' => 'pro',
        ]);

        return $editor->save();
    }

    private static function rotatedAppearanceForm(): string
    {
        return self::assemble([
            1 => '<< /Type /Catalog /Pages 4 0 R /AcroForm 2 0 R >>',
            2 => '<< /Fields [3 0 R] >>',
            3 => '<< /FT /Tx /T (turned) /Subtype /Widget /Rect [10 20 60 120] /AP << /N 6 0 R >> >>',
            4 => '<< /Type /Pages /Count 1 /Kids [5 0 R] >>',
            5 => '<< /Type /Page /Parent 4 0 R /MediaBox [0 0 612 792] /Annots [3 0 R] >>',
            6 => self::stream('/Type /XObject /Subtype /Form /BBox [0 0 100 50] /Matrix [0 1 -1 0 0 0]', '1 0 0 RG'),
        ]);
    }

    private static function scaledAppearanceForm(): string
    {
        return self::assemble([
            1 => '<< /Type /Catalog /Pages 4 0 R /AcroForm 2 0 R >>',
            2 => '<< /Fields [3 0 R] >>',
            3 => '<< /FT /Tx /T (stretched) /Subtype /Widget /Rect [0 0 200 200] /AP << /N 6 0 R >> >>',
            4 => '<< /Type /Pages /Count 1 /Kids [5 0 R] >>',
            5 => '<< /Type /Page /Parent 4 0 R /MediaBox [0 0 612 792] /Annots [3 0 R] >>',
            6 => self::stream('/Type /XObject /Subtype /Form /BBox [0 0 100 50]', '1 0 0 RG'),
        ]);
    }

    private static function signedForm(): string
    {
        return self::assemble([
            1 => '<< /Type /Catalog /Pages 4 0 R /AcroForm 2 0 R >>',
            2 => '<< /Fields [3 0 R] >>',
            3 => '<< /FT /Sig /T (approval) /Subtype /Widget /Rect [0 0 10 10] /V 6 0 R'
                . ' /AP << /N 7 0 R >> >>',
            4 => '<< /Type /Pages /Count 1 /Kids [5 0 R] >>',
            5 => '<< /Type /Page /Parent 4 0 R /MediaBox [0 0 612 792] /Annots [3 0 R] >>',
            6 => '<< /Type /Sig /Filter /Adobe.PPKLite /Contents <00> >>',
            7 => self::stream('/Type /XObject /Subtype /Form /BBox [0 0 10 10]', '1 0 0 RG'),
        ]);
    }

    private static function formWithALink(): string
    {
        return self::assemble([
            1 => '<< /Type /Catalog /Pages 4 0 R /AcroForm 2 0 R >>',
            2 => '<< /Fields [3 0 R] >>',
            3 => '<< /FT /Tx /T (name) /Subtype /Widget /Rect [0 0 10 10] >>',
            4 => '<< /Type /Pages /Count 1 /Kids [5 0 R] >>',
            5 => '<< /Type /Page /Parent 4 0 R /MediaBox [0 0 612 792] /Annots [3 0 R 6 0 R] >>',
            6 => '<< /Type /Annot /Subtype /Link /Rect [0 40 10 50] /A << /S /URI /URI (https://example.com) >> >>',
        ]);
    }

    private static function hiddenWidgetForm(): string
    {
        return self::assemble([
            1 => '<< /Type /Catalog /Pages 4 0 R /AcroForm 2 0 R >>',
            2 => '<< /Fields [3 0 R] >>',
            // /F 2 is the Hidden flag: no appearance, and none needed.
            3 => '<< /FT /Tx /T (invisible) /Subtype /Widget /Rect [0 0 10 10] /F 2 >>',
            4 => '<< /Type /Pages /Count 1 /Kids [5 0 R] >>',
            5 => '<< /Type /Page /Parent 4 0 R /MediaBox [0 0 612 792] /Annots [3 0 R] >>',
        ]);
    }

    private static function stream(string $entries, string $body): string
    {
        return sprintf("<< %s /Length %d >>\nstream\n%s\nendstream", $entries, strlen($body), $body);
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
