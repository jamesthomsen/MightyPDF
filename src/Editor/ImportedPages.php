<?php

declare(strict_types=1);

namespace MightyPDF\Editor;

/**
 * Which of a source document's pages made it into the merged one, and
 * what they were renumbered to.
 *
 * Small enough to look pointless until you see who asks: a link on an
 * imported page names its target page directly, and whether that page is
 * in the new document at all is not known when the link is copied. See
 * ImportedAnnotation.
 */
final class ImportedPages
{
    /** @var array<int, int> source page object id => the id it was imported as */
    private array $pages = [];

    public function record(int $sourceObjectId, int $importedObjectId): void
    {
        $this->pages[$sourceObjectId] = $importedObjectId;
    }

    public function importedId(int $sourceObjectId): ?int
    {
        return $this->pages[$sourceObjectId] ?? null;
    }
}
