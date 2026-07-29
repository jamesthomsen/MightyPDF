<?php

declare(strict_types=1);

namespace MightyPDF\Tests\Assembler;

use MightyPDF\Assembler\Catalog;
use PHPUnit\Framework\TestCase;

final class CatalogTest extends TestCase
{
    public function testDeclaresCatalogType(): void
    {
        $catalog = new Catalog(1);
        self::assertStringContainsString('/Type /Catalog', $catalog->render(false));
    }

    public function testSetPagesAddsAReferenceToThePageTree(): void
    {
        $catalog = new Catalog(1);
        $catalog->setPages(2);

        self::assertSame('<< /Type /Catalog /Pages 2 0 R >>', $catalog->render(false));
    }
}
