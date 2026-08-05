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
use MightyPDF\Assembler\Types\PdfString;
use MightyPDF\Assembler\Types\PdfValue;
use MightyPDF\Editor\Form\FormImporter;

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
 * A page's visual content, its annotations, and any form fields those
 * annotations belong to all come across. Fields are the awkward part,
 * and the awkwardness is not in copying them: a field is a document-wide
 * thing reached through a page, so importing one page of a form means
 * rebuilding just enough of that form to hold it. See importWidget() for
 * the pruning that needs, and FormImporter for what has to be settled
 * across sources rather than within one.
 *
 * Pass a FormImporter to carry fields; without one, widget annotations
 * are skipped and a merged page keeps its appearance but not its form.
 *
 * Links come across too, and where they point is settled at save time
 * rather than as they are copied -- a page linking forwards names a page
 * that has not been imported yet. See ImportedAnnotation.
 */
final class PageImporter
{
    /** A page tree deeper than this is presumed to be a cycle, not a tree. */
    private const int MAX_TREE_DEPTH = 64;

    /** @var array<int, int> source object id => target object id */
    private array $idMap = [];

    /**
     * Which source pages were imported, for the links that point at
     * them -- see ImportedAnnotation for why that cannot be answered
     * while a link is being copied.
     */
    private readonly ImportedPages $importedPages;

    /** @var array<int, PdfObject> target object id => the copied object */
    private array $copied = [];

    /** @var array<int, Dictionary> source field id => the field node copied for it */
    private array $fields = [];

    /** @var array<int, list<int>> source field id => target ids of the children kept under it */
    private array $fieldKids = [];

    /** @var array<string, string>|null source /DR resource renames, resolved on first field */
    private ?array $resourceRenames = null;

    public function __construct(
        private readonly PdfEditor $source,
        private readonly Document $target,
        private readonly ?FormImporter $form = null,
    ) {
        $this->importedPages = new ImportedPages();
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

    /**
     * Which of this source's pages were imported, and what they were
     * renumbered to -- what anything pointing at a page needs to know
     * once the pages are in. See OutlineImporter.
     */
    public function importedPages(): ImportedPages
    {
        return $this->importedPages;
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
            $this->importedPages->record($sourcePage->objectId(), $page->objectId());
        }

        $this->importResources($sourcePage, $page);

        foreach ($this->contentStreamReferences($sourcePage) as $reference) {
            $stream = $this->copyObject($reference->objectId());

            if ($stream instanceof Stream) {
                $page->addContentStream($stream);
            }
        }

        foreach ($this->annotationReferences($sourcePage) as $reference) {
            $annotation = $this->source->resolveDictionary($reference);

            if (!FormImporter::isWidget($annotation)) {
                $page->addAnnotation($this->copyAnnotation($reference->objectId())->objectId());

                continue;
            }

            if ($this->form !== null) {
                $page->addAnnotation($this->importWidget($reference->objectId(), $page)->objectId());
            }
        }

        return $page;
    }

    /**
     * Copies a form field's widget and rebuilds the ancestry it hangs
     * from, keeping only what was imported.
     *
     * The pruning is the whole difficulty. A field's /Kids list its
     * widgets across the *whole* source document, so copying a field
     * wholesale from one imported page drags in widgets belonging to
     * pages that were never imported -- and through their /P, the pages
     * themselves. What comes across instead is built from the bottom up:
     * each widget that was imported, then the fields above it, with
     * /Kids rebuilt from the children that actually arrived.
     *
     * A field whose widget is merged into it (the common one-widget
     * case) is its own root, and is handed to the form directly.
     */
    private function importWidget(int $widgetId, Page $page): Dictionary
    {
        $widget = $this->copyFieldObject($widgetId, $page);
        $sourceWidget = $this->source->resolveDictionary(new PdfReference($widgetId));
        $parent = $sourceWidget?->get('Parent');

        if (!$parent instanceof PdfReference) {
            $this->form?->take($widget);

            return $widget;
        }

        $child = $widget;
        $childSourceId = $widgetId;

        for ($depth = 0; $depth < self::MAX_TREE_DEPTH; ++$depth) {
            $parentSourceId = $parent->objectId();
            $node = $this->copyFieldObject($parentSourceId, null);

            $child->set('Parent', new PdfReference($node->objectId()));

            if (!in_array($child->objectId(), $this->fieldKids[$parentSourceId] ?? [], true)) {
                $this->fieldKids[$parentSourceId][] = $child->objectId();
                $this->syncKids($parentSourceId);
            }

            $grandparent = $this->source->resolveDictionary($parent)?->get('Parent');

            if (!$grandparent instanceof PdfReference) {
                // Reached the root. Handing it over twice is harmless
                // only if the form is told once, so this happens on the
                // hop that created it.
                if ($childSourceId !== $parentSourceId && count($this->fieldKids[$parentSourceId]) === 1) {
                    $this->form?->take($node);
                }

                return $widget;
            }

            $child = $node;
            $childSourceId = $parentSourceId;
            $parent = $grandparent;
        }

        return $widget;
    }

