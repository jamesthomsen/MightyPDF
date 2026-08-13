<?php

declare(strict_types=1);

namespace MightyPDF\Assembler\Form;

use MightyPDF\Assembler\Dictionary;
use MightyPDF\Assembler\Types\PdfString;

/**
 * What a checkbox or radio widget tells a reader that is drawing the
 * button's appearance for itself: /MK /CA, the caption character, and a
 * /DA naming the font to draw it in.
 *
 * Neither entry is needed to *show* one of these buttons -- both types
 * carry a real two-state /AP, and a reader that uses it never reads
 * either. They matter because this library's forms set
 * /NeedAppearances, which asks readers to rebuild the appearance of
 * every widget in the document, and a reader doing as it was asked has
 * nothing else to go on. Without them poppler reports "Unknown font tag
 * 'ZaDb'" and draws an empty box where a tick should be.
 *
 * Shared by CheckboxField and RadioButtonWidget as a static rather than
 * a base class: the two deliberately have different ancestry (a radio
 * option is not a field), and this is the only thing they have in
 * common that FormField does not already give one of them.
 */
final class ButtonCaption
{
    /**
     * Both entries are written together or not at all: a caption with no
     * font to draw it in is what produced the poppler error in the first
     * place, and a font with nothing to set in it is dead weight.
     */
    public static function describe(
        Dictionary $widget,
        ?string $caption,
        ?string $fontResourceName,
    ): void {
        if ($caption === null || $fontResourceName === null) {
            return;
        }

        $appearanceCharacteristics = new Dictionary();
        $appearanceCharacteristics->set('CA', PdfString::raw($caption));
        $widget->set('MK', $appearanceCharacteristics);

        // Size 0 means "fit the box", which is what a reader wants for a
        // glyph that has to fill a widget whose size it does not know.
        $widget->set('DA', PdfString::raw("/$fontResourceName 0 Tf 0 g"));
    }
}
