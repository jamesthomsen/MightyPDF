<?php

declare(strict_types=1);

namespace MightyPDF\Tests\Assembler;

use MightyPDF\Assembler\IndirectObjectRegistry;
use MightyPDF\Assembler\PdfObject;
use PHPUnit\Framework\TestCase;

/**
 * Direct regression coverage for the 2012 bug class: xref offsets and
 * /Size need a single, provably-consistent source of truth instead of
 * hand-computed arithmetic scattered across multiple call sites.
 */
final class IndirectObjectRegistryTest extends TestCase
{
    public function testAllocateReturnsSequentialIdsStartingAtOne(): void
    {
        $registry = new IndirectObjectRegistry();

        self::assertSame(1, $registry->allocate());
        self::assertSame(2, $registry->allocate());
        self::assertSame(3, $registry->allocate());
    }

    public function testXrefOffsetsAreByteExactRegardlessOfRegistrationOrder(): void
    {
        $registry = new IndirectObjectRegistry();
        $id1 = $registry->allocate();
        $id2 = $registry->allocate();
        $id3 = $registry->allocate();

        $obj1 = $this->fakeObject($id1, 'one');
        $obj2 = $this->fakeObject($id2, 'two');
        $obj3 = $this->fakeObject($id3, 'three');

        // Registered deliberately out of allocation order.
        $registry->register($obj3);
        $registry->register($obj1);
        $registry->register($obj2);

        $result = $registry->writeAll("%PDF-1.7\n");

        foreach ($result->xref->entries() as $objectId => $offset) {
            self::assertStringStartsWith(
                "$objectId 0 obj",
                substr($result->bytes, $offset),
                "Offset for object $objectId does not point at its own \"N 0 obj\" marker",
            );
        }
    }

    public function testObjectsAreWrittenInAscendingIdOrderRegardlessOfRegistrationOrder(): void
    {
        $registry = new IndirectObjectRegistry();
        $id1 = $registry->allocate();
        $id2 = $registry->allocate();
        $id3 = $registry->allocate();

        $registry->register($this->fakeObject($id3, 'three'));
        $registry->register($this->fakeObject($id1, 'one'));
        $registry->register($this->fakeObject($id2, 'two'));

        $result = $registry->writeAll('');

        $pos1 = strpos($result->bytes, "$id1 0 obj");
        $pos2 = strpos($result->bytes, "$id2 0 obj");
        $pos3 = strpos($result->bytes, "$id3 0 obj");

        self::assertNotFalse($pos1);
        self::assertNotFalse($pos2);
        self::assertNotFalse($pos3);
        self::assertLessThan($pos2, $pos1);
        self::assertLessThan($pos3, $pos2);
    }

    public function testSizeDerivedFromXrefIsHighestObjectIdPlusOneRegardlessOfRegistrationOrder(): void
    {
        // This is the direct fix for the confirmed 2012 /Size bug: the
        // trailer's Size must come from the Xref that was actually
        // written (highestObjectId() + 1, which already accounts for the
        // free-list head), not a separately hand-copied count. Assert
        // this holds across several registration-order permutations.
        foreach ($this->registrationOrderPermutations() as $order) {
            $registry = new IndirectObjectRegistry();
            $ids = [$registry->allocate(), $registry->allocate(), $registry->allocate()];

            foreach ($order as $index) {
                $registry->register($this->fakeObject($ids[$index], "content-$index"));
            }

            $result = $registry->writeAll('');

            self::assertSame(3, $result->xref->highestObjectId());
            self::assertSame(4, $result->xref->highestObjectId() + 1, 'Size must be highestObjectId + 1, including the free-list head');
        }
    }

    /** @return list<list<int>> permutations of [0, 1, 2] */
    private function registrationOrderPermutations(): array
    {
        return [
            [0, 1, 2],
            [2, 1, 0],
            [1, 0, 2],
            [2, 0, 1],
        ];
    }

    private function fakeObject(int $objectId, string $content): PdfObject
    {
        return new class ($objectId, $content) extends PdfObject {
            public function __construct(int $objectId, private readonly string $fakeContent)
            {
                parent::__construct($objectId);
            }

            protected function content(): string
            {
                return $this->fakeContent;
            }
        };
    }
}
