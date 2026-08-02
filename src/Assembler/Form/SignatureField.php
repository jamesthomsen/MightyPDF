<?php

declare(strict_types=1);

namespace MightyPDF\Assembler\Form;

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
 */
final class SignatureField extends FormField
{
    protected function fieldType(): string
    {
        return 'Sig';
    }
}
