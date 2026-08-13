<?php

declare(strict_types=1);

namespace MightyPDF\Assembler\Form;

use MightyPDF\Assembler\Dictionary;
use MightyPDF\Assembler\Stream;
use MightyPDF\Assembler\Types\PdfReference;
use MightyPDF\Assembler\Types\PdfRectangle;

/**
 * An unsigned signature field placeholder (ISO 32000-2 §12.7.4.5), /FT
 * /Sig -- reserves a /Rect on the page and an entry in /AcroForm for a
 * signature to be added later by some other process.
 *
 * This library does not sign documents: hashing a byte range, embedding
 * a /Contents CMS blob, and handling certificates is a different feature
 * this project touches nowhere else. Accordingly /V is never set here --
 * an unsigned /Sig field has no /V at all per the spec, not an empty
 * one -- and there is deliberately no way to pass a value in.
 *
 * It does carry an appearance, though, and an empty one is not the same
 * as none. §12.5.5 wants every displayed annotation to have an /AP, and
 * the /NeedAppearances escape that text fields lean on does not help
 * here: there is no value to lay out, so a reader asked to regenerate
 * this has nothing to work from. Ghostscript says as much --
 * "AcroForm field 'Sig' with no AP not implemented" -- and this is the
 * same reasoning that gives CheckboxField a real /AP rather than
 * trusting readers to invent one.
 */
final class SignatureField extends FormField
{
    /**
     * $appearance is the widget's normal appearance: blank, but present.
     * Deliberately blank rather than a drawn box -- this library puts no
     * chrome on any other field either, and a caller who wants a visible
     * signing area draws one where they want it.
     */
    public function __construct(int $objectId, string $name, PdfRectangle $rect, ?Stream $appearance = null)
    {
        parent::__construct($objectId, $name, $rect);

        if ($appearance === null) {
            return;
        }

        // A single-state widget's /AP /N is the stream itself, not a
        // dictionary of states -- that form belongs to buttons, which
        // have an /AS to pick between them. A signature field has none.
        $this->set('AP', (new Dictionary())->set('N', new PdfReference($appearance->objectId())));
    }

    protected function fieldType(): string
    {
        return 'Sig';
    }
}
