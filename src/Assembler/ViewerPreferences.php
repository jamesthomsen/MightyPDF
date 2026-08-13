<?php

declare(strict_types=1);

namespace MightyPDF\Assembler;

use MightyPDF\Assembler\Types\PdfBoolean;
use MightyPDF\Assembler\Types\PdfInteger;
use MightyPDF\Assembler\Types\PdfName;
use MightyPDF\Exception\InvalidArgumentException;

/**
 * How a document asks to be displayed and printed (ISO 32000-2 §12.2,
 * /ViewerPreferences).
 *
 * Every one of these is a request rather than an instruction -- a reader
 * may ignore any of them, and most readers ignore the window-chrome ones
 * outright. Two are worth setting anyway and are the reason this exists:
 *
 * - **displayDocumentTitle()** makes the window show the document's
 *   /Title instead of its filename. A file a person receives as
 *   "invoice_final_v3(2).pdf" then still says what it is.
 * - **printScaling(PrintScaling::None)** stops a reader shrinking the
 *   page to its printer's margins. That is the default behaviour and it
 *   is wrong for anything measured: a form that has to line up with a
 *   pre-printed one, a drawing at a stated scale, a sheet of labels.
 *
 * Nothing is written unless it is set, so a document that asks for
 * nothing carries no /ViewerPreferences at all.
 */
final class ViewerPreferences extends Dictionary
{
    /** Hides the reader's toolbar while this document is open. */
    public function hideToolbar(bool $hide = true): static
    {
        return $this->set('HideToolbar', new PdfBoolean($hide));
    }

    public function hideMenubar(bool $hide = true): static
    {
        return $this->set('HideMenubar', new PdfBoolean($hide));
    }

    /** Hides everything but the page itself: scrollbars, panels, the lot. */
    public function hideWindowUi(bool $hide = true): static
    {
        return $this->set('HideWindowUI', new PdfBoolean($hide));
    }

    /** Resizes the window to the first page. */
    public function fitWindow(bool $fit = true): static
    {
        return $this->set('FitWindow', new PdfBoolean($fit));
    }

    public function centerWindow(bool $center = true): static
    {
        return $this->set('CenterWindow', new PdfBoolean($center));
    }

    /**
     * Shows the document's /Title in the window rather than its filename.
     *
     * Set the title too, or this asks a reader to display nothing --
     * see Document::info()->setTitle().
     */
    public function displayDocumentTitle(bool $display = true): static
    {
        return $this->set('DisplayDocTitle', new PdfBoolean($display));
    }

    /** What to show when the reader leaves full-screen mode. */
    public function nonFullScreenPageMode(PageMode $mode): static
    {
        return $this->set('NonFullScreenPageMode', new PdfName($mode->value));
    }

    /**
     * Whether the reader may scale the page to fit its printer's
     * imageable area. None is what anything measured needs.
     */
    public function printScaling(PrintScaling $scaling): static
    {
        return $this->set('PrintScaling', new PdfName($scaling->value));
    }

    public function duplex(Duplex $duplex): static
    {
        return $this->set('Duplex', new PdfName($duplex->value));
    }

    /**
     * Asks the printer to choose its paper tray by the page size rather
     * than by its own default.
     */
    public function pickTrayByPageSize(bool $pick = true): static
    {
        return $this->set('PickTrayByPDFSize', new PdfBoolean($pick));
    }

    /** The number of copies the print dialog opens with. */
    public function numberOfCopies(int $copies): static
    {
        if ($copies < 1) {
            throw new InvalidArgumentException("A document cannot ask for $copies copies.");
        }

        return $this->set('NumCopies', new PdfInteger($copies));
    }
}
