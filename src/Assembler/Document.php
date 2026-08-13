<?php

declare(strict_types=1);

namespace MightyPDF\Assembler;

use MightyPDF\Assembler\Attachment\AttachmentRelationship;
use MightyPDF\Assembler\Attachment\FileSpecification;
use MightyPDF\Assembler\Form\AcroForm;
use MightyPDF\Assembler\Types\PdfArray;
use MightyPDF\Assembler\Types\PdfBoolean;
use MightyPDF\Assembler\Types\PdfHexString;
use MightyPDF\Assembler\Types\PdfRectangle;
use MightyPDF\Assembler\Types\PdfReference;
use MightyPDF\Assembler\Types\PdfString;
use MightyPDF\Crypt\CryptTransform;
use MightyPDF\Crypt\Permissions;
use MightyPDF\Crypt\StandardSecurityHandler;
use MightyPDF\Exception\InvalidArgumentException;
use MightyPDF\Exception\LogicException;
use MightyPDF\Exception\RuntimeException;

/**
 * Top-level facade for assembling a PDF document from scratch -- the
 * modern-PHP successor to the old top-level MightyPDF class.
 *
 * Unlike the old class, saving never does any offset/count arithmetic
 * itself: it hands the header to IndirectObjectRegistry::writeAllTo()
 * and asks the resulting Xref for the trailer's /Size (see those
 * classes' doc comments for why centralizing that was the fix for the
 * confirmed 2012 /Size bug).
 *
 * There are three ways out -- save() for the bytes, writeTo() for a
 * stream, saveToFile() for a path -- and they are one code path with
 * three destinations (see ByteSink), rather than three chances to get
 * the tail of a PDF subtly different.
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
    private ?ViewerPreferences $viewerPreferences = null;
    private ?PageLabels $pageLabels = null;
    private ?XmpMetadata $metadata = null;
    private ?Structure\StructureTree $structure = null;

    /** @var array<string, FileSpecification> attachment name => its file specification */
    private array $attachments = [];
    private ?StandardSecurityHandler $security = null;
    private ?int $encryptObjectId = null;
    private ?PdfArray $id = null;

    /** See compressObjects(). */
    private bool $compressObjects = false;

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
    public function newPage(PageSize|PdfRectangle|null $mediaBox = null, int $rotation = 0): Page
    {
        $mediaBox = match (true) {
            $mediaBox instanceof PageSize => $mediaBox->mediaBox(),
            $mediaBox instanceof PdfRectangle => $mediaBox,
            default => new PdfRectangle(0, 0, self::LETTER_WIDTH, self::LETTER_HEIGHT),
        };

        $page = new Page($this->registry->allocate(), $mediaBox);
        $page->setRotation($rotation);
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

            // Asked for only where the document has not already said what
            // it wants. A caller who set a page mode of their own meant
            // it, and quietly overriding it because a bookmark was added
            // afterwards is a document that opens wrong depending on the
            // order two unrelated calls were made in.
            if (!$this->catalog->hasPageMode()) {
                $this->catalog->setPageMode(PageMode::Outlines);
            }
        }

        return $this->outline;
    }

    /**
     * How this document asks to be displayed and printed -- created
     * lazily, like acroForm() and info(), so a document that asks for
     * nothing carries no /ViewerPreferences.
     */
    public function viewerPreferences(): ViewerPreferences
    {
        if ($this->viewerPreferences === null) {
            $this->viewerPreferences = new ViewerPreferences($this->registry->allocate());
            $this->registry->register($this->viewerPreferences);
            $this->catalog->setViewerPreferences($this->viewerPreferences->objectId());
        }

        return $this->viewerPreferences;
    }

    /**
     * What the reader calls each page -- roman front matter, an appendix
     * numbered A-1, a cover with no number at all.
     *
     * Created lazily like the rest, so a document that says nothing about
     * its numbering carries no /PageLabels and readers count from 1 as
     * they always did.
     */
    public function pageLabels(): PageLabels
    {
        if ($this->pageLabels === null) {
            $this->pageLabels = new PageLabels($this->registry->allocate());
            $this->registry->register($this->pageLabels);
            $this->catalog->setPageLabels($this->pageLabels->objectId());

            // Checked at save rather than as runs are declared: whether
            // page 0 has been covered is only knowable once the caller
            // has stopped declaring them.
            $this->onBeforeSave(fn () => $this->pageLabels?->validate());
        }

        return $this->pageLabels;
    }

    /**
     * The document's XMP packet -- the metadata everything except a PDF
     * reader's properties box actually reads.
     *
     * Asking for it is what turns it on. The packet is generated from
     * info() at save, so the two cannot drift apart; see XmpMetadata for
     * why that direction rather than the other.
     */
    public function metadata(): XmpMetadata
    {
        if ($this->metadata === null) {
            $this->metadata = new XmpMetadata($this->registry->allocate());
            $this->registry->register($this->metadata->stream());
            $this->catalog->setMetadata($this->metadata->objectId());

            // At save rather than now: info() may not have been touched
            // yet, and which of the two calls comes first must not change
            // what the file says.
            $this->onBeforeSave(fn () => $this->metadata?->buildFrom($this->info));
        }

        return $this->metadata;
    }

    /**
     * The document's logical structure -- what makes it a *tagged* PDF,
     * and with it usable by a screen reader.
     *
     * Asking for it turns tagging on: the catalog gains /MarkInfo and a
     * /StructTreeRoot, and drawing done through PageBuilder::tagged() is
     * attached to it. See StructureTree.
     */
    public function structure(): Structure\StructureTree
    {
        if ($this->structure === null) {
            $this->structure = new Structure\StructureTree($this->registry->allocate(), $this);
            $this->registry->register($this->structure);

            $this->catalog->set('StructTreeRoot', new PdfReference($this->structure->objectId()));
            // Without /MarkInfo a reader has no reason to look for a
            // structure tree, and will not.
            $this->catalog->set('MarkInfo', (new Dictionary())->set('Marked', new PdfBoolean(true)));

            // The parent tree describes every mark in the document, so it
            // cannot be built until there are no more of them.
            $this->onBeforeSave(fn () => $this->structure?->finish());
        }

        return $this->structure;
    }

    /**
     * The structure tree if tagging is on, without turning it on. See
     * DocumentContext::activeStructure() for why the two are separate.
     */
    public function activeStructure(): ?Structure\StructureTree
    {
        return $this->structure;
    }

    /**
     * The document's language, as a BCP 47 tag ("en-GB", "fr").
     *
     * Required of a tagged document, and not a formality: it is what tells
     * a screen reader which voice to read it in, and a French document
     * read aloud in English is unintelligible rather than merely wrong.
     */
    public function setLanguage(string $language): void
    {
        $this->catalog->set('Lang', PdfString::text($language));
    }

    /** Which panel the reader shows when the document opens. */
    public function setPageMode(PageMode $mode): void
    {
        $this->catalog->setPageMode($mode);
    }

    /** How the reader arranges the pages -- as facing spreads, say. */
    public function setPageLayout(PageLayout $layout): void
    {
        $this->catalog->setPageLayout($layout);
    }

    /**
     * Carries a file inside this document: an e-invoice's XML, the
     * dataset behind a report, the original of something summarised.
     *
     * $name is what a reader shows and the key the attachment is filed
     * under, so two attachments cannot share one. $mediaType goes in as
     * the file's /Subtype ("application/xml", "text/csv"); $relationship
     * is the claim about what this file has to do with the document, and
     * is what an e-invoicing consumer looks for (see
     * AttachmentRelationship).
     *
     * Attaching anything also asks the reader to open its attachments
     * panel -- unless the document has already said what it wants -- on
     * the same reasoning as outline(): a file nobody notices is a file
     * nobody has.
     */
    public function attach(
        string $name,
        string $bytes,
        ?string $description = null,
        ?string $mediaType = null,
        AttachmentRelationship $relationship = AttachmentRelationship::Unspecified,
    ): FileSpecification {
        if ($name === '') {
            throw new InvalidArgumentException('An attachment needs a name -- it is what a reader shows and files it under.');
        }

        if (isset($this->attachments[$name])) {
            throw new InvalidArgumentException(
                "This document already carries an attachment called \"$name\". "
                . 'Names are the keys of a name tree, so they have to be distinct.',
            );
        }

        $embedded = FileSpecification::embeddedFile($this->registry->allocate(), $bytes, $mediaType);
        $this->registry->register($embedded);

        $specification = new FileSpecification(
            $this->registry->allocate(),
            $name,
            $embedded,
            $description,
            $relationship,
        );
        $this->registry->register($specification);

        $this->attachments[$name] = $specification;
        $this->syncAttachments();

        if (!$this->catalog->hasPageMode()) {
            $this->catalog->setPageMode(PageMode::Attachments);
        }

        return $specification;
    }

    /** @return array<string, FileSpecification> keyed by name */
    public function attachments(): array
    {
        return $this->attachments;
    }

    /**
     * Rebuilds the /EmbeddedFiles name tree and the /AF array.
     *
     * One flat node rather than a balanced tree: the spec permits either,
     * and a document with enough attachments for the difference to matter
     * is not a document this library is being asked to write. The keys
     * must be sorted, though -- a reader is entitled to binary-search
     * them, and an unsorted node is one where some attachments simply
     * cannot be found.
     */
    private function syncAttachments(): void
    {
        $sorted = $this->attachments;
        ksort($sorted, SORT_STRING);

        $pairs = [];

        foreach ($sorted as $name => $specification) {
            $pairs[] = PdfString::text((string) $name);
            $pairs[] = new PdfReference($specification->objectId());
        }

        $embeddedFiles = new Dictionary();
        $embeddedFiles->set('Names', new PdfArray(...$pairs));

        $names = new Dictionary();
        $names->set('EmbeddedFiles', $embeddedFiles);

        $this->catalog->setNames($names);
        $this->catalog->setAssociatedFiles(array_map(
            static fn (FileSpecification $specification): int => $specification->objectId(),
            array_values($sorted),
        ));
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
     *
     * @param bool $encryptMetadata whether the XMP packet is enciphered
     *        along with everything else. True is the default and the
     *        spec's; false leaves metadata() readable in the clear, which
     *        is the point of having it -- an indexer or asset manager with
     *        no password can still see the title and author of a document
     *        whose contents it cannot read. Only turn it off knowing that
     *        those fields become public.
     */
    public function encrypt(
        string $ownerPassword,
        string $userPassword = '',
        ?int $permissions = null,
        bool $encryptMetadata = true,
    ): void {
        if ($this->encryptObjectId !== null) {
            throw new LogicException('This document is already encrypted.');
        }

        $this->security = StandardSecurityHandler::create(
            $userPassword,
            $ownerPassword,
            $permissions ?? Permissions::all(),
            $encryptMetadata,
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

    /**
     * Packs this document's objects into object streams when it is saved,
     * which is the difference between a 40 kB form and a 15 kB one.
     *
     * A PDF's dictionaries are the compressible part of it and the writer
     * has never compressed them: individually they are too small for it to
     * pay, and there was nowhere to compress them together. An object
     * stream is that somewhere (see ObjectStream), and a document with many
     * small objects -- a form, an outline, anything tagged -- is mostly
     * dictionaries by count.
     *
     * Off by default, for two reasons worth knowing before turning it on.
     * The file stops being greppable: "/Type /Page" is no longer findable
     * with strings(1), which matters more than it sounds like it should
     * when something goes wrong at three in the morning. And the
     * cross-reference section becomes a stream rather than a table, so the
     * file needs a PDF 1.5 reader -- which is everything current, and not
     * everything embedded.
     *
     * Nothing about the document changes, only how it is written: the same
     * calls produce the same pages either way.
     */
    public function compressObjects(bool $compress = true): void
    {
        $this->compressObjects = $compress;
    }

    public function save(): string
    {
        $sink = new StringSink();

        $this->writeToSink($sink);

        return $sink->contents();
    }

    /**
     * Writes the finished document to an open stream, which is the way
     * to produce one larger than the memory available to hold it: a file
     * handle, a socket, php://output.
     *
     * save() has to materialize the whole document as one string,
     * because that is what it promises to return. Nothing else does --
     * and the two documents this library is most often asked for, a long
     * report and a scan-heavy merge, are exactly the ones where holding
     * every page and every embedded image at once is the difference
     * between working and exhausting memory_limit.
     *
     * The handle is written to from wherever it currently sits and is
     * left open; see StreamSink. Peak memory is one object at a time,
     * not one document -- see IndirectObjectRegistry::writeAllTo().
     *
     * @param resource $handle an open stream, opened for writing
     */
    public function writeTo($handle): void
    {
        $this->writeToSink(new StreamSink($handle));
    }

    /**
     * Everything save() and writeTo() have in common, which is all of it
     * bar where the bytes end up.
     */
    private function writeToSink(ByteSink $sink): void
    {
        foreach ($this->beforeSave as $finalize) {
            $finalize();
        }

        $xref = $this->registry->writeAllTo(
            self::HEADER,
            $sink,
            $this->encryptionPass(),
            $this->compressObjects,
            // A reader has to read /Encrypt before it can decrypt
            // anything, so it cannot be inside a stream it would have to
            // decrypt first.
            $this->encryptObjectId === null ? [] : [$this->encryptObjectId],
        );

        if ($xref->hasCompressedEntries()) {
            $this->writeCrossReferenceStream($sink, $xref);

            return;
        }

        $this->writeCrossReferenceTable($sink, $xref);
    }

    private function writeCrossReferenceTable(ByteSink $sink, Xref $xref): void
    {
        $trailer = $this->trailerFor($xref->highestObjectId() + 1);
        $startXref = $sink->offset();

        $sink->write($xref->build());
        $sink->write($trailer->build());
        $sink->write("startxref\n{$startXref}\n%%EOF");
    }

    /**
     * The cross-reference section as a stream, which a document with
     * object streams has no choice about: a classic table has no way to
     * say "object 12 is the third thing inside object 40".
     *
     * The section is an object itself, so it needs a number and an entry
     * in its own table -- a reader that has just found it via startxref
     * still expects to be told where it is. The number comes from the ids
     * already written rather than from the allocator, so that saving twice
     * gives the same bytes twice.
     */
    private function writeCrossReferenceStream(ByteSink $sink, Xref $xref): void
    {
        $xrefStreamId = $xref->highestObjectId() + 1;
        $startXref = $sink->offset();

        $xref->addEntry($xrefStreamId, $startXref);

        $trailer = $this->trailerFor($xrefStreamId + 1);

        $sink->write(XrefStream::build($xrefStreamId, $xref, $trailer)->render(true));
        $sink->write("startxref\n{$startXref}\n%%EOF");
    }

    private function trailerFor(int $size): Trailer
    {
        return Trailer::forNewDocument(
            size: $size,
            rootObjectId: $this->catalog->objectId(),
            infoObjectId: $this->info?->objectId(),
            id: $this->id,
            encryptObjectId: $this->encryptObjectId,
        );
    }

    /**
     * Streams the document straight to $path rather than building it in
     * memory and handing the whole thing to file_put_contents(), which
     * is what this used to do. Saving a 300-page report no longer needs
     * the report to fit in memory twice.
     */
    public function saveToFile(string $path): void
    {
        $handle = @fopen($path, 'wb');

        if ($handle === false) {
            throw new RuntimeException("Failed to write PDF to $path");
        }

        try {
            $this->writeTo($handle);
        } finally {
            fclose($handle);
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
     * So is the XMP packet of a document that said /EncryptMetadata
     * false. That entry is a promise to the reader about where the
     * plaintext is, and a file that makes it and then enciphers the
     * packet anyway does not produce unreadable metadata -- it produces
     * metadata a conforming reader confidently reads as noise. See
     * CryptTransform::isNeverEncrypted(), which the reader has always
     * consulted from the other direction.
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

            if ($object instanceof Dictionary
                && CryptTransform::isNeverEncrypted($object, $security->encryptsMetadata())) {
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
