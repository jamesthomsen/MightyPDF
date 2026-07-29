<?php

declare(strict_types=1);

namespace MightyPDF\Tests\Assembler\Form;

use MightyPDF\Assembler\Dictionary;
use MightyPDF\Assembler\Form\AcroForm;
use PHPUnit\Framework\TestCase;

final class AcroFormTest extends TestCase
{
    public function testStartsWithNoFieldsAndNeedsAppearancesTrue(): void
    {
        $rendered = (new AcroForm(1))->render(false);

        self::assertStringContainsString('/Fields []', $rendered);
        self::assertStringContainsString('/NeedsAppearances true', $rendered);
        self::assertStringContainsString('/DR <<>>', $rendered);
    }

    public function testAddFieldAppendsAReference(): void
    {
        $form = new AcroForm(1);
        $form->addField(5);
        $form->addField(6);

        self::assertStringContainsString('/Fields [5 0 R 6 0 R]', $form->render(false));
    }

    public function testDefaultResourcesAccessorReturnsTheLiveDrDictionary(): void
    {
        $form = new AcroForm(1);
        $form->defaultResources()->set('Font', new Dictionary());

        self::assertStringContainsString('/DR << /Font <<>> >>', $form->render(false));
    }
}
