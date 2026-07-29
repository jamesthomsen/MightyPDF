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
}
