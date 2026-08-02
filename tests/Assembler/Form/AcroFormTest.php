<?php

declare(strict_types=1);

namespace MightyPDF\Tests\Assembler\Form;

use MightyPDF\Assembler\Dictionary;
use MightyPDF\Assembler\Form\AcroForm;
use PHPUnit\Framework\TestCase;

final class AcroFormTest extends TestCase
{
    public function testStartsWithNoFieldsAndNeedAppearancesTrue(): void
    {
        $rendered = (new AcroForm(1))->render(false);

        self::assertStringContainsString('/Fields []', $rendered);
        self::assertStringContainsString('/NeedAppearances true', $rendered);
        self::assertStringContainsString('/DR <<>>', $rendered);
    }

    /**
     * The key is /NeedAppearances, and this library spelled it
     * /NeedsAppearances for its whole life -- which reads perfectly
     * naturally and is not the name in ISO 32000-2 Table 226.
     *
     * Nothing complains about that. A reader ignores dictionary keys it
     * does not know, so a document asking to have its field appearances
     * regenerated was simply not asking, and text fields created with a
     * value showed empty boxes. The assertion below is deliberately on
     * the exact string rather than through get(), since reading it back
     * by the same name the writer used is what hid this.
     */
    public function testTheAppearanceFlagIsSpelledTheWayTheSpecSpellsIt(): void
    {
        $rendered = (new AcroForm(1))->render(false);

        self::assertStringContainsString('/NeedAppearances true', $rendered);
        self::assertStringNotContainsString('NeedsAppearances', $rendered);
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
