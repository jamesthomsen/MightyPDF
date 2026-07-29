<?php

declare(strict_types=1);

namespace MightyPDF\Tests\Assembler;

use MightyPDF\Assembler\Xref;
use PHPUnit\Framework\TestCase;

final class XrefTest extends TestCase
{
    public function testEmptyXrefHasOnlyTheFreeListHead(): void
    {
        $xref = new Xref();

        self::assertSame("xref\n0 1\n0000000000 65535 f \n", $xref->build());
    }

    public function testHighestObjectIdIsZeroWhenEmpty(): void
    {
        self::assertSame(0, (new Xref())->highestObjectId());
    }

    public function testRendersEntriesRegardlessOfInsertionOrder(): void
    {
        $xref = new Xref();
        $xref->addEntry(3, 300);
        $xref->addEntry(1, 100);
        $xref->addEntry(2, 200);

        self::assertSame(
            "xref\n0 4\n0000000000 65535 f \n0000000100 00000 n \n0000000200 00000 n \n0000000300 00000 n \n",
            $xref->build(),
        );
    }

    public function testSizeLineCountsTheFreeListHead(): void
    {
        // This is the exact quantity the 2012 code got wrong for /Size
        // (in Trailer, not here) -- confirm the xref table's own "0 N"
        // header always includes the free-list head in N.
        $xref = new Xref();
        $xref->addEntry(1, 10);

        self::assertStringContainsString("0 2\n", $xref->build());
        self::assertSame(1, $xref->highestObjectId());
    }

    public function testThrowsOnGapInObjectIds(): void
    {
        $xref = new Xref();
        $xref->addEntry(1, 10);
        $xref->addEntry(3, 30); // no entry for 2

        $this->expectException(\LogicException::class);
        $xref->build();
    }

    public function testEntriesAccessorReturnsWhatWasStored(): void
    {
        $xref = new Xref();
        $xref->addEntry(5, 500);

        self::assertSame([5 => 500], $xref->entries());
    }
}
