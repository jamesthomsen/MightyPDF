<?php

declare(strict_types=1);

namespace MightyPDF\Editor;

use MightyPDF\Assembler\Dictionary;
use MightyPDF\Assembler\PageContext;
use MightyPDF\Assembler\Stream;
use MightyPDF\Assembler\Types\PdfArray;
use MightyPDF\Assembler\Types\PdfInteger;
use MightyPDF\Assembler\Types\PdfName;
use MightyPDF\Assembler\Types\PdfReal;
use MightyPDF\Assembler\Types\PdfRectangle;
use MightyPDF\Assembler\Types\PdfReference;
use MightyPDF\Assembler\Types\PdfValue;
use MightyPDF\Content\PageBuilder;
use MightyPDF\Exception\LogicException;

/**
 * Draws onto a page of an existing document -- a logo, a stamp, a
 * watermark -- using the same PageBuilder that draws on a fresh page.
 *
 * Everything drawn goes into a form XObject of its own, which is then
 * invoked once from the page. That is not an implementation detail so
 * much as the point, because it removes three separate ways of damaging a
 * page this library did not write:
 *
 * - **Resource names cannot collide.** Appending to a page's content
 *   stream would mean naming fonts and images in the page's own
 *   /Resources, where /F1 very likely already means something. An XObject
 *   brings its own resource dictionary, and only one name has to be
 *   negotiated with the page.
 * - **Shared resources cannot be disturbed.** A page's /Resources is
 *   frequently inherited from an ancestor or shared between pages, so
 *   adding an entry to it in place would change every page that shares
 *   it. Only the page's own dictionary is touched, and copy-on-write.
 * - **Graphics state cannot leak.** Content that leaves the state
 *   unbalanced -- an unmatched `q`, a lingering clip or colour -- would
 *   otherwise apply to whatever is appended after it.
 *
 * Coordinates are the page's own, the space its /MediaBox is expressed
 * in, with the origin at the bottom-left. Note that a page with a
 * /Rotate is displayed turned: an overlay stays in the same place
 * relative to the page's content and turns with it, which is normally
 * what a stamp should do. See rotation() to decide otherwise.
 */
final class PageOverlay implements PageContext
{
    private const string RESOURCE_PREFIX = 'MPOverlay';

    private readonly Dictionary $resources;

    /** @var list<Stream> */
    private array $streams = [];

    /** @var list<int> */
    private array $annotationObjectIds = [];

    private ?PageBuilder $builder = null;
    private bool $applied = false;

    public function __construct(
        private readonly PdfEditor $editor,
        private readonly Dictionary $page,
    ) {
        $this->resources = new Dictionary();
    }

    /**
     * Where to draw. The same PageBuilder used for a fresh page, so every
     * drawing method behaves identically.
     */
    public function content(): PageBuilder
    {
        return $this->builder ??= new PageBuilder(new EditedDocument($this->editor), $this);
    }

    /**
     * Wires everything drawn into the page.
     *
     * Nothing is written until this is called, so an overlay that draws
     * nothing leaves the document untouched.
     */
    public function apply(): void
    {
        if ($this->applied) {
            throw new LogicException('This overlay has already been applied.');
        }

        $this->applied = true;

        if ($this->annotationObjectIds !== []) {
            $this->appendAnnotations();
        }

        if ($this->streams === []) {
            return;
        }

        $names = [];

        foreach ($this->streams as $stream) {
            $names[] = $this->registerXObject($stream);
        }

        $this->appendContents($names);
        $this->editor->register($this->page);
    }

    /** The page's size, resolving /MediaBox up the page tree if need be. */
    public function mediaBox(): PdfRectangle
    {
        $box = $this->numbers($this->inherited('MediaBox'));

        if (count($box) < 4) {
            // The spec requires one, but a page without it is not worth
            // refusing to stamp; US Letter is the near-universal default.
            return new PdfRectangle(0, 0, 612, 792);
        }

        return new PdfRectangle(
            min($box[0], $box[2]),
            min($box[1], $box[3]),
            max($box[0], $box[2]),
            max($box[1], $box[3]),
        );
    }

    /**
     * The page's /Rotate, in degrees clockwise. An overlay is drawn in
     * unrotated page space and turns with the page, so a caller wanting a
     * stamp upright on a rotated page has to compensate.
     */
    public function rotation(): int
    {
        $rotate = $this->editor->resolve($this->inherited('Rotate'));

        return $rotate instanceof PdfInteger ? (($rotate->value() % 360) + 360) % 360 : 0;
    }

