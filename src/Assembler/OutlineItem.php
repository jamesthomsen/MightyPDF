<?php

declare(strict_types=1);

namespace MightyPDF\Assembler;

use MightyPDF\Assembler\Types\PdfInteger;
use MightyPDF\Assembler\Types\PdfReference;
use MightyPDF\Assembler\Types\PdfString;

/**
 * One entry in the document's outline -- a bookmark, in every reader's
 * own words (ISO 32000-2 §12.3.3).
 *
 * An item is a node of a doubly-linked tree: /First and /Last for its
 * children, /Next and /Prev for its siblings, /Parent upwards. None of
 * that is set as items are added, because none of it is known then -- an
 * item's /Next does not exist until the item after it does. Outline
 * finalizes the whole tree at once instead; see Outline::finalize().
 */
final class OutlineItem extends Dictionary
{
    /** @var list<self> */
    private array $children = [];

    /**
     * @param bool $open whether this item's children are shown when the
     *        document is opened, which is also what decides the sign of
     *        its /Count
     */
    public function __construct(
        private readonly DocumentContext $document,
        int $objectId,
        string $title,
        ?Destination $destination,
        private readonly bool $open,
    ) {
        parent::__construct($objectId);

        // A title is a text string: an outline is read by a person, and
        // the languages people read in do not fit in Latin-1.
        $this->set('Title', PdfString::text($title));

        if ($destination !== null) {
            $this->set('Dest', $destination->toArray());
        }
    }

    /**
     * Adds a child of this item -- a section under a chapter.
     *
     * A destination is optional: an item with none is a heading that
     * groups the items under it and goes nowhere itself, which is what a
     * reader does when a document's own structure has no page to point
     * at.
     */
    public function add(string $title, ?Destination $destination = null, bool $open = true): self
    {
        $child = new self($this->document, $this->document->allocate(), $title, $destination, $open);
        $this->document->register($child);
        $this->children[] = $child;

        return $child;
    }

    public function isOpen(): bool
    {
        return $this->open;
    }

    /** @return list<self> */
    public function children(): array
    {
        return $this->children;
    }

    /**
     * How many items a reader shows under this one.
     *
     * Only the open ones, and all the way down: an open item inside an
     * open item is visible, and its own children are counted too, while a
     * closed item is one line that hides everything below it.
     */
    public function visibleDescendants(): int
    {
        $count = 0;

        foreach ($this->children as $child) {
            ++$count;

            if ($child->isOpen()) {
                $count += $child->visibleDescendants();
            }
        }

        return $count;
    }

    /**
     * Writes this item's place in the tree, and its children's.
     *
     * /Count is the entry worth getting right: absent for a leaf,
     * positive for an open item and negative for a closed one, and its
     * size is how many rows the item is responsible for. A reader uses it
     * to lay the panel out before it has read the items themselves, so a
     * wrong one shows a bookmark tree with gaps or overlapping rows.
     */
    public function link(?int $parentObjectId): void
    {
        $this->set('Parent', $parentObjectId === null ? null : new PdfReference($parentObjectId));

        $count = $this->visibleDescendants();
        $this->set('Count', $this->children === [] ? null : new PdfInteger($this->open ? $count : -$count));

        self::setEnds($this, $this->children);
        self::linkChildren($this->children, $this->objectId());
    }

    /**
     * Wires a run of siblings to each other and to their parent, then
     * lets each wire its own children.
     *
     * @param list<self> $items
     */
    public static function linkChildren(array $items, ?int $parentObjectId): void
    {
        foreach ($items as $index => $item) {
            $previous = $items[$index - 1] ?? null;
            $next = $items[$index + 1] ?? null;

            $item->set('Prev', $previous === null ? null : new PdfReference($previous->objectId()));
            $item->set('Next', $next === null ? null : new PdfReference($next->objectId()));

            $item->link($parentObjectId);
        }
    }

    /**
     * @param list<self> $items
     */
    public static function setEnds(Dictionary $parent, array $items): void
    {
        $first = $items[0] ?? null;
        $last = $items === [] ? null : $items[count($items) - 1];

        $parent->set('First', $first === null ? null : new PdfReference($first->objectId()));
        $parent->set('Last', $last === null ? null : new PdfReference($last->objectId()));
    }
}