    /**
     * Copies a field or widget dictionary, leaving out the two entries
     * that describe where it sits.
     *
     * /Parent and /Kids are rebuilt by importWidget() from what was
     * imported; copying them would follow the source's own structure
     * straight back out of this page. /P is set to the target page
     * instead of copied for the same reason: the source's page object is
     * not the one this widget now lives on.
     */
    private function copyFieldObject(int $sourceId, ?Page $page): Dictionary
    {
        if (isset($this->fields[$sourceId])) {
            return $this->fields[$sourceId];
        }

        $source = $this->source->resolveDictionary(new PdfReference($sourceId))
            ?? throw new \RuntimeException("Cannot import form field $sourceId: it is not a dictionary.");

        $copy = new Dictionary($this->target->allocate());
        $this->fields[$sourceId] = $copy;
        $this->idMap[$sourceId] = $copy->objectId();
        $this->copied[$copy->objectId()] = $copy;

        foreach ($source->entries() as $key => $value) {
            if (in_array((string) $key, ['Parent', 'Kids', 'P'], true)) {
                continue;
            }

            $copy->set((string) $key, $this->copyValue($value));
        }

        if ($page !== null) {
            $copy->set('P', new PdfReference($page->objectId()));
        }

        $this->rewriteDefaultAppearance($copy);
        $this->target->register($copy);

        return $copy;
    }

    /**
     * Points a copied field's /DA at whatever its font ended up called
     * in the merged form's /DR -- see FormImporter::takeDefaultResources().
     */
    private function rewriteDefaultAppearance(Dictionary $field): void
    {
        $da = $field->get('DA');

        if (!$da instanceof PdfString || $this->form === null) {
            return;
        }

        $this->resourceRenames ??= $this->takeDefaultResources();

        if ($this->resourceRenames === []) {
            return;
        }

        $field->set('DA', PdfString::text(
            FormImporter::rewriteDefaultAppearance($da->toUtf8(), $this->resourceRenames),
        ));
    }

    /**
     * Hands this source's /DR entries to the merged form, and reports
     * which of them had to be renamed to get in.
     *
     * A resource is identified to the form by its contents rather than
     * by its object number -- see FormImporter::takeDefaultResource().
     * Two files' /Helv are the same font if they say the same thing,
     * and cannot be the same object, since they are in different files.
     *
     * @return array<string, string> old name => new name
     */
    private function takeDefaultResources(): array
    {
        $resources = $this->source->resolveDictionary($this->sourceForm()?->get('DR'));
        $renames = [];

        foreach ($resources?->entries() ?? [] as $category => $entries) {
            if (!$entries instanceof Dictionary) {
                continue;
            }

            foreach ($entries->entries() as $name => $value) {
                $chosen = $this->form?->takeDefaultResource(
                    (string) $category,
                    (string) $name,
                    $this->signatureOf($value),
                    fn (): PdfValue => $this->copyValue($value),
                );

                if ($chosen !== null && $chosen !== (string) $name) {
                    $renames[(string) $name] = $chosen;
                }
            }
        }

        return $renames;
    }

    /**
     * What a resource *is*, as bytes: the object it points at, rendered.
     *
     * Rendering the reference itself would compare object numbers, which
     * say nothing across files. Rendering what it resolves to compares
     * the font -- and a font that embeds a program still differs
     * between files, because the program hangs off it by reference, so
     * this errs towards keeping both rather than merging two fonts that
     * only look alike.
     */
    private function signatureOf(PdfValue $value): string
    {
        $resolved = $this->source->resolve($value);

        return $resolved instanceof PdfObject ? $resolved->render(false) : ($resolved?->format() ?? '');
    }

