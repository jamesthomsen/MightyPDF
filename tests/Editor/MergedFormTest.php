<?php

declare(strict_types=1);

namespace MightyPDF\Tests\Editor;

use MightyPDF\Assembler\Document;
use MightyPDF\Assembler\Types\PdfArray;
use MightyPDF\Content\Font\StandardFont;
use MightyPDF\Content\PageBuilder;
use MightyPDF\Editor\Form\FormFiller;
use MightyPDF\Editor\PdfEditor;
use MightyPDF\Editor\PdfMerger;
use MightyPDF\Reader\ObjectStore;
use PHPUnit\Framework\TestCase;

/**
 * Merging documents that have forms.
 *
 * Checked through FormFiller wherever possible rather than by reading
 * the object graph: a merged form is only worth anything if something
 * can go on to fill it, and the names it reports are what a caller has
 * to address the fields by.
 */
final class MergedFormTest extends TestCase
{
    /** @var list<string> */
    private array $paths = [];

    protected function tearDown(): void
    {
        foreach ($this->paths as $path) {
            @unlink($path);
        }

        $this->paths = [];
    }

    public function testFieldsFromEverySourceReachTheMergedDocument(): void
    {
        $merged = $this->merge(
            $this->form(['applicant' => 'Ada']),
            $this->form(['referee' => 'Grace']),
        );

        self::assertSame(['applicant', 'referee'], $this->fillerFor($merged)->names());
    }

    /**
     * Two files that each have a "signature" field are not describing
     * one field. Left sharing a name they would share a value, so
     * filling either would fill both.
     */
    public function testFieldsWithTheSameNameInDifferentFilesAreKeptApart(): void
    {
        $merged = $this->merge(
            $this->form(['signature' => 'first']),
            $this->form(['signature' => 'second']),
        );

        $values = $this->fillerFor($merged)->values();

        self::assertSame(['signature', 'signature_2'], array_keys($values));
        self::assertSame('first', $values['signature']);
        self::assertSame('second', $values['signature_2']);
    }

    public function testARenamedFieldCanStillBeFilledOnItsOwn(): void
    {
        $merged = $this->merge(
            $this->form(['note' => 'a']),
            $this->form(['note' => 'b']),
        );

        $editor = PdfEditor::fromBytes($merged);
        (new FormFiller($editor))->fill(['note_2' => 'edited']);

        $values = $this->fillerFor($editor->save())->values();

        self::assertSame('a', $values['note'], 'the field of the same name is untouched');
        self::assertSame('edited', $values['note_2']);
    }

    public function testCheckboxesAndRadioGroupsSurviveWithTheirStates(): void
    {
        $document = new Document();
        $content = new PageBuilder($document, $document->newPage());
        $content->addCheckbox('agree', x: 72, y: 620, size: 14, checked: true);
        $content->addRadioGroup('plan', [
            ['exportValue' => 'Basic', 'x' => 72, 'y' => 580, 'size' => 12],
            ['exportValue' => 'Pro', 'x' => 120, 'y' => 580, 'size' => 12],
        ], checkedExportValue: 'Pro');

        $filler = $this->fillerFor($this->merge($this->write($document)));

        self::assertSame('Yes', $filler->values()['agree']);
        self::assertSame('Pro', $filler->values()['plan']);
        self::assertSame(['Basic', 'Pro'], $filler->field('plan')->onStates);
    }

    /**
     * A radio group is one field with a widget per button. Both widgets
     * have to arrive, on the page they were on, under the one field --
     * a group that loses a button silently offers fewer choices.
     */
    public function testEveryWidgetOfAFieldIsCarriedOver(): void
    {
        $document = new Document();
        (new PageBuilder($document, $document->newPage()))->addRadioGroup('plan', [
            ['exportValue' => 'Basic', 'x' => 72, 'y' => 580, 'size' => 12],
            ['exportValue' => 'Pro', 'x' => 120, 'y' => 580, 'size' => 12],
        ]);

        $store = new ObjectStore($this->merge($this->write($document)));
        $fields = $store->resolve($this->formOf($store)->get('Fields'));

        self::assertInstanceOf(PdfArray::class, $fields);
        self::assertCount(1, $fields->items(), 'one field, not one per button');

        $kids = $store->resolve($store->resolveDictionary($fields->items()[0])?->get('Kids'));
        self::assertInstanceOf(PdfArray::class, $kids);
        self::assertCount(2, $kids->items());
    }

    /** Each page keeps its own widgets, and none of anyone else's. */
    public function testWidgetsLandOnThePageTheyCameFrom(): void
    {
        $merged = $this->merge(
            $this->form(['first' => 'a']),
            $this->form(['second' => 'b']),
        );

        $store = new ObjectStore($merged);
        $kids = $store->resolve($store->resolveDictionary($store->catalog()->get('Pages'))?->get('Kids'));
        self::assertInstanceOf(PdfArray::class, $kids);

        self::assertSame(['first'], $this->widgetNamesOn($store, $kids->items()[0]));
        self::assertSame(['second'], $this->widgetNamesOn($store, $kids->items()[1]));
    }

