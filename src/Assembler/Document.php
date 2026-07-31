<?php

declare(strict_types=1);

namespace MightyPDF\Assembler;

use MightyPDF\Assembler\Form\AcroForm;
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
    private ?AcroForm $acroForm = null;

    /** @var list<Page> */
    private array $pages = [];

    /**
     * Content-hash => already-registered image XObject stream, so that
     * embedding the same image bytes (e.g. a logo repeated across pages)
     * reuses one XObject instead of re-decoding and re-embedding it once
     * per draw call. Keyed by hash rather than file path, since two
     * different paths can hold identical bytes. Lives on Document (not
     * PageBuilder) because the cached Stream is referenced by id from
     * every page that draws it, not just the page that first did.
     *
     * @var array<string, Stream>
     */
    private array $imageCache = [];

    /**
     * Font key (a plain string, e.g. a StandardFont case name) =>
     * already-registered /Type /Font dictionary. Same reasoning as
     * $imageCache: the font object is referenced by id from every page
     * that uses it, so it belongs to the document rather than to the
     * per-page PageBuilder that happened to need it first.
     *
     * Keyed by string, never by a Content-layer type -- Assembler must
     * not depend on Content (see AcroForm/WinAnsiEncoding living here
     * for the same reason).
     *
     * @var array<string, Dictionary>
     */
    private array $fontCache = [];

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

    public function cachedImage(string $contentHash): ?Stream
    {
        return $this->imageCache[$contentHash] ?? null;
    }

    public function cacheImage(string $contentHash, Stream $image): void
    {
        $this->imageCache[$contentHash] = $image;
    }

    public function cachedFont(string $fontKey): ?Dictionary
    {
        return $this->fontCache[$fontKey] ?? null;
    }

    public function cacheFont(string $fontKey, Dictionary $font): void
    {
        $this->fontCache[$fontKey] = $font;
    }

    public function catalog(): Catalog
    {
        return $this->catalog;
    }

    /**
     * Created lazily -- most documents have no form fields at all, so
     * this only allocates and wires an /AcroForm into the Catalog the
     * first time something actually needs it, and every PageBuilder for
     * every page in the document shares this same instance (there is
     * exactly one /AcroForm per document, with every field from every
     * page listed together in its /Fields array).
     */
    public function acroForm(): AcroForm
    {
        if ($this->acroForm === null) {
            $this->acroForm = new AcroForm($this->registry->allocate());
            $this->registry->register($this->acroForm);
            $this->catalog->setAcroForm($this->acroForm->objectId());
        }

        return $this->acroForm;
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
