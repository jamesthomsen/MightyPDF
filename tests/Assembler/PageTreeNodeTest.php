<?php

declare(strict_types=1);

namespace MightyPDF\Tests\Assembler;

use MightyPDF\Assembler\PageTreeNode;
use PHPUnit\Framework\TestCase;

final class PageTreeNodeTest extends TestCase
{
    public function testStartsWithEmptyKidsAndZeroCount(): void
    {
        $tree = new PageTreeNode(1);

        self::assertSame('<< /Type /Pages /Kids [] /Count 0 >>', $tree->render(false));
    }

    public function testAddKidUpdatesKidsAndCountTogether(): void
    {
        $tree = new PageTreeNode(1);
        $tree->addKid(2);
        $tree->addKid(3);

        self::assertSame('<< /Type /Pages /Kids [2 0 R 3 0 R] /Count 2 >>', $tree->render(false));
    }
}
