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

    public function testValueIsWinAnsiEncoded(): void
    {
        $field = new TextField(1, 'Name', new PdfRectangle(0, 0, 100, 20), 'F1', 10.0, value: 'café');
        $rendered = $field->render(false);

        self::assertStringContainsString("/V (caf\xE9)", $rendered);
    }
}
