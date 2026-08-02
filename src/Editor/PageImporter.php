<?php

declare(strict_types=1);

namespace MightyPDF\Editor;

use MightyPDF\Assembler\Dictionary;
use MightyPDF\Assembler\Document;
use MightyPDF\Assembler\Page;
use MightyPDF\Assembler\PdfObject;
use MightyPDF\Assembler\Stream;
use MightyPDF\Assembler\Types\PdfArray;
use MightyPDF\Assembler\Types\PdfInteger;
use MightyPDF\Assembler\Types\PdfName;
use MightyPDF\Assembler\Types\PdfReal;
use MightyPDF\Assembler\Types\PdfRectangle;
use MightyPDF\Assembler\Types\PdfReference;
use MightyPDF\Assembler\Types\PdfValue;

/**
 * Copies pages from one opened document into a fresh Document being
 * assembled, renumbering every object reached along the way into the
 * target's id space.
 *
 * One instance is meant to be reused for every page pulled from the same
 * source: a font or image shared by several pages of that source is copied
 * once (via $idMap) and referenced by all of them afterwards, rather than
 * re-embedded per page.
 *
 * Only a page's visual content and non-form annotations come across.
 * /Subtype /Widget annotations (form field widgets) are deliberately
 * skipped -- carrying them over correctly would mean merging them into a
 * shared /AcroForm across source files (name collisions, /Parent
 * hierarchies, the "one /AcroForm per catalog" constraint AcroForm::adopt()
 * already has to work around for a single file) which is out of scope here.
 */
final class PageImporter
{
    /** A page tree deeper than this is presumed to be a cycle, not a tree. */
    private const int MAX_TREE_DEPTH = 64;

    /** @var array<int, int> source object id => target object id */
    private array $idMap = [];

    /** @var array<int, PdfObject> target object id => the copied object */
    private array $copied = [];

    public function __construct(
        private readonly PdfEditor $source,
        private readonly Document $target,
    ) {
    }

    /**
     * Every page in the source, in document order, keyed 0, 1, 2, ...
     *
     * Deliberately re-keyed here rather than relying on walk()'s own
     * `yield`s: nested `yield from` does not renumber an inner generator's
     * keys, so two branches of a multi-level source page tree would
     * otherwise both start counting from 0 and collide.
     */
    public function pages(): iterable
    {
        $root = $this->source->resolveDictionary($this->source->catalog()->get('Pages'));

        if ($root === null) {
            return;
        }

        $index = 0;

        foreach ($this->walk($root, [], 0) as $page) {
            yield $index++ => $page;
        }
    }

    /**
     * @param array<int, true> $seen
     * @return iterable<Dictionary>
     */
    private function walk(Dictionary $node, array $seen, int $depth): iterable
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

        $kids = $this->source->resolve($node->get('Kids'));

        if (!$kids instanceof PdfArray) {
            // No /Kids: this is a leaf page, not an intermediate tree node.
            yield $node;

            return;
        }

