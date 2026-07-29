<?php

declare(strict_types=1);

namespace MightyPDF\Assembler\Form;

use MightyPDF\Assembler\Dictionary;
use MightyPDF\Assembler\Types\PdfArray;
use MightyPDF\Assembler\Types\PdfBoolean;
use MightyPDF\Assembler\Types\PdfReference;

/**
 * The document-level interactive form dictionary (ISO 32000-2 §12.7.2),
 * referenced from the Catalog's /AcroForm entry.
 *
 * /NeedsAppearances is set true: rather than this library hand-building a
 * correct text-field appearance stream for every field (a large amount
 * of work, and exactly the class of bug that caused the user's original
 * TCPDF form-field pain), readers are asked to regenerate field
 * appearances themselves from /DA + /V, which is the standard,
 * widely-supported escape hatch most PDF libraries rely on.
 */
final class AcroForm extends Dictionary
{
    private readonly Dictionary $defaultResources;

    /** @var list<int> */
    private array $fieldObjectIds = [];

    public function __construct(int $objectId)
    {
        parent::__construct($objectId);

        $this->defaultResources = new Dictionary();
        $this->set('DR', $this->defaultResources);
        $this->set('NeedsAppearances', new PdfBoolean(true));
        $this->syncFields();
    }

    public function addField(int $fieldObjectId): void
    {
        $this->fieldObjectIds[] = $fieldObjectId;
        $this->syncFields();
    }

    /** The /DR dictionary -- fonts referenced by any field's /DA must be registered here. */
    public function defaultResources(): Dictionary
    {
        return $this->defaultResources;
    }

    private function syncFields(): void
    {
        $this->set('Fields', new PdfArray(...array_map(
            static fn (int $id): PdfReference => new PdfReference($id),
            $this->fieldObjectIds,
        )));
    }
}
