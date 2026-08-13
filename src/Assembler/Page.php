<?php

declare(strict_types=1);

namespace MightyPDF\Assembler;

use MightyPDF\Assembler\Types\PdfArray;
use MightyPDF\Assembler\Types\PdfInteger;
use MightyPDF\Assembler\Types\PdfName;
use MightyPDF\Assembler\Types\PdfReference;
use MightyPDF\Assembler\Types\PdfRectangle;
use MightyPDF\Exception\InvalidArgumentException;

/**
 * A page object (ISO 32000-2 §7.7.3.3).
 *
 * Holds an explicit list of content Streams and a separate list of
 * annotation object ids -- unlike the 2012 Page, which attached arbitrary
 * objects to one generic bag (addObject()) that conflated page content
 * with everything else. /Contents and /Annots are kept in sync from
 * those two lists and omitted entirely when empty (a page with no
 * content has no /Contents at all, per spec, rather than an empty array).
 */
final class Page extends Dictionary implements PageContext
{
    private readonly Dictionary $resources;

    /**
     * Normalized, unlike the /MediaBox actually written, which is exactly
     * what the caller gave. Every box question below -- what the crop box
     * defaults to, whether a trim box fits on the sheet -- reads corners
     * rather than extents, and §7.9.5 permits a rectangle whose corners
     * are the other way round.
     */
    private readonly PdfRectangle $mediaBox;

    /**
     * The optional boxes, by /Key, in the order §14.11.2 defines them.
     * Held here as well as in the dictionary so that the resolved
     * getters can tell "not set, so inherit" from "set to something that
     * happens to equal the default".
     *
     * @var array<string, PdfRectangle>
     */
    private array $boxes = [];

    /** @var list<Stream> */
    private array $contentStreams = [];

    /** @var list<int> */
    private array $annotationObjectIds = [];

    public function __construct(int $objectId, PdfRectangle $mediaBox)
    {
        parent::__construct($objectId);

        $this->resources = new Dictionary();
        $this->mediaBox = $mediaBox->normalized();

        $this->set('Type', new PdfName('Page'));
        $this->set('MediaBox', $mediaBox);
        $this->set('Resources', $this->resources);
    }

    /**
     * The sheet this page is printed on (§14.11.2, /MediaBox) --
     * normalized, so x1/y1 is the lower-left corner whichever way round
     * it was given.
     */
    public function mediaBox(): PdfRectangle
    {
        return $this->mediaBox;
    }

    /**
     * What a reader clips this page to before displaying or printing it
     * (§14.11.2, /CropBox).
     *
     * The visible page, in other words, and the only one of these four
     * that changes what an ordinary reader shows. The rest are messages
     * to a print workflow and are ignored on screen.
     */
    public function setCropBox(PdfRectangle $box): void
    {
        $this->setBox('CropBox', $box);
    }

    /**
     * How far the press prints past the finished edge (§14.11.2,
     * /BleedBox) -- see PageSize::withBleed() for what bleed is for.
     */
    public function setBleedBox(PdfRectangle $box): void
    {
        $this->setBox('BleedBox', $box);
    }

    /**
     * The finished page: where the guillotine is meant to cut (§14.11.2,
     * /TrimBox).
     *
     * The box a printer's preflight check actually looks for. A file with
     * no /TrimBox is one where nothing in the document says how big the
     * finished piece is, and the shop has to ask.
     */
    public function setTrimBox(PdfRectangle $box): void
    {
        $this->setBox('TrimBox', $box);
    }

    /**
     * The extent of the meaningful content (§14.11.2, /ArtBox) -- what a
     * placing application crops to when this page is imported as artwork.
     */
    public function setArtBox(PdfRectangle $box): void
    {
        $this->setBox('ArtBox', $box);
    }

    /**
     * Declares that $bleed points around the edge of this sheet are bleed
     * rather than finished page: the trim box becomes the media box less
     * that much on every side, and the bleed box becomes the whole sheet.
     *
     * The one-call version of the commercial-print setup, and the other
     * half of PageSize::withBleed() -- that makes a sheet big enough,
     * this says how much of it gets cut off:
     *
     * ```php
     * $bleed = Unit::Millimetres->toPoints(3.0);
     * $page = $document->newPage(PageSize::A4->withBleed($bleed));
     * $page->setBleed($bleed);
     * ```
     *
     * Note what this does *not* do: the page origin stays at the corner
     * of the sheet, which is now outside the finished page. Content laid
     * out from (0, 0) starts in the bleed. That is the honest arrangement
     * -- PDF has one coordinate system per page and it is the media box's
     * -- so a caller placing content against the trim edge adds $bleed to
     * its coordinates, and Layout\Flow::setBleed() does exactly that to
     * its margins.
     */
    public function setBleed(float $bleed): void
    {
        if ($bleed < 0.0) {
            throw new InvalidArgumentException("Bleed is a margin outside the finished page, so it cannot be negative -- got $bleed.");
        }

        $trim = $this->mediaBox->expandedBy(-$bleed);

        // Measured against the sheet rather than by asking $trim how wide
        // it is: width() is absolute, so a bleed that has eaten past the
        // middle of the page produces an inside-out rectangle that
        // cheerfully reports a positive width.
        if ($this->mediaBox->width() - $bleed * 2 <= 0.0
            || $this->mediaBox->height() - $bleed * 2 <= 0.0) {
            throw new InvalidArgumentException(sprintf(
                'A bleed of %s leaves nothing of a %s x %s sheet to trim to. '
                . 'The media box has to be the finished size *plus* the bleed -- see PageSize::withBleed().',
                self::describe($bleed),
                self::describe($this->mediaBox->width()),
                self::describe($this->mediaBox->height()),
            ));
        }

        $this->setTrimBox($trim);
        $this->setBleedBox($this->mediaBox);
    }

