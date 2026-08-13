<?php

declare(strict_types=1);

namespace MightyPDF\Editor;

use MightyPDF\Assembler\Dictionary;
use MightyPDF\Assembler\Types\PdfArray;
use MightyPDF\Assembler\Types\PdfInteger;
use MightyPDF\Assembler\Types\PdfReal;
use MightyPDF\Assembler\Types\PdfRectangle;
use MightyPDF\Assembler\Types\PdfValue;

/**
 * The pages of an opened document, in the order a reader displays them,
 * and the attributes each one effectively has.
 *
 * A PDF's pages are a tree, not a list (ISO 32000-2 §7.7.3), and nothing
 * requires that tree to be shallow, balanced, or even acyclic -- a
 * hand-edited file can perfectly well have a /Kids entry pointing back at
 * an ancestor. Every caller that wants "page 3" therefore has to walk it,
 * guard the walk, and flatten the result, and every caller that wants a
 * page's size or resources has to look up the /Parent chain because
 * /Resources, /MediaBox, /CropBox and /Rotate are all inheritable and
 * plenty of files put them only on the root.
 *
 * That is two pieces of fiddly traversal that four separate features need,
 * so they live here once rather than being written out again per caller
 * with slightly different cycle guards.
 */
final class PageTree
{
    /** A page tree deeper than this is presumed to be a cycle, not a tree. */
    private const int MAX_TREE_DEPTH = 64;

    /** @var list<Dictionary>|null flattened on first use, then reused */
    private ?array $pages = null;

    public function __construct(private readonly PdfEditor $editor)
    {
    }

    /**
     * Every page, in document order.
     *
     * @return list<Dictionary>
     */
    public function pages(): array
    {
        if ($this->pages !== null) {
            return $this->pages;
        }

        $root = $this->editor->resolveDictionary($this->editor->catalog()->get('Pages'));

        if ($root === null) {
            return $this->pages = [];
        }

        $pages = [];
        $seen = [];

        $this->walk($root, $seen, $pages, 0);

        return $this->pages = $pages;
    }

    public function count(): int
    {
        return count($this->pages());
    }

    /**
     * One page by its zero-based position, or null past the end.
     *
     * Zero-based deliberately, matching every other index in this library,
     * even though a reader's own page numbering starts at 1 -- see
     * PageSelection, which takes the same numbers and says so loudly.
     */
    public function page(int $index): ?Dictionary
    {
        return $this->pages()[$index] ?? null;
    }

    /**
     * The position of a page in the document, by object id, or null if it
     * is not in the tree. What a destination pointing at a page needs in
     * order to survive that page being moved.
     */
    public function indexOf(Dictionary $page): ?int
    {
        if (!$page->hasObjectId()) {
            return null;
        }

        foreach ($this->pages() as $index => $candidate) {
            if ($candidate->hasObjectId() && $candidate->objectId() === $page->objectId()) {
                return $index;
            }
        }

        return null;
    }

    /**
     * A page attribute, looked up on the page and then up the /Parent
     * chain -- /Resources, /MediaBox, /CropBox and /Rotate are all
     * inheritable (§7.7.3.4), and a page that declares none of them is not
     * malformed, it is normal.
     */
    public function inherited(Dictionary $page, string $key): ?PdfValue
    {
        $node = $page;
        $seen = [];

        for ($depth = 0; $depth < self::MAX_TREE_DEPTH; ++$depth) {
            $value = $node->get($key);

            if ($value !== null) {
                return $value;
            }

            if ($node->hasObjectId()) {
                if (isset($seen[$node->objectId()])) {
                    return null;
                }

                $seen[$node->objectId()] = true;
            }

            $parent = $this->editor->resolveDictionary($node->get('Parent'));

            if ($parent === null) {
                return null;
            }

            $node = $parent;
        }

        return null;
    }

    /**
     * A page's size, resolved up the tree.
     *
     * @param PdfRectangle $fallback what to answer for a page with no
     *        /MediaBox anywhere above it. The spec requires one, but a file
     *        missing it is not worth refusing to work with, and every
     *        caller here would otherwise invent the same US Letter default.
     */
    public function mediaBox(
        Dictionary $page,
        PdfRectangle $fallback = new PdfRectangle(0, 0, 612, 792),
    ): PdfRectangle {
        $box = $this->numbers($this->inherited($page, 'MediaBox'));

        if (count($box) < 4) {
            return $fallback;
        }

        return (new PdfRectangle($box[0], $box[1], $box[2], $box[3]))->normalized();
    }

    /** A page's /Rotate in degrees clockwise, normalised to 0, 90, 180 or 270. */
    public function rotation(Dictionary $page): int
    {
        $rotate = $this->editor->resolve($this->inherited($page, 'Rotate'));

        if (!$rotate instanceof PdfInteger) {
            return 0;
        }

        // Negative and out-of-range values both occur, and §7.7.3.3's
        // "multiple of 90" is a requirement writers do not always meet;
        // rounding to a quarter turn beats propagating a rotation no
        // reader would honour.
        return ((intdiv($rotate->value(), 90) * 90) % 360 + 360) % 360;
    }

    /**
     * The numbers in an array-valued entry, references followed.
     *
     * @return list<float>
     */
    public function numbers(?PdfValue $value): array
    {
        $value = $this->editor->resolve($value);

        if (!$value instanceof PdfArray) {
            return [];
        }

        $out = [];

        foreach ($value->items() as $item) {
            $item = $this->editor->resolve($item);

            $out[] = match (true) {
                $item instanceof PdfInteger => (float) $item->value(),
                $item instanceof PdfReal => $item->value(),
                default => 0.0,
            };
        }

        return $out;
    }

    /**
     * The set of visited nodes is shared across the whole walk rather than
     * copied down each branch, and that is the difference between this
     * terminating and this not.
     *
     * A per-branch copy guards only against a node being its own ancestor
     * -- a cycle. It does nothing about a node reached twice by two
     * different paths, which is not a cycle and which §7.7.3.2 does not
     * permit either, a page having exactly one /Parent. Each such node is
     * then walked once per path that reaches it, so a tree where every
     * level lists the same child twice costs 2^depth: at MAX_TREE_DEPTH
     * that is 2^63 from a file of a few kilobytes, which is a hostile
     * document's cheapest way to take the process with it.
     *
     * Shared, a node is walked once whatever reaches it, so the walk costs
     * what the document has in it. The visible difference for a malformed
     * file is that a page listed twice is returned once, which is what a
     * reader shows.
     *
     * @param array<int, true> $seen
     * @param list<Dictionary> $pages
     */
    private function walk(Dictionary $node, array &$seen, array &$pages, int $depth): void
    {
        if ($depth >= self::MAX_TREE_DEPTH) {
            return;
        }

        if ($node->hasObjectId()) {
            if (isset($seen[$node->objectId()])) {
                return;
            }

            $seen[$node->objectId()] = true;
        }

        $kids = $this->editor->resolve($node->get('Kids'));

        if (!$kids instanceof PdfArray) {
            // No /Kids: a leaf page, not an intermediate node.
            $pages[] = $node;

            return;
        }

        foreach ($kids->items() as $kid) {
            $child = $this->editor->resolveDictionary($kid);

            if ($child !== null) {
                $this->walk($child, $seen, $pages, $depth + 1);
            }
        }
    }
}
