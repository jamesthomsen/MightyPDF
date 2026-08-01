<?php

declare(strict_types=1);

namespace MightyPDF\Editor;

use MightyPDF\Assembler\Dictionary;
use MightyPDF\Assembler\DocumentContext;
use MightyPDF\Assembler\Form\AcroForm;
use MightyPDF\Assembler\PdfObject;
use MightyPDF\Assembler\Stream;

/**
 * Presents a document being edited as something the content layer can
 * draw into.
 *
 * Object numbers come from the editor, so they land above everything the
 * file already uses, and finished objects go into the update rather than
 * into a document being built from nothing. The caches are per-overlay
 * rather than per-file: they exist so that drawing the same logo on
 * twenty pages embeds it once, and they must not be seeded from the
 * file's own objects, since nothing here knows whether an image already
 * in the document is byte-identical to one about to be added.
 */
final class EditedDocument implements DocumentContext
{
    /** @var array<string, Stream> */
    private array $imageCache = [];

    /** @var array<string, Dictionary> */
    private array $fontCache = [];

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
     * Refused, deliberately.
     *
     * Adding a field to an existing document is not the same problem as
     * adding one to a document being built: the file already has an
     * /AcroForm, with its own /DR resources and its own field tree, and a
     * new form built alongside it would leave a document with two --
     * which readers resolve by ignoring one of them, usually the new one.
     * Adopting the existing form properly is worth doing and is not done
     * yet, so this says so rather than producing a file that looks right
     * and has an invisible field in it.
     */
    public function acroForm(): AcroForm
    {
        throw new \LogicException(
            'Adding form fields to an existing document is not supported yet -- the file already has its own '
            . '/AcroForm, and a second one would be ignored. Use MightyPDF\Editor\Form\FormFiller to fill the '
            . 'fields it already has.',
        );
    }
}