    /**
     * The visible page: the crop box if one was set, otherwise the media
     * box, which is what §14.11.2 says a reader assumes.
     */
    public function cropBox(): PdfRectangle
    {
        return $this->boxes['CropBox'] ?? $this->mediaBox;
    }

    /** The bleed box if one was set, otherwise the crop box (§14.11.2). */
    public function bleedBox(): PdfRectangle
    {
        return $this->boxes['BleedBox'] ?? $this->cropBox();
    }

    /** The trim box if one was set, otherwise the crop box (§14.11.2). */
    public function trimBox(): PdfRectangle
    {
        return $this->boxes['TrimBox'] ?? $this->cropBox();
    }

    /** The art box if one was set, otherwise the crop box (§14.11.2). */
    public function artBox(): PdfRectangle
    {
        return $this->boxes['ArtBox'] ?? $this->cropBox();
    }

    /**
     * Records a box, having checked it fits on the sheet.
     *
     * §14.11.2 lets a reader quietly reduce a box that hangs off the
     * media box to the intersection of the two, which is exactly the
     * behaviour worth refusing: a trim box a hair too big does not
     * announce itself, it silently becomes a different trim box, and the
     * first anyone hears of it is a print run cut to the wrong size. The
     * numbers go in the message because the caller almost never typed
     * them -- they came out of a unit conversion.
     */
    private function setBox(string $key, PdfRectangle $box): void
    {
        $normalized = $box->normalized();

        if (!$this->mediaBox->contains($normalized)) {
            throw new InvalidArgumentException(sprintf(
                'The /%s [%s %s %s %s] does not fit inside this page\'s media box [%s %s %s %s]. '
                . 'A reader is entitled to shrink it to the overlap of the two rather than report this, '
                . 'so the page would come out silently wrong. Give the page a bigger media box -- '
                . 'PageSize::withBleed() sizes one for a given bleed.',
                $key,
                self::describe($normalized->x1),
                self::describe($normalized->y1),
                self::describe($normalized->x2),
                self::describe($normalized->y2),
                self::describe($this->mediaBox->x1),
                self::describe($this->mediaBox->y1),
                self::describe($this->mediaBox->x2),
                self::describe($this->mediaBox->y2),
            ));
        }

        $this->boxes[$key] = $normalized;
        $this->set($key, $normalized);
    }

    /** A float in a message, without a tail of zeroes. */
    private static function describe(float $value): string
    {
        return rtrim(rtrim(number_format($value, 2, '.', ''), '0'), '.');
    }

    public function setParent(int $pageTreeObjectId): void
    {
        $this->set('Parent', new PdfReference($pageTreeObjectId));
    }

    /**
     * How far clockwise the reader turns this page before showing it
     * (§7.7.3.3, /Rotate).
     *
     * This is *not* a way to make a landscape page. It rotates the page
     * as displayed and printed while leaving the coordinate system
     * underneath it exactly as it was, so everything already drawn stays
     * where it was drawn and comes out sideways -- which is what makes it
     * the right tool for a scanned page that arrived the wrong way up,
     * and the wrong one for a landscape report. For that, give the page a
     * landscape media box: PageSize::A4->landscape().
     *
     * Multiples of 90 only, since that is all the spec permits.
     * Normalized into 0-270, so -90 and 270 are the same page.
     */
    public function setRotation(int $degrees): void
    {
        if ($degrees % 90 !== 0) {
            throw new InvalidArgumentException(
                "A page turns in multiples of 90 degrees, got $degrees.",
            );
        }

        $normalized = (($degrees % 360) + 360) % 360;

        // Omitted rather than written as 0: an unrotated page has no
        // /Rotate, and inheriting one from the page tree is a thing the
        // spec allows that this writer never does.
        $this->set('Rotate', $normalized === 0 ? null : new PdfInteger($normalized));
    }

    /** 0, 90, 180 or 270. */
    public function rotation(): int
    {
        $rotate = $this->get('Rotate');

        return $rotate instanceof PdfInteger ? $rotate->value() : 0;
    }

    public function resources(): Dictionary
    {
        return $this->resources;
    }

    public function addContentStream(Stream $stream): void
    {
        $this->contentStreams[] = $stream;
        $this->syncContents();
    }

    /** @return list<Stream> */
    public function contentStreams(): array
    {
        return $this->contentStreams;
    }

    /**
     * The key this page's marked content is filed under in the structure
     * tree's /ParentTree (§14.7.4.4).
     *
     * A page carrying marked content must have one, and it must be unique
     * in the document: it is how a reader gets from a mark on this page
     * back to the structure element that owns it. A page with marks and no
     * /StructParents is content the structure cannot account for, which is
     * what a checker reports and what assistive technology skips.
     */
    public function setStructParents(int $index): void
    {
        $this->set('StructParents', new PdfInteger($index));
    }

    public function addAnnotation(int $annotationObjectId): void
    {
        $this->annotationObjectIds[] = $annotationObjectId;
        $this->syncAnnotations();
    }

    private function syncContents(): void
    {
        $refs = array_map(
            static fn (Stream $stream): PdfReference => new PdfReference($stream->objectId()),
            $this->contentStreams,
        );

        $this->set('Contents', $refs === [] ? null : new PdfArray(...$refs));
    }

    private function syncAnnotations(): void
    {
        $refs = array_map(
            static fn (int $id): PdfReference => new PdfReference($id),
            $this->annotationObjectIds,
        );

        $this->set('Annots', $refs === [] ? null : new PdfArray(...$refs));
    }
}
