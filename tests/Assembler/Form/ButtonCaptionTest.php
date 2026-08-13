<?php

declare(strict_types=1);

namespace MightyPDF\Tests\Assembler\Form;

use MightyPDF\Assembler\Document;
use MightyPDF\Assembler\Dictionary;
use MightyPDF\Assembler\Types\PdfArray;
use MightyPDF\Assembler\Types\PdfString;
use MightyPDF\Content\MarkStyle;
use MightyPDF\Content\PageBuilder;
use MightyPDF\Editor\PdfEditor;
use PHPUnit\Framework\TestCase;

/**
 * A form built by this library sets /NeedAppearances, which asks readers
 * to rebuild the appearance of *every* widget in it -- including the
 * checkboxes and radio buttons whose marks PageBuilder drew as vector
 * paths. A reader doing as it was asked throws those away, so what it
 * draws instead has to be described: /MK /CA says which glyph, and /DR
 * has to carry the font it is in.
 *
 * Found by running poppler over the examples rather than by any test:
 * pdftotext reported "Unknown font tag 'ZaDb'" on all three form
 * examples and still exited 0, while 1595 tests passed. These assert
 * against the document read back, not against its bytes, because the
 * question is what a reader finds when it looks -- which is exactly the
 * question a substring match cannot ask.
 */
final class ButtonCaptionTest extends TestCase
{
    public function testACheckboxNamesTheFontItsCaptionIsDrawnIn(): void
    {
        $form = self::readBackFormOf(static function (PageBuilder $content): void {
            $content->addCheckbox('subscribe', 100, 700, 14);
        });

        $fonts = self::dictionaryAt($form, 'DR', 'Font');

        // The literal name matters: readers look for /ZaDb rather than
        // reading the /DA, so the same font called /F2 is not found.
        self::assertNotNull($fonts->get('ZaDb'), '/DR should carry the dingbat font under its conventional name');
    }

    public function testTheCaptionCharacterMatchesTheMarkThatWasDrawn(): void
    {
        foreach ([[MarkStyle::Check, '4'], [MarkStyle::Dot, 'l'], [MarkStyle::Square, 'n']] as [$mark, $expected]) {
            $editor = self::readBack(static function (PageBuilder $content) use ($mark): void {
                $content->addCheckbox('box', 100, 700, 14, mark: $mark);
            });

            $field = self::firstFieldOf($editor);
            $characteristics = $editor->resolveDictionary($field->get('MK'));

            self::assertInstanceOf(Dictionary::class, $characteristics);

            $caption = $editor->resolve($characteristics->get('CA'));
            self::assertInstanceOf(PdfString::class, $caption);
            self::assertSame($expected, $caption->bytes(), "mark {$mark->name} should regenerate as '$expected'");
        }
    }

    public function testARadioOptionCarriesTheSameDescription(): void
    {
        $editor = self::readBack(static function (PageBuilder $content): void {
            $content->addRadioGroup('plan', [
                ['exportValue' => 'Basic', 'x' => 100.0, 'y' => 700.0, 'size' => 12.0],
                ['exportValue' => 'Pro', 'x' => 100.0, 'y' => 680.0, 'size' => 12.0],
            ], 'Pro');
        });

        $group = self::firstFieldOf($editor);
        $kids = $editor->resolve($group->get('Kids'));
        self::assertInstanceOf(PdfArray::class, $kids);

        $widget = $editor->resolveDictionary($kids->items()[0] ?? null);
        self::assertInstanceOf(Dictionary::class, $widget);

        $characteristics = $editor->resolveDictionary($widget->get('MK'));
        self::assertInstanceOf(Dictionary::class, $characteristics);

        // The default for a radio group is a dot, not a check.
        $caption = $editor->resolve($characteristics->get('CA'));
        self::assertInstanceOf(PdfString::class, $caption);
        self::assertSame('l', $caption->bytes());
    }

    /**
     * A document with no buttons has no reason to carry the dingbats:
     * the entry exists to describe a mark, and there is no mark.
     */
    public function testATextOnlyFormDoesNotCarryTheDingbatFont(): void
    {
        $form = self::readBackFormOf(static function (PageBuilder $content): void {
            $content->addTextField('name', 100, 700, 200, 20);
        });

        $fonts = self::dictionaryAt($form, 'DR', 'Font');

        self::assertNull($fonts->get('ZaDb'));
    }

    /** @param \Closure(PageBuilder): void $draw */
    private static function readBack(\Closure $draw): PdfEditor
    {
        $document = new Document();
        $page = $document->newPage();
        $draw(new PageBuilder($document, $page));

        return PdfEditor::fromBytes($document->save());
    }

    /** @param \Closure(PageBuilder): void $draw */
    private static function readBackFormOf(\Closure $draw): Dictionary
    {
        $editor = self::readBack($draw);
        $form = $editor->resolveDictionary($editor->catalog()->get('AcroForm'));

        self::assertInstanceOf(Dictionary::class, $form);

        return $form;
    }

    private static function firstFieldOf(PdfEditor $editor): Dictionary
    {
        $form = $editor->resolveDictionary($editor->catalog()->get('AcroForm'));
        self::assertInstanceOf(Dictionary::class, $form);

        $fields = $editor->resolve($form->get('Fields'));
        self::assertInstanceOf(PdfArray::class, $fields);

        $field = $editor->resolveDictionary($fields->items()[0] ?? null);
        self::assertInstanceOf(Dictionary::class, $field);

        return $field;
    }

    private static function dictionaryAt(Dictionary $from, string ...$keys): Dictionary
    {
        $current = $from;

        foreach ($keys as $key) {
            $next = $current->get($key);
            self::assertInstanceOf(Dictionary::class, $next, "expected a dictionary at /$key");
            $current = $next;
        }

        return $current;
    }
}
