<?php

declare(strict_types=1);

namespace MightyPDF\Assembler;

use MightyPDF\Assembler\Types\PdfRectangle;

/**
 * Top-level facade for assembling a PDF document from scratch -- the
 * modern-PHP successor to the old top-level MightyPDF class.
 *
 * Unlike the old class, save() never does any offset/count arithmetic
 * itself: it hands the header to IndirectObjectRegistry::writeAll() and
 * asks the resulting Xref for the trailer's /Size (see those classes'
 * doc comments for why centralizing that was the fix for the confirmed
 * 2012 /Size bug).
 */
final class Document
{
    private const string HEADER = "%PDF-1.7\n%\xE2\xE3\xCF\xD3\n";

    public const float LETTER_WIDTH = 612.0;
    public const float LETTER_HEIGHT = 792.0;

    private readonly IndirectObjectRegistry $registry;
    private readonly Catalog $catalog;
    private readonly PageTreeNode $pageTree;

    /** @var list<Page> */
    private array $pages = [];

    public function __construct()
    {
        $this->registry = new IndirectObjectRegistry();

        $this->catalog = new Catalog($this->registry->allocate());
        $this->registry->register($this->catalog);

        $this->pageTree = new PageTreeNode($this->registry->allocate());
        $this->registry->register($this->pageTree);

        $this->catalog->setPages($this->pageTree->objectId());
    }

    public function newPage(?PdfRectangle $mediaBox = null): Page
    {
        $mediaBox ??= new PdfRectangle(0, 0, self::LETTER_WIDTH, self::LETTER_HEIGHT);

        $page = new Page($this->registry->allocate(), $mediaBox);
        $page->setParent($this->pageTree->objectId());
        $this->registry->register($page);

        $this->pageTree->addKid($page->objectId());
        $this->pages[] = $page;

        return $page;
    }

    public function registry(): IndirectObjectRegistry
    {
        return $this->registry;
    }

    public function catalog(): Catalog
    {
        return $this->catalog;
    }

    /** @return list<Page> */
    public function pages(): array
    {
        return $this->pages;
    }

    public function save(): string
    {
        $result = $this->registry->writeAll(self::HEADER);

        $trailer = new Trailer(
            size: $result->xref->highestObjectId() + 1,
            rootObjectId: $this->catalog->objectId(),
        );

        $startXref = strlen($result->bytes);

        return $result->bytes
            . $result->xref->build()
            . $trailer->build()
            . "startxref\n{$startXref}\n%%EOF";
    }

    public function saveToFile(string $path): void
    {
        if (file_put_contents($path, $this->save()) === false) {
            throw new \RuntimeException("Failed to write PDF to $path");
        }
    }
}
