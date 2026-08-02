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
 * /NeedAppearances is set true: rather than this library hand-building a
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

    public function __construct(int $objectId, ?Dictionary $defaultResources = null)
    {
        parent::__construct($objectId);

        $this->defaultResources = $defaultResources ?? new Dictionary();
        $this->set('DR', $this->defaultResources);
        $this->set('NeedAppearances', new PdfBoolean(true));
        $this->syncFields();
    }

    /**
     * Takes over the form of a document that already has one, so that
     * fields can be added to it rather than beside it.
     *
     * A second /AcroForm alongside the file's own is not a half-working
     * arrangement, it is a broken one: the catalog has room for exactly
     * one, so whichever loses is simply not there, and the fields listed
     * in it are invisible while looking perfectly correct in the object
     * model. Everything the existing form said is therefore carried
     * forward -- including /SigFlags, /DA, /Q and anything else this
     * library has never heard of -- and only /Fields is rebuilt, from the
     * ids it already listed plus whatever gets added.
     *
     * /NeedAppearances is deliberately left exactly as found. Turning it
     * on would ask readers to redraw every field in the document, not
     * just the new ones, which can visibly change fields nobody touched.
     * A newly added field with a value should be filled through
     * FormFiller instead, which draws its appearance directly.
     *
     * @param Dictionary $existing the form as read from the file
     * @param Dictionary $defaultResources its /DR, already resolved --
     *        the live object, since fonts added later have to land in the
     *        one the document actually points at
     * @param list<int> $fieldObjectIds the ids already in /Fields
     */
    public static function adopt(
        int $objectId,
        Dictionary $existing,
        Dictionary $defaultResources,
        array $fieldObjectIds,
    ): self {
        $form = new self($objectId, $defaultResources);

        foreach ($existing->entries() as $key => $value) {
            // /Fields is rebuilt from $fieldObjectIds below. Everything
            // else is copied verbatim, /DR included -- which restores the
            // original entry, so a /DR held as an indirect reference stays
            // a reference rather than being inlined into a second copy.
            if ((string) $key !== 'Fields') {
                $form->set((string) $key, $value);
            }
        }

        if ($existing->get('NeedAppearances') === null) {
            $form->set('NeedAppearances', null);
        }

        $form->fieldObjectIds = $fieldObjectIds;
        $form->syncFields();

        return $form;
    }

    public function addField(int $fieldObjectId): void
    {
        $this->fieldObjectIds[] = $fieldObjectId;
        $this->syncFields();
    }

    /** @return list<int> */
    public function fieldObjectIds(): array
    {
        return $this->fieldObjectIds;
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

        $fonts = $this->defaultResources->get('Font');
        if (!$fonts instanceof Dictionary) {
            $fonts = new Dictionary();
            $this->defaultResources->set('Font', $fonts);
        }

        // An adopted form's /DR usually already names the standard fonts,
        // and Acrobat's own /Helv is very often exactly the font being
        // asked for. Reusing that entry keeps the resources tidy and,
        // more to the point, avoids two names for one object.
        foreach ($fonts->entries() as $name => $value) {
            if ($value instanceof PdfReference && $value->objectId() === $fontDict->objectId()) {
                return $this->fontResourceNames[$fontKey] = (string) $name;
            }
        }

        $resourceName = $this->freeFontResourceName($fonts);
        $this->fontResourceNames[$fontKey] = $resourceName;
        $fonts->set($resourceName, new PdfReference($fontDict->objectId()));

        return $resourceName;
    }

    /**
     * Skipping any name /DR already uses.
     *
     * On a fresh document the counter alone is enough. On an adopted one
     * it is not: handing out /F1 when the document's own /DR already has
     * an /F1 meaning a different font would repoint every existing field
     * whose /DA names it -- the same class of bug that moving this
     * numbering onto AcroForm was meant to fix, arriving from the other
     * direction.
     */
    private function freeFontResourceName(Dictionary $fonts): string
    {
        do {
            $resourceName = 'F' . $this->nextFontResourceNumber++;
        } while ($fonts->get($resourceName) !== null);

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
