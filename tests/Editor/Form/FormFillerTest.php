<?php

declare(strict_types=1);

namespace MightyPDF\Tests\Editor\Form;

use MightyPDF\Assembler\Document;
use MightyPDF\Assembler\Types\PdfArray;
use MightyPDF\Content\PageBuilder;
use MightyPDF\Editor\Form\FieldType;
use MightyPDF\Editor\Form\FormException;
use MightyPDF\Editor\Form\FormFiller;
use MightyPDF\Editor\PdfEditor;
use PHPUnit\Framework\TestCase;

final class FormFillerTest extends TestCase
{
    public function testFindsNoFormInAPlainDocument(): void
    {
        $document = new Document();
        $document->newPage();

        self::assertFalse(self::filler($document->save())->hasForm());
        self::assertSame([], self::filler($document->save())->names());
    }

    public function testDiscoversFieldsAndTheirTypes(): void
    {
        $filler = self::filler(self::writtenForm());

        self::assertSame(['first_name', 'email', 'subscribe', 'plan'], $filler->names());
        self::assertSame(FieldType::Text, $filler->field('first_name')?->type);
        self::assertSame(FieldType::Checkbox, $filler->field('subscribe')?->type);
        self::assertSame(FieldType::RadioGroup, $filler->field('plan')?->type);
    }

    public function testReadsCurrentValues(): void
    {
        self::assertSame(
            ['first_name' => 'PLACEHOLDER', 'email' => null, 'subscribe' => 'Off', 'plan' => 'basic'],
            self::filler(self::writtenForm())->values(),
        );
    }

    public function testFilledValuesSurviveASaveAndReopen(): void
    {
        $editor = PdfEditor::fromBytes(self::writtenForm());

        (new FormFiller($editor))->fill([
            'first_name' => 'Zoë Mikkelsen',
            'email' => 'zoe@example.com',
            'subscribe' => true,
            'plan' => 'pro',
        ]);

        self::assertSame(
            [
                'first_name' => 'Zoë Mikkelsen',
                'email' => 'zoe@example.com',
                'subscribe' => 'Yes',
                'plan' => 'pro',
            ],
            self::filler($editor->save())->values(),
        );
    }

    public function testWritesTheAppearanceStateOnEveryWidgetOfARadioGroup(): void
    {
        // The heart of it. Setting /V alone leaves a group where every
        // extraction tool agrees "pro" is selected and every human sees
        // the old button still filled in -- so each widget has to be told,
        // including the ones being turned *off*.
        $editor = PdfEditor::fromBytes(self::writtenForm());
        (new FormFiller($editor))->set('plan', 'pro');

        $field = (new FormFiller($editor))->field('plan');
        self::assertNotNull($field);
        self::assertCount(3, $field->widgets);

        $states = array_map(
            static fn ($widget): ?string => $widget->get('AS')?->value(),
            $field->widgets,
        );

        self::assertSame(['Off', 'pro', 'Off'], $states);
        self::assertSame('pro', $field->dictionary->get('V')?->value());
    }

    public function testWritesTheAppearanceStateOnACheckbox(): void
    {
        $editor = PdfEditor::fromBytes(self::writtenForm());
        (new FormFiller($editor))->set('subscribe', true);

        $field = (new FormFiller($editor))->field('subscribe');

        self::assertSame('Yes', $field?->dictionary->get('V')?->value());
        self::assertSame('Yes', $field->widgets[0]->get('AS')?->value());
    }

    public function testTurningACheckboxOffUsesTheOffState(): void
    {
        $editor = PdfEditor::fromBytes(self::writtenForm());
        $filler = new FormFiller($editor);
        $filler->set('subscribe', true);
        $filler->set('subscribe', false);

        $field = (new FormFiller($editor))->field('subscribe');

        self::assertSame('Off', $field?->dictionary->get('V')?->value());
        self::assertSame('Off', $field->widgets[0]->get('AS')?->value());
    }

    public function testUsesTheStateNamesTheDocumentActuallyDefines(): void
    {
        // "Yes" is a convention, not a rule. A form whose author called it
        // /On must be filled with /On.
        $filler = self::filler(self::customStateCheckboxForm());

        self::assertSame(['On'], $filler->field('agree')?->onStates);
    }

    public function testRefusesAStateTheWidgetHasNoAppearanceFor(): void
    {
        $this->expectException(FormException::class);
        $this->expectExceptionMessage('/On');

        self::filler(self::customStateCheckboxForm())->set('agree', 'Yes');
    }

