<?php

declare(strict_types=1);

namespace MightyPDF\Tests\Editor\Form;

use MightyPDF\Assembler\Document;
use MightyPDF\Content\PageBuilder;
use MightyPDF\Editor\Form\FormException;
use MightyPDF\Editor\Form\FormFiller;
use MightyPDF\Editor\Form\Xfdf;
use MightyPDF\Editor\PdfEditor;
use PHPUnit\Framework\TestCase;

final class FormDataTest extends TestCase
{
    private static function writtenForm(): string
    {
        $document = new Document();
        $page = $document->newPage();
        $content = new PageBuilder($document, $page);

        $content->addTextField('first_name', x: 200, y: 700, width: 250, height: 20, value: 'Ada');
        $content->addTextField('email', x: 200, y: 670, width: 250, height: 20);
        $content->addCheckbox('subscribe', x: 200, y: 640, size: 14, checked: true);
        $content->addRadioGroup('plan', [
            ['exportValue' => 'basic', 'x' => 200, 'y' => 610, 'size' => 14],
            ['exportValue' => 'pro', 'x' => 240, 'y' => 610, 'size' => 14],
        ], checkedExportValue: 'basic');

        return $document->save();
    }

    private static function filler(?string $pdf = null): FormFiller
    {
        return new FormFiller(PdfEditor::fromBytes($pdf ?? self::writtenForm()));
    }

    // -- XFDF ------------------------------------------------------------

    public function testXfdfCarriesEveryFieldAndItsValue(): void
    {
        $xml = self::filler()->toXfdf();

        self::assertStringContainsString('<xfdf xmlns="http://ns.adobe.com/xfdf/"', $xml);
        self::assertStringContainsString('<field name="first_name">', $xml);
        self::assertStringContainsString('<value>Ada</value>', $xml);
        self::assertStringContainsString('<field name="subscribe">', $xml);
        self::assertStringContainsString('<value>Yes</value>', $xml);
        self::assertStringContainsString('<value>basic</value>', $xml);
    }

    public function testXfdfNamesTheSourceFileWhenGivenOne(): void
    {
        self::assertStringContainsString('<f href="invoice.pdf"/>', self::filler()->toXfdf('invoice.pdf'));
        self::assertStringNotContainsString('<f ', self::filler()->toXfdf());
    }

    public function testAnEmptyFieldIsExportedAsAnEmptyValueRatherThanOmitted(): void
    {
        // Omitting it would mean an import could never clear the field.
        $xml = self::filler()->toXfdf();

        self::assertStringContainsString("<field name=\"email\">\n      <value></value>", $xml);
        self::assertArrayHasKey('email', Xfdf::parse($xml));
    }

    public function testXfdfPreservesWhitespaceInValues(): void
    {
        $editor = PdfEditor::fromBytes(self::writtenForm());
        $filler = new FormFiller($editor);
        $filler->set('first_name', 'Suite  4');

        self::assertStringContainsString('xml:space="preserve"', $filler->toXfdf());
        self::assertSame('Suite  4', Xfdf::parse($filler->toXfdf())['first_name']);
    }

    public function testAFormRoundTripsThroughXfdf(): void
    {
        $source = PdfEditor::fromBytes(self::writtenForm());
        $filler = new FormFiller($source);
        $filler->fill([
            'first_name' => 'Zoë Mikkelsen',
            'email' => 'zoe@example.com',
            'subscribe' => false,
            'plan' => 'pro',
        ]);

        $xml = $filler->toXfdf();

        // Into a fresh copy of the blank form.
        $target = new FormFiller(PdfEditor::fromBytes(self::writtenForm()));
        $target->fillFromXfdf($xml);

        self::assertSame(
            [
                'first_name' => 'Zoë Mikkelsen',
                'email' => 'zoe@example.com',
                'subscribe' => 'Off',
                'plan' => 'pro',
            ],
            $target->values(),
        );
    }

    public function testXfdfSurvivesASaveAndReopen(): void
    {
        $editor = PdfEditor::fromBytes(self::writtenForm());
        (new FormFiller($editor))->fillFromXfdf(
            '<?xml version="1.0"?><xfdf xmlns="http://ns.adobe.com/xfdf/">'
            . '<fields><field name="email"><value>filed@example.com</value></field></fields></xfdf>',
        );

        $reopened = new FormFiller(PdfEditor::fromBytes($editor->save()));

        self::assertSame('filed@example.com', $reopened->values()['email']);
    }

