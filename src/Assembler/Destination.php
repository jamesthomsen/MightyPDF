<?php

declare(strict_types=1);

namespace MightyPDF\Assembler;

use MightyPDF\Assembler\Types\PdfArray;
use MightyPDF\Assembler\Types\PdfName;
use MightyPDF\Assembler\Types\PdfNull;
use MightyPDF\Assembler\Types\PdfReal;
use MightyPDF\Assembler\Types\PdfReference;
use MightyPDF\Assembler\Types\PdfValue;

/**
 * A place in the document: a page, and how a reader should be looking at
 * it when it gets there (ISO 32000-2 §12.3.2).
 *
 * The same value serves a link and a bookmark, which is why it is here
 * rather than inside either -- "go to page 4" is one idea, and having it
 * written twice is how the two end up disagreeing.
 *
 * The view is deliberately narrow: the whole page, its width, or a
 * particular point at the top of the window. Those are the three a
 * document *author* means, and each of the others (/FitR, /FitBV and the
 * bounding-box variants) asks the caller for coordinates it would have to
 * guess at.
 */
final class Destination
{
    private function __construct(
        private readonly int $pageObjectId,
        private readonly string $fit,
        /** @var list<PdfValue> */
        private readonly array $arguments,
    ) {
    }

    /**
     * A point on the page, scrolled to the top of the window.
     *
     * $top is a y coordinate in the page's own space, so it counts from
     * the *bottom* like everything else here: the top of a Letter page is
     * 792, not 0. Left null -- the usual case -- the reader keeps the
     * position it already had, which is what makes a link to a page feel
     * like a page turn rather than a jump.
     */
    public static function of(Page $page, ?float $top = null, ?float $left = null): self
    {
        return self::atPage($page->objectId(), $top, $left);
    }

    /** The same for a page reached by object id -- a page of a document being edited. */
    public static function atPage(int $pageObjectId, ?float $top = null, ?float $left = null): self
    {
        return new self($pageObjectId, 'XYZ', [
            self::coordinate($left),
            self::coordinate($top),
            // The zoom, which null leaves as it was. A number here would
            // change the reader's magnification on every link, which is
            // startling and almost never what an author meant.
            new PdfNull(),
        ]);
    }

    /** The whole page, fitted to the window. */
    public static function fitPage(Page $page): self
    {
        return new self($page->objectId(), 'Fit', []);
    }

    /** The page's full width, with $top at the top of the window. */
    public static function fitWidth(Page $page, ?float $top = null): self
    {
        return new self($page->objectId(), 'FitH', [self::coordinate($top)]);
    }

    /**
     * The destination as PDF writes it: the page, the fit, and whatever
     * that fit takes.
     */
    public function toArray(): PdfArray
    {
        return new PdfArray(
            new PdfReference($this->pageObjectId),
            new PdfName($this->fit),
            ...$this->arguments,
        );
    }

    public function pageObjectId(): int
    {
        return $this->pageObjectId;
    }

    private static function coordinate(?float $value): PdfValue
    {
        return $value === null ? new PdfNull() : new PdfReal($value);
    }
}
