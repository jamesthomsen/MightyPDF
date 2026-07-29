<?php

declare(strict_types=1);

namespace MightyPDF\Assembler;

use MightyPDF\Assembler\Types\PdfArray;
use MightyPDF\Assembler\Types\PdfInteger;
use MightyPDF\Assembler\Types\PdfName;
use MightyPDF\Assembler\Types\PdfReference;

/**
 * A page tree node (ISO 32000-2 §7.7.3.2). Phase 1 only ever builds a
 * single, flat tree (one root node, pages as direct children), so this
 * never needs a /Parent entry of its own or nested intermediate nodes.
 */
final class PageTreeNode extends Dictionary
{
    /** @var list<int> */
    private array $kidObjectIds = [];

    public function __construct(int $objectId)
    {
        parent::__construct($objectId);
        $this->set('Type', new PdfName('Pages'));
        $this->syncKids();
    }

    public function addKid(int $pageObjectId): void
    {
        $this->kidObjectIds[] = $pageObjectId;
        $this->syncKids();
    }

    private function syncKids(): void
    {
        $this->set('Kids', new PdfArray(...array_map(
            static fn (int $id): PdfReference => new PdfReference($id),
            $this->kidObjectIds,
        )));
        $this->set('Count', new PdfInteger(count($this->kidObjectIds)));
    }
}
