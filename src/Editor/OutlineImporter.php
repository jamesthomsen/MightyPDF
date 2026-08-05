<?php

declare(strict_types=1);

namespace MightyPDF\Editor;

use MightyPDF\Assembler\Destination;
use MightyPDF\Assembler\Dictionary;
use MightyPDF\Assembler\Document;
use MightyPDF\Assembler\OutlineItem;
use MightyPDF\Assembler\Types\PdfArray;
use MightyPDF\Assembler\Types\PdfInteger;
use MightyPDF\Assembler\Types\PdfName;
use MightyPDF\Assembler\Types\PdfReference;
use MightyPDF\Assembler\Types\PdfString;
use MightyPDF\Assembler\Types\PdfValue;

/**
 * Collects the bookmark trees of several source documents into the one
 * outline a merged document has.
 *
 * Each source's top-level items are appended in the order the files were
 * merged, which is the only arrangement that invents nothing: wrapping
 * each file's bookmarks under a heading named after it would be adding
 * structure the documents never had, and interleaving them would be
 * asserting a relationship between chapters that arrived separately.
 *
 * **An item survives if anything under it still points somewhere.** A
 * bookmark whose page was left behind is a line that goes nowhere, and a
 * subtree of those is a table of contents for a document that is not
 * here. So the tree is pruned to the items that resolve, keeping any
 * ancestor needed to reach one -- and an ancestor kept that way loses
 * its own destination and becomes what it already looked like: a
 * heading.
 *
 * Bookmarks are read after their source's pages are imported, so unlike
 * a link's, their destinations resolve immediately -- the map is
 * complete by then. See ImportedAnnotation for the case that is not.
 */
final class OutlineImporter
{
    /** A tree deeper than this is a cycle or a document not worth reading. */
    private const int MAX_DEPTH = 32;

    /** Enough for the largest real table of contents; past it, the file is not describing one. */
    private const int MAX_ITEMS = 20_000;

    private int $read = 0;

    public function __construct(private readonly Document $target)
    {
    }

    /**
     * Appends $source's outline, with its destinations resolved through
     * the pages that were imported from it.
     */
    public function take(PdfEditor $source, ImportedPages $pages): void
    {
        $root = $source->resolveDictionary($source->catalog()->get('Outlines'));

        if ($root === null) {
            return;
        }

        $this->read = 0;
        $items = $this->readItems($source, $root->get('First'), $pages, [], 0);

        foreach ($items as $item) {
            $this->append($item, null);
        }
    }

    /**
     * Reads a run of siblings, deepest first, keeping only what still
     * points somewhere.
     *
     * @param array<int, true> $seen
     * @return list<array{title: string, destination: ?Destination, entries: array<string, PdfValue>, open: bool, children: list<array<string, mixed>>}>
     */
    private function readItems(PdfEditor $source, ?PdfValue $first, ImportedPages $pages, array $seen, int $depth): array
    {
        if ($depth >= self::MAX_DEPTH) {
            return [];
        }

        $items = [];
        $reference = $first;

        while ($reference instanceof PdfReference && $this->read < self::MAX_ITEMS) {
            // A /Next chain that comes back on itself is a document
            // describing an endless outline, which no reader survives
            // either.
            if (isset($seen[$reference->objectId()])) {
                break;
            }

            $seen[$reference->objectId()] = true;
            ++$this->read;

            $node = $source->resolveDictionary($reference);

            if ($node === null) {
                break;
            }

            $children = $this->readItems($source, $node->get('First'), $pages, $seen, $depth + 1);
            $destination = $this->destinationOf($source, $node, $pages);
            $entries = $this->carriedEntries($source, $node);

            if ($destination !== null || $children !== [] || isset($entries['A'])) {
                $items[] = [
                    'title' => self::titleOf($source, $node),
                    'destination' => $destination,
                    'entries' => $entries,
                    // A negative /Count is how a document says an item
                    // was written folded away.
                    'open' => self::countOf($source, $node) >= 0,
                    'children' => $children,
                ];
            }

            $reference = $node->get('Next');
        }

        return $items;
    }

    /**
     * @param array{title: string, destination: ?Destination, entries: array<string, PdfValue>, open: bool, children: list<array<string, mixed>>} $item
     */
    private function append(array $item, ?OutlineItem $parent): void
    {
        $added = $parent === null
            ? $this->target->outline()->add($item['title'], $item['destination'], $item['open'])
            : $parent->add($item['title'], $item['destination'], $item['open']);

        foreach ($item['entries'] as $key => $value) {
            $added->set($key, $value);
        }

        foreach ($item['children'] as $child) {
            /** @var array{title: string, destination: ?Destination, entries: array<string, PdfValue>, open: bool, children: list<array<string, mixed>>} $child */
            $this->append($child, $added);
        }
    }

    /**
     * Where the item points, in the merged document -- or null where
     * that is nowhere: a page left behind, or a destination named
     * through a name tree this does not import (see PageImporter, which
     * drops those on links for the same reason).
     */
    private function destinationOf(PdfEditor $source, Dictionary $node, ImportedPages $pages): ?Destination
    {
        $destination = $source->resolve($node->get('Dest'));

        if ($destination === null) {
            $action = $source->resolveDictionary($node->get('A'));
            $isGoTo = ($action?->get('S') instanceof PdfName) && $action->get('S')->value() === 'GoTo';
            $destination = $isGoTo ? $source->resolve($action->get('D')) : null;
        }

        if (!$destination instanceof PdfArray) {
            return null;
        }

        $items = $destination->items();
        $page = $items[0] ?? null;
        $fit = $items[1] ?? null;

        if (!$page instanceof PdfReference || !$fit instanceof PdfName) {
            return null;
        }

        $imported = $pages->importedId($page->objectId());

        return $imported === null
            ? null
            : Destination::copied($imported, $fit->value(), array_slice($items, 2));
    }

    /**
     * The entries worth carrying that are not the item's structure: how
     * it looks, and a link out of the document if that is what it was.
     *
     * All of them are values rather than references, so they cross
     * without copying anything. A bookmark that runs JavaScript or opens
     * another file is *not* carried: those reach outside the document,
     * and a merge is no place to decide they should still fire.
     *
     * @return array<string, PdfValue>
     */
    private function carriedEntries(PdfEditor $source, Dictionary $node): array
    {
        $entries = [];

        // /C is the item's colour and /F its bold/italic flags. Dropping
        // them would quietly restyle a document's table of contents.
        foreach (['C' => PdfArray::class, 'F' => PdfInteger::class] as $key => $type) {
            $value = $source->resolve($node->get($key));

            if ($value instanceof $type) {
                $entries[$key] = $value;
            }
        }

        $action = $source->resolveDictionary($node->get('A'));
        $isUri = ($action?->get('S') instanceof PdfName) && $action->get('S')->value() === 'URI';
        $uri = $isUri ? $source->resolve($action->get('URI')) : null;

        if ($uri instanceof PdfString) {
            $copy = new Dictionary();
            $copy->set('S', new PdfName('URI'));
            $copy->set('URI', $uri);

            $entries['A'] = $copy;
        }

        return $entries;
    }

    private static function titleOf(PdfEditor $source, Dictionary $node): string
    {
        $title = $source->resolve($node->get('Title'));

        return $title instanceof PdfString ? $title->toUtf8() : '';
    }

    private static function countOf(PdfEditor $source, Dictionary $node): int
    {
        $count = $source->resolve($node->get('Count'));

        return $count instanceof PdfInteger ? $count->value() : 0;
    }
}