    public function resources(): Dictionary
    {
        return $this->resources;
    }

    public function addContentStream(Stream $stream): void
    {
        $this->streams[] = $stream;
    }

    public function addAnnotation(int $annotationObjectId): void
    {
        $this->annotationObjectIds[] = $annotationObjectId;
    }

    /** Turns a drawn stream into a form XObject and names it on the page. */
    private function registerXObject(Stream $stream): string
    {
        $box = $this->mediaBox();

        $stream->set('Type', new PdfName('XObject'));
        $stream->set('Subtype', new PdfName('Form'));
        // The same rectangle the page is measured in, so that what the
        // caller drew at (72, 720) lands where it would on a fresh page.
        $stream->set('BBox', $box);
        $stream->set('Resources', $this->resources);

        $pageResources = $this->pageResources();
        $existing = $this->editor->resolveDictionary($pageResources->get('XObject'));

        // Copy-on-write: the /XObject dictionary may be shared with other
        // pages just as /Resources may be.
        $xObjects = new Dictionary();

        foreach ($existing?->entries() ?? [] as $key => $value) {
            $xObjects->set((string) $key, $value);
        }

        $name = $this->freeName($xObjects);
        $xObjects->set($name, new PdfReference($stream->objectId()));
        $pageResources->set('XObject', $xObjects);

        return $name;
    }

    /**
     * The page's own /Resources, copied from whatever it currently
     * inherits or shares if it has none of its own.
     *
     * Writing into an inherited or shared dictionary would silently add
     * the overlay's resources to every other page using it.
     */
    private function pageResources(): Dictionary
    {
        $effective = $this->editor->resolveDictionary($this->inherited('Resources'));

        $own = new Dictionary();

        foreach ($effective?->entries() ?? [] as $key => $value) {
            $own->set((string) $key, $value);
        }

        $this->page->set('Resources', $own);

        return $own;
    }

    /** @param list<string> $names */
    private function appendContents(array $names): void
    {
        $invocation = '';

        foreach ($names as $name) {
            $invocation .= sprintf("q\n%s Do\nQ\n", (new PdfName($name))->format());
        }

        $existing = $this->page->get('Contents');
        $items = match (true) {
            $existing instanceof PdfArray => $existing->items(),
            $existing !== null => [$existing],
            default => [],
        };

        if ($items === []) {
            $this->page->set('Contents', new PdfArray($this->contentReference($invocation)));

            return;
        }

        // Bracketing the original content in q/Q so that anything it
        // leaves set -- a clip, a colour, an unmatched q -- is popped
        // before the overlay draws. Separate streams rather than editing
        // the originals, which stay untouched.
        $this->page->set('Contents', new PdfArray(
            $this->contentReference("q\n"),
            ...$items,
            ...[$this->contentReference("Q\n"), $this->contentReference($invocation)],
        ));
    }

    private function contentReference(string $operators): PdfReference
    {
        $stream = new Stream($this->editor->allocate(), $operators, compress: false);
        $this->editor->register($stream);

        return new PdfReference($stream->objectId());
    }

    private function appendAnnotations(): void
    {
        $existing = $this->editor->resolve($this->page->get('Annots'));
        $items = $existing instanceof PdfArray ? $existing->items() : [];

        $this->page->set('Annots', new PdfArray(...[
            ...$items,
            ...array_map(
                static fn (int $id): PdfReference => new PdfReference($id),
                $this->annotationObjectIds,
            ),
        ]));
    }

    private function freeName(Dictionary $xObjects): string
    {
        for ($n = 0; ; ++$n) {
            $name = self::RESOURCE_PREFIX . $n;

            if ($xObjects->get($name) === null) {
                return $name;
            }
        }
    }

    /**
     * A page attribute, looked up on the page and then up the /Parent
     * chain -- /Resources, /MediaBox, /CropBox and /Rotate are all
     * inheritable (ISO 32000-2 §7.7.3.4), and plenty of files put them
     * only on the page tree root.
     */
    private function inherited(string $key): ?PdfValue
    {
        $node = $this->page;
        $seen = [];

        for ($depth = 0; $depth < 64; ++$depth) {
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

    /** @return list<float> */
    private function numbers(?PdfValue $value): array
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
}
