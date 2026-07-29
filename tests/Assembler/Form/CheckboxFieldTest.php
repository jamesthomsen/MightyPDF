<?php

declare(strict_types=1);

namespace MightyPDF\Tests\Assembler\Form;

use MightyPDF\Assembler\Form\CheckboxField;
use MightyPDF\Assembler\Stream;
use MightyPDF\Assembler\Types\PdfRectangle;
use PHPUnit\Framework\TestCase;

final class CheckboxFieldTest extends TestCase
{
    public function testUncheckedFieldDeclaresOffState(): void
    {
        $on = new Stream(2, '', compress: false);
        $off = new Stream(3, '', compress: false);
        $field = new CheckboxField(1, 'Agree', new PdfRectangle(0, 0, 12, 12), false, $on, $off);
        $rendered = $field->render(false);

        self::assertStringContainsString('/FT /Btn', $rendered);
        self::assertStringContainsString('/V /Off', $rendered);
        self::assertStringContainsString('/AS /Off', $rendered);
        self::assertStringContainsString('/AP << /N << /Yes 2 0 R /Off 3 0 R >> >>', $rendered);
    }

    public function testCheckedFieldDeclaresYesState(): void
    {
        $on = new Stream(2, '', compress: false);
        $off = new Stream(3, '', compress: false);
        $field = new CheckboxField(1, 'Agree', new PdfRectangle(0, 0, 12, 12), true, $on, $off);
        $rendered = $field->render(false);

        self::assertStringContainsString('/V /Yes', $rendered);
        self::assertStringContainsString('/AS /Yes', $rendered);
    }
}