    /**
     * A field's /DA names a font from the form's /DR. Where two sources
     * disagree about what a name means, one of them has to be renamed --
     * and the /DA strings that referred to it have to follow.
     */
    public function testFieldsKeepTheirOwnFontWhenTwoFormsNameItDifferently(): void
    {
        $merged = $this->merge(
            $this->form(['a' => 'x'], StandardFont::Helvetica),
            $this->form(['b' => 'y'], StandardFont::Courier),
        );

        $store = new ObjectStore($merged);
        $resources = $store->resolveDictionary($this->formOf($store)->get('DR'));
        $fonts = $store->resolveDictionary($resources?->get('Font'));

        // Both forms called their font /F1; the merged /DR has to hold
        // both fonts under names that still say which is which.
        self::assertNotNull($fonts?->get('F1'));
        self::assertNotNull($fonts->get('F1_2'), 'the second form\'s font was kept too');

        $names = [];
        foreach ($fonts->entries() as $name => $reference) {
            $names[(string) $name] = $store->resolveDictionary($reference)?->get('BaseFont')?->format();
        }

        self::assertSame(['F1' => '/Helvetica', 'F1_2' => '/Courier'], $names);
        self::assertStringContainsString('/F1_2', $this->defaultAppearanceOf($store, 'b'));
        self::assertStringContainsString('/F1 ', $this->defaultAppearanceOf($store, 'a'));
    }

    public function testAFontBothFormsAgreeOnIsSharedRatherThanDuplicated(): void
    {
        $merged = $this->merge(
            $this->form(['a' => 'x'], StandardFont::Helvetica),
            $this->form(['b' => 'y'], StandardFont::Helvetica),
        );

        $store = new ObjectStore($merged);
        $fonts = $store->resolveDictionary(
            $store->resolveDictionary($this->formOf($store)->get('DR'))?->get('Font'),
        );

        self::assertCount(1, $fonts?->entries() ?? []);
    }

    /** Merging documents with no fields must not leave an empty form behind. */
    public function testADocumentWithNoFieldsGetsNoForm(): void
    {
        $document = new Document();
        (new PageBuilder($document, $document->newPage()))
            ->drawText(StandardFont::Helvetica, 12.0, 72, 720, 'no fields here');

        $store = new ObjectStore($this->merge($this->write($document)));

        self::assertNull($store->catalog()->get('AcroForm'));
    }

    /**
     * The widgets brought their appearance streams with them, so a
     * merged document asks for regeneration only where a source asked
     * for it -- not merely because it is a new document.
     */
    public function testTheAppearanceFlagFollowsTheSources(): void
    {
        $merged = $this->merge($this->form(['a' => 'x']));

        $store = new ObjectStore($merged);

        self::assertSame('true', $this->formOf($store)->get('NeedAppearances')?->format());
    }

    /** @param array<string, string> $fields name => value */
    private function form(array $fields, StandardFont $font = StandardFont::Helvetica): string
    {
        $document = new Document();
        $content = new PageBuilder($document, $document->newPage());
        $y = 700;

        foreach ($fields as $name => $value) {
            $content->addTextField($name, x: 72, y: $y, width: 200, height: 20, value: $value, font: $font);
            $y -= 40;
        }

        return $this->write($document);
    }

    private function write(Document $document): string
    {
        $path = tempnam(sys_get_temp_dir(), 'mightypdf-form-') . '.pdf';
        $document->saveToFile($path);
        $this->paths[] = $path;

        return $path;
    }

    private function merge(string ...$paths): string
    {
        return PdfMerger::merge(...$paths)->save();
    }

    private function fillerFor(string $pdf): FormFiller
    {
        return new FormFiller(PdfEditor::fromBytes($pdf));
    }

    private function formOf(ObjectStore $store): \MightyPDF\Assembler\Dictionary
    {
        $form = $store->resolveDictionary($store->catalog()->get('AcroForm'));
        self::assertNotNull($form);

        return $form;
    }

    /** @return list<string> */
    private function widgetNamesOn(ObjectStore $store, \MightyPDF\Assembler\Types\PdfValue $page): array
    {
        $annots = $store->resolve($store->resolveDictionary($page)?->get('Annots'));

        if (!$annots instanceof PdfArray) {
            return [];
        }

        $names = [];

        foreach ($annots->items() as $item) {
            $name = $store->resolveDictionary($item)?->get('T');

            if ($name !== null) {
                $names[] = $name->toUtf8();
            }
        }

        return $names;
    }

    private function defaultAppearanceOf(ObjectStore $store, string $fieldName): string
    {
        $fields = $store->resolve($this->formOf($store)->get('Fields'));
        self::assertInstanceOf(PdfArray::class, $fields);

        foreach ($fields->items() as $item) {
            $field = $store->resolveDictionary($item);

            if ($field?->get('T')?->toUtf8() === $fieldName) {
                return $field->get('DA')?->toUtf8() ?? '';
            }
        }

        self::fail("No field named $fieldName in the merged form.");
    }
}
