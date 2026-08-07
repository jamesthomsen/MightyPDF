<?php

declare(strict_types=1);

namespace MightyPDF\Assembler;

use MightyPDF\Assembler\Form\AcroForm;
use MightyPDF\Assembler\Types\PdfArray;
use MightyPDF\Assembler\Types\PdfHexString;
use MightyPDF\Assembler\Types\PdfRectangle;
use MightyPDF\Crypt\CryptTransform;
use MightyPDF\Crypt\Permissions;
use MightyPDF\Crypt\StandardSecurityHandler;

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
final class Document implements DocumentContext
{
    private const string HEADER = "%PDF-1.7\n%\xE2\xE3\xCF\xD3\n";

    public const float LETTER_WIDTH = 612.0;
    public const float LETTER_HEIGHT = 792.0;

    private readonly IndirectObjectRegistry $registry;
    private readonly Catalog $catalog;
    private readonly PageTreeNode $pageTree;
    private ?AcroForm $acroForm = null;
    private ?Outline $outline = null;
    private ?DocumentInfo $info = null;
    private ?StandardSecurityHandler $security = null;
    private ?int $encryptObjectId = null;
    private ?PdfArray $id = null;

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

    /**
     * Closures run at the start of save(), for a layer that has work to
     * do once nothing more will be added -- Layout\Flow's per-page
     * furniture, which cannot be drawn earlier because "Page 3 of 7"
     * needs a page count that is still changing.
     *
     * Registered here rather than left to the caller because the caller
     * is who forgets. A Flow hands out its Document through document(),
     * and $flow->document()->save() would otherwise produce a file with
     * no page numbers and no legal footer -- silently, since a missing
     * footer looks exactly like a document that never had one. This is
     * the assembler's own lifecycle, so it is the thing that can make
     * the two paths agree.
     *
     * @var list<\Closure(): void>
     */
    private array $beforeSave = [];

    public function __construct()
    {
        $this->registry = new IndirectObjectRegistry();

        $this->catalog = new Catalog($this->registry->allocate());
        $this->registry->register($this->catalog);

        $this->pageTree = new PageTreeNode($this->registry->allocate());
        $this->registry->register($this->pageTree);

        $this->catalog->setPages($this->pageTree->objectId());
    }

    /**
     * $mediaBox may be a PageSize, which is the readable way to say the
     * same thing: newPage(PageSize::A4) rather than a copied 595.28 x
     * 841.89. Widening the parameter rather than adding a second method
     * keeps one way to add a page.
     */
    public function newPage(PageSize|PdfRectangle|null $mediaBox = null): Page
    {
        $mediaBox = match (true) {
            $mediaBox instanceof PageSize => $mediaBox->mediaBox(),
            $mediaBox instanceof PdfRectangle => $mediaBox,
            default => new PdfRectangle(0, 0, self::LETTER_WIDTH, self::LETTER_HEIGHT),
        };

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

    public function allocate(): int
    {
        return $this->registry->allocate();
    }

    public function register(PdfObject $object): void
    {
        $this->registry->register($object);
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

    /**
     * The document's outline -- its bookmarks -- created lazily, the same
     * way acroForm() and info() are.
     *
     * Asking for it also asks readers to show their bookmark panel when
     * the document opens. A document with an outline wants it seen, and
     * the default is to show nothing.
     */
    public function outline(): Outline
    {
        if ($this->outline === null) {
            $this->outline = new Outline($this, $this->registry->allocate());
            $this->registry->register($this->outline);
            $this->catalog->setOutlines($this->outline->objectId());
            $this->catalog->setPageMode('UseOutlines');
        }

        return $this->outline;
    }

    /** @return list<Page> */
    public function pages(): array
    {
        return $this->pages;
    }

    /**
     * Created lazily, same reasoning as acroForm(): most documents never
     * set metadata at all, so nothing is allocated or written to /Info
     * unless something here is actually set.
     */
    public function info(): DocumentInfo
    {
        if ($this->info === null) {
            $this->info = new DocumentInfo($this->registry->allocate());
            $this->registry->register($this->info);
        }

        return $this->info;
    }

    /**
     * Encrypts this document with AES-256 when it is saved.
     *
     * Be clear about what each password does. The *user* password is
     * needed to open the document at all, and is the only thing here that
     * provides confidentiality; leave it empty -- the usual arrangement --
     * and the file opens in every viewer without a prompt, because the key
     * derives from the empty string. The *owner* password is what a reader
     * asks for before disregarding $permissions, which are a request
     * rather than a restriction (see Permissions).
     *
     * So: an empty user password gives a document that anyone can read and
     * that politely asks to be treated a certain way. A real one gives a
     * document that cannot be read without it.
     */
    public function encrypt(
        string $ownerPassword,
        string $userPassword = '',
        ?int $permissions = null,
    ): void {
        if ($this->encryptObjectId !== null) {
            throw new \LogicException('This document is already encrypted.');
        }

        $this->security = StandardSecurityHandler::create(
            $userPassword,
            $ownerPassword,
            $permissions ?? Permissions::all(),
        );

        $dictionary = new Dictionary($this->registry->allocate());

        foreach (($this->security->encryptDictionary()?->entries() ?? []) as $key => $value) {
            $dictionary->set((string) $key, $value);
        }

        $this->registry->register($dictionary);
        $this->encryptObjectId = $dictionary->objectId();

        // An encrypted document must carry a /ID, and both halves are the
        // same for a file that has never been updated.
        $id = new PdfHexString(random_bytes(16));
        $this->id = new PdfArray($id, $id);
    }

    /**
     * Registers a closure run at the start of every save(), in
     * registration order -- see $beforeSave.
     *
     * A closure registered here must be idempotent: save() may be called
     * more than once, and drawing a second set of footers on the second
     * call is exactly the bug this exists to prevent.
     *
     * @param \Closure(): void $finalize
     */
    public function onBeforeSave(\Closure $finalize): void
    {
        $this->beforeSave[] = $finalize;
    }

    public function save(): string
    {
        foreach ($this->beforeSave as $finalize) {
            $finalize();
        }

        $result = $this->registry->writeAll(self::HEADER, $this->encryptionPass());

        $trailer = Trailer::forNewDocument(
            size: $result->xref->highestObjectId() + 1,
            rootObjectId: $this->catalog->objectId(),
            infoObjectId: $this->info?->objectId(),
            id: $this->id,
            encryptObjectId: $this->encryptObjectId,
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

    /**
     * How each object gets enciphered on the way out, or null when the
     * document is not encrypted.
     *
     * The /Encrypt dictionary is passed through untouched: it is what
     * tells a reader how to decrypt everything else, so enciphering it
     * would leave a file nothing could open.
     *
     * @return (\Closure(PdfObject): PdfObject)|null
     */
    private function encryptionPass(): ?\Closure
    {
        $security = $this->security;

        if ($security === null) {
            return null;
        }

        $encryptObjectId = $this->encryptObjectId;

        return static function (PdfObject $object) use ($security, $encryptObjectId): PdfObject {
            if ($object->objectId() === $encryptObjectId) {
                return $object;
            }

            $objectId = $object->objectId();
            $generation = $object->generation();

            $encrypted = CryptTransform::apply(
                $object,
                static fn (string $bytes): string => $security->encryptString($bytes, $objectId, $generation),
                static fn (string $bytes): string => $security->encryptStream($bytes, $objectId, $generation),
            );

            return $encrypted instanceof PdfObject ? $encrypted : $object;
        };
    }
}