    public function testNestedFieldNamesAreWrittenAsNestedElements(): void
    {
        $xml = Xfdf::export(['address.line1' => 'One Road', 'address.city' => 'Leeds']);

        self::assertStringContainsString('<field name="address">', $xml);
        self::assertStringContainsString('<field name="line1">', $xml);

        // One <address>, holding both, rather than one per leaf.
        self::assertSame(1, substr_count($xml, '<field name="address">'));
    }

    public function testNestedElementsComeBackAsDottedNames(): void
    {
        $values = Xfdf::parse(
            '<?xml version="1.0"?><xfdf xmlns="http://ns.adobe.com/xfdf/"><fields>'
            . '<field name="address"><field name="city"><value>Leeds</value></field></field>'
            . '</fields></xfdf>',
        );

        self::assertSame(['address.city' => 'Leeds'], $values);
    }

    public function testFlatDottedNamesAreAlsoAccepted(): void
    {
        // Not what Acrobat writes, but what a hand-rolled exporter does.
        $values = Xfdf::parse(
            '<?xml version="1.0"?><xfdf xmlns="http://ns.adobe.com/xfdf/"><fields>'
            . '<field name="address.city"><value>Leeds</value></field></fields></xfdf>',
        );

        self::assertSame(['address.city' => 'Leeds'], $values);
    }

    public function testXfdfWithoutTheNamespaceIsStillRead(): void
    {
        // Plenty of real exporters omit it, and refusing the file over a
        // missing xmlns helps nobody.
        $values = Xfdf::parse('<xfdf><fields><field name="a"><value>1</value></field></fields></xfdf>');

        self::assertSame(['a' => '1'], $values);
    }

    public function testMalformedXfdfIsRefused(): void
    {
        $this->expectException(FormException::class);
        $this->expectExceptionMessage('well-formed XML');

        Xfdf::parse('<xfdf><fields>');
    }

    public function testAnFdfFileGivenToTheXfdfParserIsNamed(): void
    {
        $this->expectException(FormException::class);
        $this->expectExceptionMessage('not an XFDF file');

        Xfdf::parse('<foo/>');
    }

    public function testAnExternalEntityIsNotResolved(): void
    {
        $xml = '<?xml version="1.0"?><!DOCTYPE xfdf [<!ENTITY x SYSTEM "file:///etc/passwd">]>'
            . '<xfdf><fields><field name="a"><value>&x;</value></field></fields></xfdf>';

        // Either refused or read with the entity empty -- what it must
        // never be is the contents of the file.
        try {
            $values = Xfdf::parse($xml);
            self::assertStringNotContainsString('root:', $values['a'] ?? '');
        } catch (FormException) {
            self::assertTrue(true);
        }
    }

    // -- JSON ------------------------------------------------------------

    public function testJsonCarriesEveryFieldIncludingTheEmptyOnes(): void
    {
        $json = json_decode(self::filler()->toJson(), true);

        self::assertSame(
            ['first_name' => 'Ada', 'email' => null, 'subscribe' => 'Yes', 'plan' => 'basic'],
            $json,
        );
    }

    public function testAFormWithNoFieldsIsAnEmptyObjectNotAnEmptyList(): void
    {
        $document = new Document();
        $document->newPage();

        self::assertSame('{}', trim(self::filler($document->save())->toJson()));
    }

    public function testJsonIsNotEscapedIntoUnreadability(): void
    {
        $editor = PdfEditor::fromBytes(self::writtenForm());
        $filler = new FormFiller($editor);
        $filler->set('first_name', 'Zoë');

        self::assertStringContainsString('Zoë', $filler->toJson());
    }

    public function testAFormRoundTripsThroughJson(): void
    {
        $source = new FormFiller(PdfEditor::fromBytes(self::writtenForm()));
        $source->set('email', 'ada@example.com');

        $target = new FormFiller(PdfEditor::fromBytes(self::writtenForm()));
        $target->fillFromJson($source->toJson());

        self::assertSame($source->values(), $target->values());
    }

    public function testInvalidJsonIsRefused(): void
    {
        $this->expectException(FormException::class);
        $this->expectExceptionMessage('not valid JSON');

        self::filler()->fillFromJson('{nope');
    }

    public function testAJsonListIsRefused(): void
    {
        $this->expectException(FormException::class);
        $this->expectExceptionMessage('must be a JSON object');

        self::filler()->fillFromJson('"just a string"');
    }

    public function testANestedObjectIsRefusedRatherThanFlattened(): void
    {
        $this->expectException(FormException::class);
        $this->expectExceptionMessage('dotted full name');

        self::filler()->fillFromJson('{"address": {"city": "Leeds"}}');
    }

    public function testAnUnknownFieldStillReportsTheUsualSuggestion(): void
    {
        $this->expectException(FormException::class);

        self::filler()->fillFromJson('{"first_nam": "Ada"}');
    }
}
