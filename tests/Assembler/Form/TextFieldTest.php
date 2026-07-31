<?php

declare(strict_types=1);

namespace MightyPDF\Tests\Assembler\Form;

use MightyPDF\Assembler\Form\TextField;
use MightyPDF\Assembler\Types\PdfRectangle;
use PHPUnit\Framework\TestCase;

final class TextFieldTest extends TestCase
{
    public function testDeclaresFieldTypeNameRectAndDefaultAppearance(): void
    {
        $field = new TextField(1, 'FirstName', new PdfRectangle(10, 20, 110, 40), 'F1', 10.0);
        $rendered = $field->render(false);

        self::assertStringContainsString('/Type /Annot', $rendered);
        self::assertStringContainsString('/Subtype /Widget', $rendered);
        self::assertStringContainsString('/FT /Tx', $rendered);
        self::assertStringContainsString('/T (FirstName)', $rendered);
        self::assertStringContainsString('/Rect [10 20 110 40]', $rendered);
        self::assertStringContainsString('/DA (/F1 10 Tf 0 g)', $rendered);
        self::assertStringContainsString('/F 4', $rendered); // Print annotation flag
    }

    public function testOmitsValueAndMaxLengthWhenNotProvided(): void
    {
        $field = new TextField(1, 'Name', new PdfRectangle(0, 0, 100, 20), 'F1', 10.0);
        $rendered = $field->render(false);

        self::assertStringNotContainsString('/V', $rendered);
        self::assertStringNotContainsString('/MaxLen', $rendered);
    }

    public function testIncludesValueAndMaxLengthWhenProvided(): void
    {
        $field = new TextField(1, 'Name', new PdfRectangle(0, 0, 100, 20), 'F1', 10.0, value: 'Jane Doe', maxLength: 40);
        $rendered = $field->render(false);

        self::assertStringContainsString('/V (Jane Doe)', $rendered);
        self::assertStringContainsString('/MaxLen 40', $rendered);
    }

    /**
     * A field value is data the caller reads back out, not glyphs this
     * library renders, so it goes out as UTF-16BE rather than being
     * squeezed into a single-byte encoding. It used to be WinAnsi-encoded,
     * which turned anything outside CP1252 into literal "?" characters.
     */
    public function testNonAsciiValueIsUtf16be(): void
    {
        $field = new TextField(1, 'Name', new PdfRectangle(0, 0, 100, 20), 'F1', 10.0, value: 'café');
        $rendered = $field->render(false);

        self::assertStringContainsString("/V (\xFE\xFF\x00c\x00a\x00f\x00\xE9)", $rendered);
    }

    public function testCyrillicValueSurvivesInsteadOfBecomingQuestionMarks(): void
    {
        $field = new TextField(1, 'Name', new PdfRectangle(0, 0, 100, 20), 'F1', 10.0, value: 'Иванов');
        $rendered = $field->render(false);

        self::assertStringNotContainsString('??????', $rendered);
        self::assertStringContainsString("/V (\xFE\xFF" . iconv('UTF-8', 'UTF-16BE', 'Иванов') . ')', $rendered);
    }

    /**
     * /T is the key form-filling code looks a field up by, and it used to
     * be emitted as raw UTF-8 bytes inside a Latin-1 string -- so "Prénom"
     * reached readers as "PrÃ©nom".
     */
    public function testNonAsciiFieldNameIsUtf16be(): void
    {
        $field = new TextField(1, 'Prénom', new PdfRectangle(0, 0, 100, 20), 'F1', 10.0);
        $rendered = $field->render(false);

        self::assertStringNotContainsString("/T (Pr\xC3\xA9nom)", $rendered);
        self::assertStringContainsString("/T (\xFE\xFF" . iconv('UTF-8', 'UTF-16BE', 'Prénom') . ')', $rendered);
    }

    public function testAsciiNameAndValueStayPlainLiterals(): void
    {
        $field = new TextField(1, 'FirstName', new PdfRectangle(0, 0, 100, 20), 'F1', 10.0, value: 'Jane Doe');
        $rendered = $field->render(false);

        self::assertStringContainsString('/T (FirstName)', $rendered);
        self::assertStringContainsString('/V (Jane Doe)', $rendered);
    }

    public function testOmitsQAndFfByDefault(): void
    {
        $field = new TextField(1, 'Name', new PdfRectangle(0, 0, 100, 20), 'F1', 10.0);
        $rendered = $field->render(false);

        self::assertStringNotContainsString('/Q', $rendered);
        self::assertStringNotContainsString('/Ff', $rendered);
    }

    public function testAlignSetsQuadding(): void
    {
        $field = new TextField(1, 'Name', new PdfRectangle(0, 0, 100, 20), 'F1', 10.0, align: TextField::ALIGN_RIGHT);

        self::assertStringContainsString('/Q 2', $field->render(false));
    }

    public function testMultilineSetsMultilineFlag(): void
    {
        $field = new TextField(1, 'Name', new PdfRectangle(0, 0, 100, 20), 'F1', 10.0, multiline: true);

        self::assertStringContainsString('/Ff 4096', $field->render(false));
    }

    public function testReadonlySetsReadonlyFlag(): void
    {
        $field = new TextField(1, 'Name', new PdfRectangle(0, 0, 100, 20), 'F1', 10.0, readonly: true);

        self::assertStringContainsString('/Ff 1', $field->render(false));
    }

    public function testMultilineAndReadonlyFlagsCombine(): void
    {
        $field = new TextField(1, 'Name', new PdfRectangle(0, 0, 100, 20), 'F1', 10.0, multiline: true, readonly: true);

        self::assertStringContainsString('/Ff 4097', $field->render(false));
    }
}
