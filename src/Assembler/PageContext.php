<?php

declare(strict_types=1);

namespace MightyPDF\Assembler;

/**
 * What PageBuilder needs from the page it is drawing on.
 *
 * Deliberately small, and deliberately not "a Page": drawing onto a page
 * of an existing document does not append to that page's content stream
 * or write into its /Resources at all -- doing either would risk
 * disturbing content the library did not write. It draws into a form
 * XObject of its own instead (see MightyPDF\Editor\PageOverlay), which
 * answers these same three questions with its own isolated resources.
 */
interface PageContext
{
    /** The resource dictionary drawing operators will name things in. */
    public function resources(): Dictionary;

    public function addContentStream(Stream $stream): void;

    public function addAnnotation(int $annotationObjectId): void;
}
