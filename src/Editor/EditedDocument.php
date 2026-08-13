<?php

declare(strict_types=1);

namespace MightyPDF\Editor;

use MightyPDF\Assembler\Dictionary;
use MightyPDF\Assembler\DocumentContext;
use MightyPDF\Assembler\Form\AcroForm;
use MightyPDF\Assembler\PdfObject;
use MightyPDF\Assembler\Stream;
use MightyPDF\Assembler\Types\PdfArray;
use MightyPDF\Assembler\Types\PdfReference;

/**
 * Presents a document being edited as something the content layer can
 * draw into.
 *
 * Object numbers come from the editor, so they land above everything the
 * file already uses, and finished objects go into the update rather than
 * into a document being built from nothing.
 *
 * The caches cover only what this instance adds, and are deliberately not
 * seeded from the file's own objects. For images, because nothing here
 * knows whether an image already in the document is byte-identical to one
 * about to be added. For fonts, because a font object is more than its
 * /BaseFont: the document's own /Helv may carry a different /Encoding, or
 * none, and text drawn through it would not say what it was given. A
 * second Helvetica dictionary costs a few dozen bytes; reusing one whose
 * encoding was never checked costs correctness.
 */
final class EditedDocument implements DocumentContext
{
    /** @var array<string, Stream> */
    private array $imageCache = [];

    /** @var array<string, Dictionary> */
    private array $fontCache = [];

    private ?AcroForm $acroForm = null;

    public function __construct(private readonly PdfEditor $editor)
    {
    }

    public function allocate(): int
    {
        return $this->editor->allocate();
    }

    public function register(PdfObject $object): void
    {
        $this->editor->register($object);
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

    /**
     * The document's form -- the one it already has, taken over, or a new
     * one wired into the catalog if it has none.
     *
     * Taking over matters because the catalog has room for exactly one
     * /AcroForm. Building a second alongside the file's own would not
     * half-work: whichever loses is simply not there, and the fields in it
     * are invisible while looking perfectly correct in the object model.
     */
    public function acroForm(): AcroForm
    {
        return $this->acroForm ??= $this->openAcroForm();
    }

    /**
     * None: a document being edited has whatever structure tree it came
     * with, and this library did not build it.
     *
     * Adopting it the way acroForm() adopts a form is a different and much
     * harder problem -- where in someone else's tree does a stamp belong?
     * -- and answering it wrongly produces a document that claims to be
     * tagged and is not, which is worse than one that never claimed to be.
     * So a stamp draws untagged, and says so here rather than by omission.
     */
    public function activeStructure(): ?\MightyPDF\Assembler\Structure\StructureTree
    {
        return null;
    }

    private function openAcroForm(): AcroForm
    {
        $catalog = $this->editor->catalog();
        $existing = $this->editor->resolveDictionary($catalog->get('AcroForm'));

        if ($existing === null) {
            $form = new AcroForm($this->editor->allocate());
            $this->editor->register($form);

            $catalog->set('AcroForm', new PdfReference($form->objectId()));
            $this->editor->register($catalog);

            return $form;
        }

        $defaultResources = $this->editor->resolveDictionary($existing->get('DR')) ?? new Dictionary();

        $form = AcroForm::adopt(
            // A form written inline in the catalog cannot be rewritten on
            // its own, so it becomes an object in its own right and the
            // catalog is repointed at it.
            $existing->hasObjectId() ? $existing->objectId() : $this->editor->allocate(),
            $existing,
            $defaultResources,
            $this->existingFieldIds($existing),
        );

        $this->editor->register($form);

        if (!$existing->hasObjectId()) {
            $catalog->set('AcroForm', new PdfReference($form->objectId()));
            $this->editor->register($catalog);
        }

        // Fonts added for a new field's /DA land in this dictionary. When
        // it is an object of its own, it is the object that changed and
        // has to be written; when it is inline, rewriting the form covers
        // it.
        if ($defaultResources->hasObjectId()) {
            $this->editor->register($defaultResources);
        }

        return $form;
    }

    /** @return list<int> */
    private function existingFieldIds(Dictionary $existing): array
    {
        $fields = $this->editor->resolve($existing->get('Fields'));

        if (!$fields instanceof PdfArray) {
            return [];
        }

        $ids = [];

        foreach ($fields->items() as $item) {
            // Only references can be carried across, since /Fields is
            // rebuilt from ids. A field written inline in the array is
            // malformed and there is nothing to point at.
            if ($item instanceof PdfReference) {
                $ids[] = $item->objectId();
            }
        }

        return $ids;
    }
}
