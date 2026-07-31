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

    /**
     * Font key => /DR resource name (e.g. "F1"). Both this map and the
     * counter below live here rather than on the per-page PageBuilder
     * because /DR is a single document-wide dictionary: a per-page cache
     * restarts numbering on every page, so page 2's first form font is
     * named /F1 again and silently overwrites page 1's /DR /Font /F1
     * entry -- leaving page 1's field pointing at page 2's font.
     *
     * @var array<string, string>
     */
    private array $fontResourceNames = [];
    private int $nextFontResourceNumber = 1;

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

    /**
     * Resource name to use in a field's /DA for $fontDict, registering it
     * in /DR /Font on first use. $fontKey is a plain string (Assembler
     * must not depend on Content), and $fontDict is expected to be the
     * document-wide shared font object for that key, so repeat calls
     * across pages return the same name pointing at the same object.
     */
    public function fontResourceName(string $fontKey, Dictionary $fontDict): string
    {
        if (isset($this->fontResourceNames[$fontKey])) {
            return $this->fontResourceNames[$fontKey];
        }

        $resourceName = 'F' . $this->nextFontResourceNumber++;
        $this->fontResourceNames[$fontKey] = $resourceName;

        $fonts = $this->defaultResources->get('Font');
        if (!$fonts instanceof Dictionary) {
            $fonts = new Dictionary();
            $this->defaultResources->set('Font', $fonts);
        }
        $fonts->set($resourceName, new PdfReference($fontDict->objectId()));

        return $resourceName;
    }

    private function syncFields(): void
    {
        $this->set('Fields', new PdfArray(...array_map(
            static fn (int $id): PdfReference => new PdfReference($id),
            $this->fieldObjectIds,
        )));
    }
}
