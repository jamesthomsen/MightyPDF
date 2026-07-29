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
 * Phase 1 always registers object ids contiguously starting at 1 (see
 * IndirectObjectRegistry::allocate()), so this renders a single
 * subsection covering 0..max(objectId) and throws if a gap is found
 * instead of silently emitting a malformed table. Representing freed
 * objects/gaps from an edited source file (real free-list chaining) is a
 * phase-2 concern, not attempted here.
 */
final class Xref
{
    /** @var array<int, int> objectId => byte offset */
    private array $entries = [];

    public function addEntry(int $objectId, int $byteOffset): void
    {
        $this->entries[$objectId] = $byteOffset;
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

    public function build(): string
    {
        $highest = $this->highestObjectId();

        $out = "xref\n";
        $out .= sprintf("0 %d\n", $highest + 1);
        $out .= "0000000000 65535 f \n";

        for ($id = 1; $id <= $highest; ++$id) {
            if (!isset($this->entries[$id])) {
                throw new \LogicException("Xref has a gap at object id $id -- phase 1 requires contiguous object ids starting at 1.");
            }

            $out .= sprintf("%010d 00000 n \n", $this->entries[$id]);
        }

        return $out;
    }
}
