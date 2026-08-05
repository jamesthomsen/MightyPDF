<?php

declare(strict_types=1);

namespace MightyPDF\Assembler;

use MightyPDF\Assembler\Types\PdfInteger;
use MightyPDF\Assembler\Types\PdfName;

/**
 * The document's outline: the tree of bookmarks a reader shows beside
 * the page (ISO 32000-2 §12.3.3).
 *
 * This is the root of the tree rather than an item in it -- it has
 * children and a count and nothing else, no title and nowhere to go.
 *
 * The tree is wired at save time (see Finalizable). Every link between
 * items -- /First, /Last, /Next, /Prev, /Parent, /Count -- describes a
 * relationship that is not yet true while the outline is being built: an
 * item's /Next does not exist until the item after it has been added,
 * and its /Count changes every time anything is added below it. Writing
 * those as they change would mean rewriting most of the tree on every
 * call and getting one of them wrong in every intermediate state.
 */
final class Outline extends Dictionary implements Finalizable
{
    /** @var list<OutlineItem> */
    private array $items = [];

    public function __construct(
        private readonly DocumentContext $document,
        int $objectId,
    ) {
        parent::__construct($objectId);

        $this->set('Type', new PdfName('Outlines'));
    }

    /**
     * Adds a top-level bookmark, returning it so that sections can be
     * added under it.
     *
     * ```php
     * $chapter = $document->outline()->add('Chapter 1', Destination::of($page));
     * $chapter->add('Background', Destination::of($page, top: 500));
     * ```
     */
    public function add(string $title, ?Destination $destination = null, bool $open = true): OutlineItem
    {
        $item = new OutlineItem($this->document, $this->document->allocate(), $title, $destination, $open);
        $this->document->register($item);
        $this->items[] = $item;

        return $item;
    }

    public function isEmpty(): bool
    {
        return $this->items === [];
    }

    /**
     * The root's /Count is the number of rows a reader draws with the
     * panel as it opens: the top-level items, plus everything visible
     * under the open ones. Unlike an item's, it is never negative --
     * there is nothing to collapse the whole outline into.
     */
    public function finalize(): void
    {
        $count = 0;

        foreach ($this->items as $item) {
            ++$count;

            if ($item->isOpen()) {
                $count += $item->visibleDescendants();
            }
        }

        $this->set('Count', new PdfInteger($count));

        OutlineItem::setEnds($this, $this->items);
        OutlineItem::linkChildren($this->items, $this->objectId());
    }
}