    public function testTrueIsAmbiguousWhenThereIsMoreThanOneOnState(): void
    {
        $this->expectException(FormException::class);
        $this->expectExceptionMessage('ambiguous');

        self::filler(self::writtenForm())->set('plan', true);
    }

    public function testJoinsHierarchicalFieldNamesWithDots(): void
    {
        // A flat scan of /Fields would never find these.
        $filler = self::filler(self::hierarchicalForm());

        self::assertSame(['address.line1', 'address.line2'], $filler->names());
    }

    public function testInheritsFieldTypeFromAnAncestor(): void
    {
        // /FT is inheritable, so a leaf may carry none of its own.
        self::assertSame(FieldType::Text, self::filler(self::hierarchicalForm())->field('address.line1')?->type);
    }

    public function testHandlesAFieldWhoseWidgetsAreSeparateObjects(): void
    {
        // One field appearing on several pages -- the value goes on the
        // field, the appearance state on each widget.
        $editor = PdfEditor::fromBytes(self::multiWidgetForm());
        $field = (new FormFiller($editor))->field('signature_date');

        self::assertNotNull($field);
        self::assertCount(2, $field->widgets);
        self::assertNotSame($field->dictionary, $field->widgets[0]);
    }

    public function testDiscardsStaleAppearancesOnEveryWidget(): void
    {
        // A reader that ignores /NeedsAppearances would otherwise render
        // the appearance stream for the previous value, perfectly, on
        // every page the field appears on.
        $editor = PdfEditor::fromBytes(self::multiWidgetForm());
        (new FormFiller($editor))->set('signature_date', '2026-07-31');

        foreach ((new FormFiller($editor))->field('signature_date')->widgets as $widget) {
            self::assertNull($widget->get('AP'));
        }
    }

    public function testAsksTheReaderToRegenerateAppearances(): void
    {
        $editor = PdfEditor::fromBytes(self::writtenForm());
        (new FormFiller($editor))->set('email', 'someone@example.com');

        $acroForm = $editor->resolveDictionary($editor->catalog()->get('AcroForm'));

        self::assertTrue($acroForm?->get('NeedsAppearances')?->value());
    }

    public function testRefusesAValueLongerThanMaxLen(): void
    {
        // Truncating silently would put data in the file nobody asked for.
        $this->expectException(FormException::class);
        $this->expectExceptionMessage('at most 5 characters');

        self::filler(self::maxLengthForm())->set('code', 'far too long');
    }

    public function testAcceptsAValueExactlyAtMaxLen(): void
    {
        $editor = PdfEditor::fromBytes(self::maxLengthForm());
        (new FormFiller($editor))->set('code', 'ABCDE');

        self::assertSame('ABCDE', (new FormFiller($editor))->values()['code']);
    }

    public function testClearingAFieldRemovesItsValue(): void
    {
        $editor = PdfEditor::fromBytes(self::writtenForm());
        (new FormFiller($editor))->set('first_name', null);

        self::assertNull((new FormFiller($editor))->values()['first_name']);
    }

    public function testSetsAChoiceValueAndItsSelectedIndex(): void
    {
        $editor = PdfEditor::fromBytes(self::choiceForm());
        (new FormFiller($editor))->set('country', 'Denmark');

        $field = (new FormFiller($editor))->field('country');

        self::assertSame(['Norway', 'Denmark', 'Sweden'], $field?->options);
        self::assertSame('Denmark', (new FormFiller($editor))->values()['country']);

        $indices = $field->dictionary->get('I');
        self::assertInstanceOf(PdfArray::class, $indices);
        self::assertSame(1, $indices->items()[0]->value());
    }

    public function testRefusesAChoiceThatIsNotOnOffer(): void
    {
        $this->expectException(FormException::class);
        $this->expectExceptionMessage('"Norway", "Denmark", "Sweden"');

        self::filler(self::choiceForm())->set('country', 'Iceland');
    }

    public function testRefusesToFillAPushButton(): void
    {
        $this->expectException(FormException::class);
        $this->expectExceptionMessage('holds no value');

        self::filler(self::pushButtonForm())->set('submit', 'x');
    }

    public function testNamesTheClosestFieldWhenAskedForOneThatIsNotThere(): void
    {
        // Hierarchical names mean the usual mistake is a real field
        // addressed by its leaf name alone.
        $this->expectException(FormException::class);
        $this->expectExceptionMessage('Did you mean "address.line1"?');

        self::filler(self::hierarchicalForm())->set('address.line', 'x');
    }

