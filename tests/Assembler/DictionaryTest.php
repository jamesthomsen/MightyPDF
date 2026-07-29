<?php

declare(strict_types=1);

namespace MightyPDF\Tests\Assembler;

use MightyPDF\Assembler\Dictionary;
use MightyPDF\Assembler\Types\PdfBoolean;
use MightyPDF\Assembler\Types\PdfName;
use MightyPDF\Assembler\Types\PdfNull;
use MightyPDF\Assembler\Types\PdfReference;
use PHPUnit\Framework\TestCase;

final class DictionaryTest extends TestCase
{
    public function testFormatsEmptyDictionary(): void
    {
        self::assertSame('<<>>', (new Dictionary(1))->render(false));
    }

    public function testFormatsEntriesInInsertionOrder(): void
    {
        $dict = new Dictionary(1);
        $dict->set('Type', new PdfName('Catalog'));
        $dict->set('Pages', new PdfReference(2));

        self::assertSame('<< /Type /Catalog /Pages 2 0 R >>', $dict->render(false));
    }

    public function testSettingNullRemovesEntry(): void
    {
        $dict = new Dictionary(1);
        $dict->set('Type', new PdfName('Catalog'));
        $dict->set('Type', null);

        self::assertSame('<<>>', $dict->render(false));
    }

    public function testExplicitPdfNullIsDistinctFromRemovingEntry(): void
    {
        $dict = new Dictionary(1);
        $dict->set('Foo', new PdfNull());

        self::assertSame('<< /Foo null >>', $dict->render(false));
    }

    public function testExplicitFalseIsWritten(): void
    {
        // The 2012 model had no way to emit an explicit PDF "false" --
        // only entry omission. Confirm PdfBoolean(false) actually appears.
        $dict = new Dictionary(1);
        $dict->set('NeedsAppearances', new PdfBoolean(false));

        self::assertSame('<< /NeedsAppearances false >>', $dict->render(false));
    }

    public function testRendersAsIndirectObjectWhenRequested(): void
    {
        $dict = new Dictionary(3);
        $dict->set('Type', new PdfName('Pages'));

        self::assertSame("3 0 obj\n<< /Type /Pages >>\nendobj\n", $dict->render(true));
    }

    public function testGetReturnsSetValue(): void
    {
        $dict = new Dictionary(1);
        $ref = new PdfReference(2);
        $dict->set('Pages', $ref);

        self::assertSame($ref, $dict->get('Pages'));
        self::assertNull($dict->get('Missing'));
    }

    public function testCanNestAnIdlessDictionaryAsAnInlineValue(): void
    {
        // No object number of its own -- e.g. a Page's /Resources
        // sub-dictionary, which is never a separate indirect object.
        $font = new Dictionary();
        $font->set('F1', new PdfReference(5));

        $resources = new Dictionary();
        $resources->set('Font', $font);

        $page = new Dictionary(1);
        $page->set('Resources', $resources);

        self::assertSame('<< /Resources << /Font << /F1 5 0 R >> >> >>', $page->render(false));
    }
}