        foreach ($kids->items() as $kid) {
            $child = $this->source->resolveDictionary($kid);

            if ($child !== null) {
                yield from $this->walk($child, $seen, $depth + 1);
            }
        }
    }

    /** Copies one source page into the target and returns the new page. */
    public function import(Dictionary $sourcePage): Page
    {
        $page = $this->target->newPage($this->resolvedMediaBox($sourcePage));

        $rotate = $this->resolvedRotate($sourcePage);

        if ($rotate !== 0) {
            $page->set('Rotate', new PdfInteger($rotate));
        }

        // Recorded before anything else is copied, so an annotation's /P
        // pointing back at this same page resolves to the new page instead
        // of triggering a redundant (and wrong) copy of it.
        if ($sourcePage->hasObjectId()) {
            $this->idMap[$sourcePage->objectId()] = $page->objectId();
            $this->copied[$page->objectId()] = $page;
        }

        $this->importResources($sourcePage, $page);

        foreach ($this->contentStreamReferences($sourcePage) as $reference) {
            $stream = $this->copyObject($reference->objectId());

            if ($stream instanceof Stream) {
                $page->addContentStream($stream);
            }
        }

        foreach ($this->nonWidgetAnnotationReferences($sourcePage) as $reference) {
            $page->addAnnotation($this->copyObject($reference->objectId())->objectId());
        }

        return $page;
    }

    private function importResources(Dictionary $sourcePage, Page $page): void
    {
        $resourcesValue = $this->inherited($sourcePage, 'Resources');

        if ($resourcesValue === null) {
            return;
        }

        $copiedResources = $this->asCopiedDictionary($this->copyValue($resourcesValue));

        foreach ($copiedResources?->entries() ?? [] as $key => $value) {
            // Merged onto the page's own (still-empty) resources dictionary
            // rather than replacing /Resources outright, so Page::resources()
            // keeps pointing at whatever backs /Resources -- needed if the
            // caller draws more onto this page afterwards.
            $page->resources()->set((string) $key, $value);
        }
    }

    /**
     * copyValue() on an indirect /Resources returns a PdfReference to the
     * already-copied object, not the Dictionary itself -- this resolves
     * either that or a directly-copied inline dictionary back to the real
     * Dictionary instance so its entries can be read.
     */
    private function asCopiedDictionary(PdfValue $value): ?Dictionary
    {
        if ($value instanceof PdfReference) {
            $object = $this->copied[$value->objectId()] ?? null;

            return $object instanceof Dictionary ? $object : null;
        }

        return $value instanceof Dictionary ? $value : null;
    }

    /** @return list<PdfReference> */
    private function contentStreamReferences(Dictionary $page): array
    {
        $raw = $page->get('Contents');
        $resolved = $this->source->resolve($raw);

        if ($resolved instanceof PdfArray) {
            return array_values(array_filter(
                $resolved->items(),
                static fn (PdfValue $item): bool => $item instanceof PdfReference,
            ));
        }

        if ($raw instanceof PdfReference && $resolved instanceof Stream) {
            return [$raw];
        }

        return [];
    }

    /** @return list<PdfReference> */
    private function nonWidgetAnnotationReferences(Dictionary $page): array
    {
        $annots = $this->source->resolve($page->get('Annots'));

        if (!$annots instanceof PdfArray) {
            return [];
        }

        $references = [];

        foreach ($annots->items() as $item) {
            if (!$item instanceof PdfReference) {
                continue;
            }

            $subtype = $this->source->resolveDictionary($item)?->get('Subtype');

            if ($subtype instanceof PdfName && $subtype->value() === 'Widget') {
                continue;
            }

            $references[] = $item;
        }

        return $references;
    }

    /**
     * Copies the indirect object $oldObjectId (a Dictionary or a Stream)
     * into the target, renumbered, caching the result so a second reference
     * to it returns the same copy instead of duplicating it.
     */
    private function copyObject(int $oldObjectId): PdfObject
    {
        if (isset($this->idMap[$oldObjectId])) {
            return $this->copied[$this->idMap[$oldObjectId]];
        }

        $resolved = $this->source->get($oldObjectId);

        if (!$resolved instanceof Dictionary) {
            throw new \RuntimeException(
                "Cannot import object $oldObjectId: expected a dictionary or stream, found something else.",
            );
        }

        $newId = $this->target->allocate();
        $this->idMap[$oldObjectId] = $newId;

        // Streams are copied with their already-encoded bytes verbatim
        // (compress: false) rather than decoded and recompressed -- this
        // preserves filters this library cannot itself produce, e.g.
        // /DCTDecode for a JPEG XObject.
        $copy = $resolved instanceof Stream
            ? new Stream($newId, $resolved->rawBytes(), compress: false)
            : new Dictionary($newId);

        $this->copied[$newId] = $copy;

        foreach ($resolved->entries() as $key => $value) {
            if ($key === 'Length') {
                // Recomputed by Stream::content() from the copied bytes.
                continue;
            }

            $copy->set((string) $key, $this->copyValue($value));
        }

        $this->target->register($copy);

        return $copy;
    }

    private function copyValue(PdfValue $value): PdfValue
    {
        return match (true) {
            $value instanceof PdfReference => new PdfReference($this->copyObject($value->objectId())->objectId()),
            $value instanceof PdfArray => new PdfArray(...array_map(
                fn (PdfValue $item): PdfValue => $this->copyValue($item),
                $value->items(),
            )),
            // A bare Dictionary here is always inline (a Stream is always
            // indirect in practice, so it is only ever reached through the
            // PdfReference branch above, via copyObject()).
            $value instanceof Dictionary => $this->copyInlineDictionary($value),
            default => $value,
        };
    }

    private function copyInlineDictionary(Dictionary $dictionary): Dictionary
    {
        $copy = new Dictionary();

        foreach ($dictionary->entries() as $key => $value) {
            $copy->set((string) $key, $this->copyValue($value));
        }

        return $copy;
    }

    private function resolvedMediaBox(Dictionary $page): ?PdfRectangle
    {
        $box = $this->numbers($this->inherited($page, 'MediaBox'));

        if (count($box) < 4) {
            // Let Document::newPage() fall back to its own default (US
            // Letter) rather than asserting one here.
            return null;
        }

        return new PdfRectangle(
            min($box[0], $box[2]),
            min($box[1], $box[3]),
            max($box[0], $box[2]),
            max($box[1], $box[3]),
        );
    }

    private function resolvedRotate(Dictionary $page): int
    {
        $rotate = $this->source->resolve($this->inherited($page, 'Rotate'));

        return $rotate instanceof PdfInteger ? (($rotate->value() % 360) + 360) % 360 : 0;
    }

    /** @return list<float> */
    private function numbers(?PdfValue $value): array
    {
        $value = $this->source->resolve($value);

        if (!$value instanceof PdfArray) {
            return [];
        }

        $out = [];

        foreach ($value->items() as $item) {
            $item = $this->source->resolve($item);

            $out[] = match (true) {
                $item instanceof PdfInteger => (float) $item->value(),
                $item instanceof PdfReal => $item->value(),
                default => 0.0,
            };
        }

        return $out;
    }

    /**
     * A page attribute, looked up on the page and then up the /Parent
     * chain -- /Resources, /MediaBox, /CropBox and /Rotate are all
     * inheritable (ISO 32000-2 §7.7.3.4), and the target won't share the
     * source's page-tree ancestors, so anything inherited has to be baked
     * onto the copied page directly.
     */
    private function inherited(Dictionary $page, string $key): ?PdfValue
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

            $parent = $this->source->resolveDictionary($node->get('Parent'));

            if ($parent === null) {
                return null;
            }

            $node = $parent;
        }

        return null;
    }
}