    public function testListsTheFieldsWhenThereIsNoNearMiss(): void
    {
        $this->expectException(FormException::class);
        $this->expectExceptionMessage('Available fields: "first_name", "email", "subscribe", "plan"');

        self::filler(self::writtenForm())->set('completely_different', 'x');
    }

    public function testRefusesAnXfaFormUnlessAskedTwice(): void
    {
        // Acrobat may honour the XFA description instead of these fields,
        // so the fill would look correct everywhere except the reader most
        // people use.
        $editor = PdfEditor::fromBytes(self::xfaForm());

        self::assertTrue((new FormFiller($editor))->isXfaForm());

        try {
            (new FormFiller($editor))->set('name', 'Jane');
            self::fail('expected a FormException');
        } catch (FormException $e) {
            self::assertStringContainsString('XFA', $e->getMessage());
        }

        (new FormFiller($editor, allowXfa: true))->set('name', 'Jane');

        self::assertSame('Jane', (new FormFiller($editor, allowXfa: true))->values()['name']);
    }

    public function testACycleInTheFieldTreeDoesNotHangOrCostTheOtherFields(): void
    {
        // The cyclic branch yields nothing -- its nodes all have field
        // children, so none of them is terminal once the loop is cut --
        // but that must stay contained rather than taking the rest of the
        // form down with it.
        $filler = self::filler(self::cyclicForm());

        self::assertSame(['reachable'], $filler->names());
    }

    public function testEverythingItChangedIsWrittenToTheUpdate(): void
    {
        // A mutated object that never reaches register() is simply lost:
        // the in-memory document would look right and the saved file
        // would not.
        $editor = PdfEditor::fromBytes(self::writtenForm());
        (new FormFiller($editor))->fill(['first_name' => 'Jane', 'plan' => 'team']);

        $changed = $editor->changedObjects();

        // The text field, the radio group, its three widgets, the AcroForm.
        self::assertCount(6, $changed);
        self::assertSame(
            ['first_name' => 'Jane', 'email' => null, 'subscribe' => 'Off', 'plan' => 'team'],
            self::filler($editor->save())->values(),
        );
    }

    private static function filler(string $pdf): FormFiller
    {
        return new FormFiller(PdfEditor::fromBytes($pdf));
    }

    private static function writtenForm(): string
    {
        $document = new Document();
        $page = $document->newPage();
        $content = new PageBuilder($document, $page);

        $content->addTextField('first_name', x: 200, y: 700, width: 250, height: 20, value: 'PLACEHOLDER');
        $content->addTextField('email', x: 200, y: 670, width: 250, height: 20);
        $content->addCheckbox('subscribe', x: 200, y: 640, size: 14, checked: false);
        $content->addRadioGroup('plan', [
            ['exportValue' => 'basic', 'x' => 200, 'y' => 610, 'size' => 14],
            ['exportValue' => 'pro', 'x' => 240, 'y' => 610, 'size' => 14],
            ['exportValue' => 'team', 'x' => 280, 'y' => 610, 'size' => 14],
        ], checkedExportValue: 'basic');

        return $document->save();
    }

    private static function customStateCheckboxForm(): string
    {
        return self::assemble([
            1 => '<< /Type /Catalog /Pages 4 0 R /AcroForm 2 0 R >>',
            2 => '<< /Fields [3 0 R] >>',
            3 => '<< /FT /Btn /T (agree) /Subtype /Widget /Rect [0 0 10 10] /V /Off /AS /Off'
                . ' /AP << /N << /On 9 0 R /Off 9 0 R >> >> >>',
            4 => '<< /Type /Pages /Count 1 /Kids [5 0 R] >>',
            5 => '<< /Type /Page /Parent 4 0 R /MediaBox [0 0 612 792] /Annots [3 0 R] >>',
        ]);
    }

    private static function hierarchicalForm(): string
    {
        return self::assemble([
            1 => '<< /Type /Catalog /Pages 4 0 R /AcroForm 2 0 R >>',
            2 => '<< /Fields [3 0 R] >>',
            // /FT lives only on the parent and is inherited by both leaves.
            3 => '<< /T (address) /FT /Tx /Kids [6 0 R 7 0 R] >>',
            4 => '<< /Type /Pages /Count 1 /Kids [5 0 R] >>',
            5 => '<< /Type /Page /Parent 4 0 R /MediaBox [0 0 612 792] /Annots [6 0 R 7 0 R] >>',
            6 => '<< /T (line1) /Parent 3 0 R /Subtype /Widget /Rect [0 0 10 10] >>',
            7 => '<< /T (line2) /Parent 3 0 R /Subtype /Widget /Rect [0 20 10 30] >>',
        ]);
    }

