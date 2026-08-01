<?php

declare(strict_types=1);

namespace MightyPDF\Assembler;

use MightyPDF\Assembler\Types\PdfArray;
use MightyPDF\Assembler\Types\PdfName;
use MightyPDF\Assembler\Types\PdfReference;
use MightyPDF\Assembler\Types\PdfRectangle;

/**
 * A page object (ISO 32000-2 §7.7.3.3).
 *
 * Holds an explicit list of content Streams and a separate list of
 * annotation object ids -- unlike the 2012 Page, which attached arbitrary
 * objects to one generic bag (addObject()) that conflated page content
 * with everything else. /Contents and /Annots are kept in sync from
 * those two lists and omitted entirely when empty (a page with no
 * content has no /Contents at all, per spec, rather than an empty array).
 */
final class Page extends Dictionary implements PageContext
{
    private readonly Dictionary $resources;

    /** @var list<Stream> */
    private array $contentStreams = [];

    /** @var list<int> */
    private array $annotationObjectIds = [];

    public function __construct(int $objectId, PdfRectangle $mediaBox)
    {
        parent::__construct($objectId);

        $this->resources = new Dictionary();

        $this->set('Type', new PdfName('Page'));
        $this->set('MediaBox', $mediaBox);
        $this->set('Resources', $this->resources);
    }

    public function setParent(int $pageTreeObjectId): void
    {
        $this->set('Parent', new PdfReference($pageTreeObjectId));
    }

    public function resources(): Dictionary
    {
        return $this->resources;
    }

    public function addContentStream(Stream $stream): void
    {
        $this->contentStreams[] = $stream;
        $this->syncContents();
    }

    /** @return list<Stream> */
    public function contentStreams(): array
    {
        return $this->contentStreams;
    }

    public function addAnnotation(int $annotationObjectId): void
    {
        $this->annotationObjectIds[] = $annotationObjectId;
        $this->syncAnnotations();
    }

    private function syncContents(): void
    {
        $refs = array_map(
            static fn (Stream $stream): PdfReference => new PdfReference($stream->objectId()),
            $this->contentStreams,
        );

        $this->set('Contents', $refs === [] ? null : new PdfArray(...$refs));
    }

    private function syncAnnotations(): void
    {
        $refs = array_map(
            static fn (int $id): PdfReference => new PdfReference($id),
            $this->annotationObjectIds,
        );

        $this->set('Annots', $refs === [] ? null : new PdfArray(...$refs));
    }
}
