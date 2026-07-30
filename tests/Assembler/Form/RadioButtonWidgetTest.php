<?php

declare(strict_types=1);

namespace MightyPDF\Tests\Assembler\Form;

use MightyPDF\Assembler\Form\RadioButtonWidget;
use MightyPDF\Assembler\Stream;
use MightyPDF\Assembler\Types\PdfRectangle;
use PHPUnit\Framework\TestCase;

final class RadioButtonWidgetTest extends TestCase
{
    public function testUncheckedWidgetDeclaresOffState(): void
    {
        $on = new Stream(3, '', compress: false);
        $off = new Stream(4, '', compress: false);
        $widget = new RadioButtonWidget(2, 1, new PdfRectangle(0, 0, 12, 12), 'Blue', false, $on, $off);
        $rendered = $widget->render(false);

        self::assertStringContainsString('/Subtype /Widget', $rendered);
        self::assertStringContainsString('/Parent 1 0 R', $rendered);
        self::assertStringContainsString('/AS /Off', $rendered);
        self::assertStringContainsString('/AP << /N << /Blue 3 0 R /Off 4 0 R >> >>', $rendered);
        self::assertStringNotContainsString('/FT', $rendered);
    }

    public function testCheckedWidgetDeclaresItsExportValueAsState(): void
    {
        $on = new Stream(3, '', compress: false);
        $off = new Stream(4, '', compress: false);
        $widget = new RadioButtonWidget(2, 1, new PdfRectangle(0, 0, 12, 12), 'Blue', true, $on, $off);

        self::assertStringContainsString('/AS /Blue', $widget->render(false));
    }
}