    private static function multiWidgetForm(): string
    {
        return self::assemble([
            1 => '<< /Type /Catalog /Pages 4 0 R /AcroForm 2 0 R >>',
            2 => '<< /Fields [3 0 R] >>',
            // No /T on the kids, so they are this field's widgets rather
            // than fields of their own.
            3 => '<< /FT /Tx /T (signature_date) /Kids [6 0 R 7 0 R] >>',
            4 => '<< /Type /Pages /Count 1 /Kids [5 0 R] >>',
            5 => '<< /Type /Page /Parent 4 0 R /MediaBox [0 0 612 792] /Annots [6 0 R 7 0 R] >>',
            6 => '<< /Parent 3 0 R /Subtype /Widget /Rect [0 0 10 10] /AP << /N 9 0 R >> >>',
            7 => '<< /Parent 3 0 R /Subtype /Widget /Rect [0 20 10 30] /AP << /N 9 0 R >> >>',
        ]);
    }

    private static function maxLengthForm(): string
    {
        return self::assemble([
            1 => '<< /Type /Catalog /Pages 4 0 R /AcroForm 2 0 R >>',
            2 => '<< /Fields [3 0 R] >>',
            3 => '<< /FT /Tx /T (code) /MaxLen 5 /Subtype /Widget /Rect [0 0 10 10] >>',
            4 => '<< /Type /Pages /Count 1 /Kids [5 0 R] >>',
            5 => '<< /Type /Page /Parent 4 0 R /MediaBox [0 0 612 792] /Annots [3 0 R] >>',
        ]);
    }

    private static function choiceForm(): string
    {
        return self::assemble([
            1 => '<< /Type /Catalog /Pages 4 0 R /AcroForm 2 0 R >>',
            2 => '<< /Fields [3 0 R] >>',
            3 => '<< /FT /Ch /T (country) /Opt [(Norway) (Denmark) (Sweden)]'
                . ' /Subtype /Widget /Rect [0 0 10 10] >>',
            4 => '<< /Type /Pages /Count 1 /Kids [5 0 R] >>',
            5 => '<< /Type /Page /Parent 4 0 R /MediaBox [0 0 612 792] /Annots [3 0 R] >>',
        ]);
    }

    private static function pushButtonForm(): string
    {
        return self::assemble([
            1 => '<< /Type /Catalog /Pages 4 0 R /AcroForm 2 0 R >>',
            2 => '<< /Fields [3 0 R] >>',
            // /Ff bit 17 marks it a push button.
            3 => '<< /FT /Btn /Ff 65536 /T (submit) /Subtype /Widget /Rect [0 0 10 10] >>',
            4 => '<< /Type /Pages /Count 1 /Kids [5 0 R] >>',
            5 => '<< /Type /Page /Parent 4 0 R /MediaBox [0 0 612 792] /Annots [3 0 R] >>',
        ]);
    }

    private static function xfaForm(): string
    {
        return self::assemble([
            1 => '<< /Type /Catalog /Pages 4 0 R /AcroForm 2 0 R >>',
            2 => '<< /Fields [3 0 R] /XFA [(preamble) 9 0 R] >>',
            3 => '<< /FT /Tx /T (name) /Subtype /Widget /Rect [0 0 10 10] >>',
            4 => '<< /Type /Pages /Count 1 /Kids [5 0 R] >>',
            5 => '<< /Type /Page /Parent 4 0 R /MediaBox [0 0 612 792] /Annots [3 0 R] >>',
        ]);
    }

    private static function cyclicForm(): string
    {
        return self::assemble([
            1 => '<< /Type /Catalog /Pages 4 0 R /AcroForm 2 0 R >>',
            2 => '<< /Fields [3 0 R 7 0 R] >>',
            3 => '<< /T (parent) /FT /Tx /Kids [6 0 R] >>',
            4 => '<< /Type /Pages /Count 1 /Kids [5 0 R] >>',
            5 => '<< /Type /Page /Parent 4 0 R /MediaBox [0 0 612 792] /Annots [7 0 R] >>',
            // Points back at its own parent.
            6 => '<< /T (child) /Kids [3 0 R] >>',
            7 => '<< /FT /Tx /T (reachable) /Subtype /Widget /Rect [0 0 10 10] >>',
        ]);
    }

    /**
     * Assembles a complete, valid PDF from object bodies.
     *
     * @param array<int, string> $objects object id => body
     */
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
