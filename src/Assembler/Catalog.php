<?php

declare(strict_types=1);

namespace MightyPDF\Assembler;

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
     * How the document asks to be opened: with its bookmark panel
     * showing, here (§12.2, /PageMode /UseOutlines).
     *
     * Worth saying rather than leaving to the reader's default, which is
     * to show no panel at all -- an outline nobody can see is the same as
     * no outline for most of the people who open the file.
     */
    public function setPageMode(string $mode): void
    {
        $this->set('PageMode', new PdfName($mode));
    }
}
