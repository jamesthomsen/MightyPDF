<?php

declare(strict_types=1);

namespace MightyPDF\Editor;

use MightyPDF\Assembler\Dictionary;
use MightyPDF\Assembler\Finalizable;
use MightyPDF\Assembler\Types\PdfArray;
use MightyPDF\Assembler\Types\PdfReference;
use MightyPDF\Assembler\Types\PdfValue;

/**
 * An annotation copied from another document, whose destination is
 * settled at save time rather than when it is copied.
 *
 * A link inside a document names its page directly, and that page may
 * not have been imported yet when the link is: a contents page linking
 * forwards is the ordinary case, not an exotic one. Copying the
 * reference as it stands would deep-copy the *page* -- its content
 * stream and all -- into a duplicate that is in no page tree, leaving
 * behind a link that goes somewhere invisible and a file carrying one
 * page twice. Neither shows until someone clicks.
 *
 * So the destination is recorded and written in the finalize pass, by
 * which time every page that is going to be imported has been. A
 * destination whose page was left behind is dropped: the link stays,
 * and does nothing.
 */
final class ImportedAnnotation extends Dictionary implements Finalizable
{
    /** @var list<array{holder: Dictionary, key: string, page: int, view: list<PdfValue>}> */
    private array $destinations = [];

    public function __construct(int $objectId, private readonly ImportedPages $pages)
    {
        parent::__construct($objectId);
    }

    /**
     * Notes that $holder's $key was a destination on the source's page
     * $page. The holder is this annotation for a /Dest, or the action
     * dictionary inside it for a /GoTo.
     *
     * @param list<PdfValue> $view what followed the page in the
     *        destination array -- the fit and its arguments, which are
     *        numbers and names, and so need no copying
     */
    public function deferDestination(Dictionary $holder, string $key, int $page, array $view): void
    {
        $this->destinations[] = ['holder' => $holder, 'key' => $key, 'page' => $page, 'view' => $view];
    }

    public function finalize(): void
    {
        foreach ($this->destinations as $destination) {
            $imported = $this->pages->importedId($destination['page']);

            $destination['holder']->set(
                $destination['key'],
                $imported === null
                    ? null
                    : new PdfArray(new PdfReference($imported), ...$destination['view']),
            );
        }
    }
}
