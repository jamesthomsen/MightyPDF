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

    public function testUpdateSectionGroupsConsecutiveIdsIntoSubsections(): void
    {
        // An update lists only what changed, so gaps are not merely
        // tolerated here -- they are the point.
        $xref = new Xref();
        $xref->addEntry(12, 1200);
        $xref->addEntry(13, 1300);
        $xref->addEntry(40, 4000);

        self::assertSame(
            "xref\n"
            . "0 1\n0000000000 65535 f \n"
            . "12 2\n0000001200 00000 n \n0000001300 00000 n \n"
            . "40 1\n0000004000 00000 n \n",
            $xref->buildUpdateSection(),
        );
    }

    public function testUpdateSectionSortsRegardlessOfInsertionOrder(): void
    {
        $xref = new Xref();
        $xref->addEntry(9, 900);
        $xref->addEntry(7, 700);
        $xref->addEntry(8, 800);

        self::assertStringContainsString("7 3\n", $xref->buildUpdateSection());
    }

    public function testUpdateSectionRecordsNonZeroGenerations(): void
    {
        $xref = new Xref();
        $xref->addEntry(5, 500, 3);

        self::assertStringContainsString('0000000500 00003 n ', $xref->buildUpdateSection());
    }

    public function testEveryEntryLineIsExactlyTwentyBytes(): void
    {
        // Readers are allowed to seek to "start of table + 20 * n", so an
        // entry one byte short silently misaligns every entry after it.
        $xref = new Xref();
        $xref->addEntry(1, 1234567890, 65535);

        foreach (explode("\n", trim($xref->buildUpdateSection(), "\n")) as $line) {
            if (preg_match('/^\d{10} \d{5} [nf]/', $line) === 1) {
                self::assertSame(19, strlen($line), "entry line without its newline: \"$line\"");
            }
        }
    }
}
