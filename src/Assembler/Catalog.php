<?php

declare(strict_types=1);

namespace MightyPDF\Assembler;

use MightyPDF\Assembler\Types\PdfArray;
use MightyPDF\Assembler\Types\PdfName;
use MightyPDF\Assembler\Types\PdfReference;

/**
 * The document catalog (ISO 32000-2 §7.7.2), the root of the document's
 * object hierarchy. Always an indirect object.
 */
final class Catalog extends Dictionary
{
    public function __construct(int $objectId)
    {
        parent::__construct($objectId);
        $this->set('Type', new PdfName('Catalog'));
    }

    public function setPages(int $pageTreeObjectId): void
    {
        $this->set('Pages', new PdfReference($pageTreeObjectId));
    }

    public function setAcroForm(int $acroFormObjectId): void
    {
        $this->set('AcroForm', new PdfReference($acroFormObjectId));
    }

    public function setOutlines(int $outlineObjectId): void
    {
        $this->set('Outlines', new PdfReference($outlineObjectId));
    }

    /**
     * How the document asks to be opened -- with its bookmark panel
     * showing, say (§12.2, /PageMode).
     *
     * Worth saying rather than leaving to the reader's default, which is
     * to show no panel at all: an outline nobody can see is the same as
     * no outline for most of the people who open the file.
     */
    public function setPageMode(PageMode|string $mode): void
    {
        $this->set('PageMode', new PdfName($mode instanceof PageMode ? $mode->value : $mode));
    }

    public function hasPageMode(): bool
    {
        return $this->get('PageMode') !== null;
    }

    /** How the reader arranges the pages (§12.2, /PageLayout). */
    public function setPageLayout(PageLayout $layout): void
    {
        $this->set('PageLayout', new PdfName($layout->value));
    }

    public function setViewerPreferences(int $objectId): void
    {
        $this->set('ViewerPreferences', new PdfReference($objectId));
    }

    /**
     * The document's name trees (§7.7.4) -- of which this library builds
     * exactly one, /EmbeddedFiles.
     */
    public function setNames(Dictionary $names): void
    {
        $this->set('Names', $names);
    }

    /**
     * The associated-files array (§14.13): every attachment that makes a
     * claim about its relationship to this document, listed at the top
     * level so a consumer can find it without walking the name tree.
     *
     * @param list<int> $fileSpecificationObjectIds
     */
    public function setAssociatedFiles(array $fileSpecificationObjectIds): void
    {
        $this->set('AF', $fileSpecificationObjectIds === [] ? null : new PdfArray(...array_map(
            static fn (int $id): PdfReference => new PdfReference($id),
            $fileSpecificationObjectIds,
        )));
    }
}
