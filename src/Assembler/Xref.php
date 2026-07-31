<?php

declare(strict_types=1);

namespace MightyPDF\Assembler;

/**
 * The classic plain-text cross-reference table (ISO 32000-2 §7.5.4):
 * "xref\n0 N\n0000000000 65535 f \n0000000010 00000 n \n...".
 *
 * A passive data holder: IndirectObjectRegistry is solely responsible for
 * deciding what offset each object gets recorded at (see its doc comment
 * for why that used to be scattered across multiple call sites and buggy);
 * this class only renders whatever it's told, keyed explicitly by object
 * id rather than implicit array position.
 *
 * There are two ways to render, because the two situations have opposite
 * rules about gaps:
 *
 * - build() writes the whole document's table. Object ids are allocated
 *   contiguously from 1 (see IndirectObjectRegistry::allocate()), so a gap
 *   here means an id was allocated and then never registered -- a bug.
 *   It throws rather than silently emitting a malformed table.
 * - buildUpdateSection() writes the table for an incremental update, which
 *   lists only the objects that changed. There gaps are not merely
 *   allowed, they are the entire point, and the ids get grouped into
 *   contiguous subsections.
 */
final class Xref
{
    private const string FREE_LIST_HEAD = "0000000000 65535 f \n";

    /** @var array<int, int> objectId => byte offset */
    private array $entries = [];

    /**
     * objectId => generation, held apart from $entries so that entries()
     * keeps its "offset by object id" shape. Only ever non-zero for an
     * object rewritten from an existing file (see PdfObject::generation()).
     *
     * @var array<int, int>
     */
    private array $generations = [];

    public function addEntry(int $objectId, int $byteOffset, int $generation = 0): void
    {
        $this->entries[$objectId] = $byteOffset;
        $this->generations[$objectId] = $generation;
    }

    /** @return array<int, int> objectId => byte offset */
    public function entries(): array
    {
        return $this->entries;
    }

    /** The highest registered object id, or 0 if none are registered. */
    public function highestObjectId(): int
    {
        return $this->entries === [] ? 0 : max(array_keys($this->entries));
    }

    public function offsetOf(int $objectId): int
    {
        return $this->entries[$objectId] ?? throw new \LogicException("No xref entry for object id $objectId.");
    }

    public function generationOf(int $objectId): int
    {
        return $this->generations[$objectId] ?? 0;
    }

    /**
     * The registered ids, ascending, grouped into runs of consecutive
     * numbers.
     *
     * Both update formats need exactly this grouping -- a classic table
     * writes each run as a "first count" subsection header, a
     * cross-reference stream writes the same pairs into its /Index -- so
     * it is computed here once rather than twice, slightly differently.
     *
     * @return list<non-empty-list<int>>
     */
    public function contiguousRuns(): array
    {
        $ids = array_keys($this->entries);
        sort($ids);

        $runs = [];
        $run = [];

        foreach ($ids as $id) {
            if ($run !== [] && $id !== $run[count($run) - 1] + 1) {
                $runs[] = $run;
                $run = [];
            }

            $run[] = $id;
        }

        if ($run !== []) {
            $runs[] = $run;
        }

        return $runs;
    }

    public function build(): string
    {
        $highest = $this->highestObjectId();

        $out = "xref\n";
        $out .= sprintf("0 %d\n", $highest + 1);
        $out .= self::FREE_LIST_HEAD;

        for ($id = 1; $id <= $highest; ++$id) {
            if (!isset($this->entries[$id])) {
                throw new \LogicException("Xref has a gap at object id $id -- phase 1 requires contiguous object ids starting at 1.");
            }

            $out .= $this->entryLine($id);
        }

        return $out;
    }

    /**
     * The cross-reference section for an incremental update: only the
     * objects this update rewrote, grouped into runs of consecutive ids
     * ("12 2\n<entry>\n<entry>\n"), preceded by the "0 1" free-list head
     * that update sections conventionally repeat.
     *
     * Every offset recorded here is an offset into the *whole* output
     * file, original bytes included. That is what makes an incremental
     * update safe: appending never moves an existing object, so every
     * offset in every earlier section stays valid and this section only
     * has to describe what moved in.
     */
    public function buildUpdateSection(): string
    {
        $out = "xref\n0 1\n" . self::FREE_LIST_HEAD;

        foreach ($this->contiguousRuns() as $run) {
            $out .= $this->subsection($run);
        }

        return $out;
    }

    /** @param non-empty-list<int> $run consecutive object ids */
    private function subsection(array $run): string
    {
        $out = sprintf("%d %d\n", $run[0], count($run));

        foreach ($run as $id) {
            $out .= $this->entryLine($id);
        }

        return $out;
    }

    /**
     * Exactly 20 bytes, including the trailing space before the newline
     * (ISO 32000-2 §7.5.4). The padding is not cosmetic: readers are
     * permitted to seek directly to "start of table + 20 * n", so an
     * entry one byte short silently misaligns every entry after it.
     */
    private function entryLine(int $objectId): string
    {
        return sprintf("%010d %05d n \n", $this->entries[$objectId], $this->generations[$objectId] ?? 0);
    }
}
