<?php

declare(strict_types=1);

namespace MightyPDF\Editor;

use MightyPDF\Assembler\Document;

/**
 * Combines the pages of several existing PDFs into one new document.
 *
 * Every page of every file, which is the least selective case of
 * PageSelection -- use that directly to take some of a file's pages, or to
 * take them in another order.
 */
final class PdfMerger
{
    public static function merge(string ...$paths): Document
    {
        return PageSelection::combine(...array_map(
            static fn (string $path): PageSelection => PageSelection::from($path),
            $paths,
        ));
    }

    /**
     * The same, for documents already opened -- one being edited, or one
     * that needed a password.
     */
    public static function mergeEditors(PdfEditor ...$sources): Document
    {
        return PageSelection::combine(...array_map(
            static fn (PdfEditor $source): PageSelection => PageSelection::of($source),
            $sources,
        ));
    }
}
