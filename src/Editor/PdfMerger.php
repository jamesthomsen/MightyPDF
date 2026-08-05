<?php

declare(strict_types=1);

namespace MightyPDF\Editor;

use MightyPDF\Assembler\Document;
use MightyPDF\Editor\Form\FormImporter;

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

        // One form for the whole merge, not one per source: a document
        // has room for a single /AcroForm, and the questions that make
        // merging forms hard -- two files each with a "signature" field,
        // two /DR dictionaries each with a different /Helv -- only exist
        // between sources. See FormImporter.
        $form = new FormImporter($document);

        // The same reasoning for bookmarks: a document has one outline,
        // and what a merged one looks like is a question about the merge
        // rather than about any file in it. See OutlineImporter.
        $outlines = new OutlineImporter($document);

        foreach ($paths as $path) {
            $source = PdfEditor::open($path);
            $importer = new PageImporter($source, $document, $form);

            $form->takeFormSettings($source->resolveDictionary($source->catalog()->get('AcroForm')));

            foreach ($importer->pages() as $sourcePage) {
                $importer->import($sourcePage);
            }

            // After this source's pages, so that every bookmark of its
            // that still points somewhere knows where.
            $outlines->take($source, $importer->importedPages());
        }

        $form->finish();

        return $document;
    }
}