    private function syncKids(int $parentSourceId): void
    {
        $this->fields[$parentSourceId]->set('Kids', new PdfArray(...array_map(
            static fn (int $id): PdfReference => new PdfReference($id),
            $this->fieldKids[$parentSourceId],
        )));
    }

    private function sourceForm(): ?Dictionary
    {
        return $this->source->resolveDictionary($this->source->catalog()->get('AcroForm'));
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

    /**
     * An annotation written inline in /Annots rather than as an object
     * of its own is malformed and cannot be pointed at, so only
     * references are taken.
     *
     * @return list<PdfReference>
     */
    private function annotationReferences(Dictionary $page): array
    {
        $annots = $this->source->resolve($page->get('Annots'));

        if (!$annots instanceof PdfArray) {
            return [];
        }

        return array_values(array_filter(
            $annots->items(),
            static fn (PdfValue $item): bool => $item instanceof PdfReference,
        ));
    }

    /**
     * Copies the indirect object $oldObjectId (a Dictionary or a Stream)
     * into the target, renumbered, caching the result so a second reference
     * to it returns the same copy instead of duplicating it.
     */
    /**
     * An annotation, copied like any other object except for where it
     * points.
     *
     * A destination names a page, and a page is the one thing that must
     * not be deep-copied here: it either belongs to the merged document
     * already or is not going to, and copying it makes a third answer up.
     * See ImportedAnnotation.
     */
    private function copyAnnotation(int $oldObjectId): PdfObject
    {
        if (isset($this->idMap[$oldObjectId])) {
            return $this->copied[$this->idMap[$oldObjectId]];
        }

        $resolved = $this->source->get($oldObjectId);

        if (!$resolved instanceof Dictionary) {
            throw new \RuntimeException(
                "Cannot import annotation $oldObjectId: expected a dictionary, found something else.",
            );
        }

        $newId = $this->target->allocate();
        $this->idMap[$oldObjectId] = $newId;

        $copy = new ImportedAnnotation($newId, $this->importedPages);
        $this->copied[$newId] = $copy;

        foreach ($resolved->entries() as $key => $value) {
            match ((string) $key) {
                'Dest' => $this->copyDestination($copy, $copy, 'Dest', $value),
                'A' => $copy->set('A', $this->copyAction($copy, $value)),
                default => $copy->set((string) $key, $this->copyValue($value)),
            };
        }

        $this->target->register($copy);

        return $copy;
    }

    /**
     * An action, copied inline so that a /GoTo's destination can be held
     * back the way an annotation's own is.
     *
     * Inlining is not a liberty: an action dictionary may be direct or
     * indirect, and a copy that keeps one object per action would have to
     * carry the deferral through it for no gain.
     */
    private function copyAction(ImportedAnnotation $annotation, PdfValue $value): PdfValue
    {
        $action = $this->source->resolveDictionary($value);

        if ($action === null) {
            return $this->copyValue($value);
        }

        $copy = new Dictionary();

        foreach ($action->entries() as $key => $entry) {
            if ((string) $key === 'D') {
                $this->copyDestination($annotation, $copy, 'D', $entry);

                continue;
            }

            $copy->set((string) $key, $this->copyValue($entry));
        }

        return $copy;
    }

    /**
     * Records where a destination points, or drops it.
     *
     * Three shapes arrive here. An explicit destination -- a page and a
     * view -- is deferred until the page's fate is known. A *named* one
     * is dropped: the name trees that resolve them are not imported, and
     * a name that means one thing in the source may well mean another in
     * a document merged from several. Anything else is copied as it
     * stands, which covers the page-number form remote destinations use.
     */
    private function copyDestination(ImportedAnnotation $annotation, Dictionary $holder, string $key, PdfValue $value): void
    {
        $destination = $this->source->resolve($value);

        if ($destination instanceof PdfName || $destination instanceof PdfString) {
            return;
        }

        if (!$destination instanceof PdfArray) {
            $holder->set($key, $this->copyValue($value));

            return;
        }

        $items = $destination->items();
        $page = $items[0] ?? null;

        if (!$page instanceof PdfReference) {
            $holder->set($key, $this->copyValue($destination));

            return;
        }

        $annotation->deferDestination($holder, $key, $page->objectId(), array_slice($items, 1));
    }

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
