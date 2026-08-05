<?php

declare(strict_types=1);

namespace MightyPDF\Assembler\Annotation;

use MightyPDF\Assembler\Destination;
use MightyPDF\Assembler\Dictionary;
use MightyPDF\Assembler\Types\PdfArray;
use MightyPDF\Assembler\Types\PdfInteger;
use MightyPDF\Assembler\Types\PdfName;
use MightyPDF\Assembler\Types\PdfRectangle;
use MightyPDF\Assembler\Types\PdfString;

/**
 * A link (ISO 32000-2 §12.5.6.5): a rectangle of the page that goes
 * somewhere when it is clicked.
 *
 * A link draws nothing. It is a region laid over whatever is already
 * there, so the underlined blue text that makes a link *look* like one is
 * the caller's to draw -- which is the right way round, since a link over
 * an image or a button is just as ordinary.
 *
 * Two flavours, and the difference is where they point. A /URI action
 * leaves the document; a destination stays inside it, and is the same
 * value a bookmark uses (see Destination).
 */
final class LinkAnnotation extends Dictionary
{
    /** Annotation flag bit 3, "Print": without it the link is absent from a printed or flattened copy. */
    private const int FLAG_PRINT = 4;

    private function __construct(int $objectId, PdfRectangle $rect)
    {
        parent::__construct($objectId);

        $this->set('Type', new PdfName('Annot'));
        $this->set('Subtype', new PdfName('Link'));
        $this->set('Rect', $rect);
        $this->set('F', new PdfInteger(self::FLAG_PRINT));

        // No border. The spec's default is a one-unit-wide box around
        // every link, which no document made this century wants and which
        // readers draw in a startling black.
        $this->set('Border', new PdfArray(new PdfInteger(0), new PdfInteger(0), new PdfInteger(0)));
    }

    /**
     * A link out of the document.
     *
     * The URI is written as given. What a reader does with an unusual
     * scheme is its business and its policy -- most refuse anything but
     * http, https and mailto without asking, and a library that decided
     * that here would be overruling documents it knows nothing about.
     */
    public static function toUri(int $objectId, PdfRectangle $rect, string $uri): self
    {
        $link = new self($objectId, $rect);

        $action = new Dictionary();
        $action->set('Type', new PdfName('Action'));
        $action->set('S', new PdfName('URI'));

        // Latin-1 rather than a text string: a URI is a sequence of
        // ASCII characters by definition (RFC 3986), and anything else
        // in it has already been percent-encoded by whoever built it.
        $action->set('URI', PdfString::latin1($uri));

        $link->set('A', $action);

        return $link;
    }

    /**
     * A link to somewhere in this document.
     *
     * Written as /Dest rather than as a /GoTo action, which is the same
     * thing said in fewer objects; both are current, and readers that
     * handle one handle the other.
     */
    public static function toDestination(int $objectId, PdfRectangle $rect, Destination $destination): self
    {
        $link = new self($objectId, $rect);
        $link->set('Dest', $destination->toArray());

        return $link;
    }
}
