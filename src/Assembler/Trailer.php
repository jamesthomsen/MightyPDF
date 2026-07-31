<?php

declare(strict_types=1);

namespace MightyPDF\Assembler;

use MightyPDF\Assembler\Types\PdfArray;
use MightyPDF\Assembler\Types\PdfInteger;
use MightyPDF\Assembler\Types\PdfReference;

/**
 * The trailer dictionary (ISO 32000-2 §7.5.5), "trailer\n<< ... >>".
 *
 * Per spec the trailer is never itself an indirect object, so this does
 * not extend PdfObject/Dictionary -- but it does hold a Dictionary and
 * render through it, so there is exactly one piece of code in the library
 * that knows how a trailer's bytes are shaped. The two named constructors
 * are the two situations that produce one; both end up in the same
 * build().
 *
 * $size must always be derived from the same Xref that was actually
 * written (Xref::highestObjectId() + 1), never hand-copied by a caller:
 * the 2012 bug was exactly this value coming from a third call site
 * (Xref::length(), which excluded the free-list head) instead of the
 * xref table itself.
 */
final class Trailer
{
    private function __construct(private readonly Dictionary $entries)
    {
    }

    public static function forNewDocument(
        int $size,
        int $rootObjectId,
        ?int $infoObjectId = null,
        ?PdfArray $id = null,
    ): self {
        $entries = new Dictionary();
        $entries->set('Size', new PdfInteger($size));
        $entries->set('Root', new PdfReference($rootObjectId));

        if ($infoObjectId !== null) {
            $entries->set('Info', new PdfReference($infoObjectId));
        }

        if ($id !== null) {
            $entries->set('ID', $id);
        }

        return new self($entries);
    }

    /**
     * The trailer for an incremental update.
     *
     * Built by copying the previous trailer wholesale and overriding only
     * /Size and /Prev, rather than by naming the keys worth keeping. An
     * update trailer must repeat /Root, and must repeat /ID unchanged or
     * every signature and every reader-side identity check on the file
     * breaks -- but it must also carry keys this library has never heard
     * of, since the document is one it did not write. Copying and then
     * overriding is the only version of this that cannot quietly drop
     * something.
     *
     * /Prev is the byte offset of the cross-reference section this update
     * supersedes -- the one the file's *previous* startxref pointed at,
     * which is what chains the sections together for a reader walking
     * backwards.
     */
    public static function forUpdate(Dictionary $previousTrailer, int $size, int $previousXrefOffset): self
    {
        $entries = new Dictionary();

        foreach ($previousTrailer->entries() as $key => $value) {
            $entries->set((string) $key, $value);
        }

        $entries->set('Size', new PdfInteger($size));
        $entries->set('Prev', new PdfInteger($previousXrefOffset));

        return new self($entries);
    }

    /**
     * The trailer's entries as a dictionary.
     *
     * For a cross-reference stream, which has no separate trailer at all
     * and must carry these keys in its own stream dictionary instead
     * (see XrefStream). Exposed rather than duplicated so that the rule
     * about which keys carry forward into an update and which get
     * overridden lives in forUpdate() alone, whichever section format
     * ends up being written.
     */
    public function entries(): Dictionary
    {
        return $this->entries;
    }

    public function build(): string
    {
        return "trailer\n" . $this->entries->format() . "\n";
    }
}
