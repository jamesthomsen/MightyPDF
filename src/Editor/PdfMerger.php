<?php

declare(strict_types=1);

namespace MightyPDF\Editor;

use MightyPDF\Assembler\Document;

/**
 * Combines the pages of several existing PDFs into one new document.
 *
 * Thin sugar over PageImporter, which stays public for finer-grained use
 * (importing a subset of a file's pages, or importing from a source already
 * opened some other way).
 */
final class PdfMerger
{
    public static function merge(string ...$paths): Document
    {
        $document = new Document();

        foreach ($paths as $path) {
            $importer = new PageImporter(PdfEditor::open($path), $document);

            foreach ($importer->pages() as $sourcePage) {
                $importer->import($sourcePage);
            }
        }

        return $document;
    }
}
