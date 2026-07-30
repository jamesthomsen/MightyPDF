<?php

declare(strict_types=1);

namespace MightyPDF\Tests\Assembler\Form;

use MightyPDF\Assembler\Form\RadioGroupField;
use PHPUnit\Framework\TestCase;

final class RadioGroupFieldTest extends TestCase
{
    public function testNoSelectionDeclaresOffValueAndNoKids(): void
    {
        $field = new RadioGroupField(1, 'Color', null);
        $rendered = $field->render(false);

        self::assertStringContainsString('/FT /Btn', $rendered);
        self::assertStringContainsString('/V /Off', $rendered);
        self::assertStringContainsString('/Kids []', $rendered);
        self::assertStringContainsString('/Ff 32768', $rendered);
    }

    public function testCheckedExportValueIsUsedAsV(): void
    {
        $field = new RadioGroupField(1, 'Color', 'Blue');

        self::assertStringContainsString('/V /Blue', $field->render(false));
    }

    public function testAddKidAppendsToKidsArray(): void
    {
        $field = new RadioGroupField(1, 'Color', null);
        $field->addKid(5);
        $field->addKid(6);

        self::assertStringContainsString('/Kids [5 0 R 6 0 R]', $field->render(false));
    }
}
