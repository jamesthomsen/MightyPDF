<?php

declare(strict_types=1);

namespace MightyPDF\Assembler;

use MightyPDF\Assembler\Form\AcroForm;

/**
 * What PageBuilder needs from the document it is drawing into.
 *
 * The caches are here rather than on PageBuilder because what they hold
 * is document-scoped: an image XObject or a font dictionary is referenced
 * by object number from every page that uses it, so a per-page cache
 * would embed the same logo once per page it appears on.
 */
interface DocumentContext extends ObjectHost
{
    public function cachedImage(string $contentHash): ?Stream;

    public function cacheImage(string $contentHash, Stream $image): void;

    public function cachedFont(string $fontKey): ?Dictionary;

    public function cacheFont(string $fontKey, Dictionary $font): void;

    /**
     * The document's single interactive form.
     *
     * May throw where there is no sensible answer -- adopting an existing
     * document's /AcroForm is not the same problem as creating one, and a
     * context that cannot do it should say so rather than quietly building
     * a second form alongside the file's own.
     */
    public function acroForm(): AcroForm;

    /**
     * The document's structure tree if it has one, and **never** one
     * created by asking.
     *
     * The distinction is the point. Document::structure() creates the
     * tree, because a caller writing that means to tag the document; this
     * is what the drawing code asks, on every call, to find out whether it
     * should be emitting marked content -- and if that created a tree, an
     * untagged document would become a half-tagged one the moment anything
     * was drawn on it.
     *
     * Null rather than throwing, because unlike acroForm() there is a
     * sensible thing to do without one: draw untagged.
     */
    public function activeStructure(): ?Structure\StructureTree;
}
