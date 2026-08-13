<?php

declare(strict_types=1);

namespace MightyPDF\Assembler;

use MightyPDF\Assembler\Types\PdfValue;
use MightyPDF\Exception\LogicException;

/**
 * Base class for every PDF object that has (or could have) its own object
 * number: dictionaries, streams, and anything built from them.
 *
 * The 2012 implementation had two separate, inconsistent ways of becoming
 * an "indirect object" (N 0 obj ... endobj): a working one on the base
 * Object class, and a second, never-implemented asIndirectObject() stub
 * that MightyPDF_Stream alone tried to call -- which meant writing any
 * page content was a guaranteed fatal error. This class has exactly one
 * mechanism: subclasses describe their body via content(), and the
 * final render() below is the only place that ever adds the "N 0 obj" /
 * "endobj" wrapper. There is nothing else to override or forget.
 *
 * Implements PdfValue so any PdfObject (a Dictionary, say) can also be
 * used as a plain inline value nested inside another dictionary's
 * entries -- e.g. a Page's /Resources sub-dictionary, which is never
 * given its own object number. format() is just an alias for the
 * "bare value" half of render(). An object used only inline can be
 * constructed with no object id at all; objectId()/render(true) throw
 * if one is asked for and never assigned.
 */
abstract class PdfObject implements PdfValue
{
    /**
     * $generation is always 0 for objects this library creates, and stays
     * a defaulted parameter for exactly that reason -- nothing in the
     * writer ever passes it. It exists because an object *read back out of
     * an existing file* may carry a non-zero generation, and rewriting
     * such an object in an incremental update has to reuse the number it
     * already had: the generation is part of an object's identity, so
     * "5 3 obj" rewritten as "5 0 obj" no longer answers the "5 3 R"
     * references pointing at it.
     */
    public function __construct(
        private readonly ?int $objectId = null,
        private readonly int $generation = 0,
    ) {
    }

    public function objectId(): int
    {
        if ($this->objectId === null) {
            throw new LogicException('This object has no object id -- it was constructed for use as an inline value only.');
        }

        return $this->objectId;
    }

    /**
     * Whether this object has a number of its own, i.e. whether it is a
     * top-level object rather than a value nested inside one.
     *
     * Matters when editing a file this library did not write: only an
     * indirect object can be rewritten by an incremental update, and
     * whether, say, a document's /AcroForm is indirect or written inline
     * in the catalog is a choice the original writer made.
     */
    public function hasObjectId(): bool
    {
        return $this->objectId !== null;
    }

    public function generation(): int
    {
        return $this->generation;
    }

    /**
     * The object's body, with no "N 0 obj"/"endobj" wrapper -- e.g. a
     * dictionary's "<< ... >>", or a stream's
     * "<< ... >>\nstream\n...\nendstream".
     */
    abstract protected function content(): string;

    /**
     * Renders this object either as a bare value (embedded inline in a
     * parent dictionary/array) or as a complete indirect object.
     *
     * Deliberately has no leading newline before "N 0 obj": the 2012
     * writer prepended one for cosmetic reasons and then had to "fix up"
     * every recorded xref offset by +1 to compensate, scattered across
     * three separate call sites -- one of which miscounted. Omitting the
     * leading newline here means whatever records this object's byte
     * offset can point directly at the start of the returned string, no
     * correction needed.
     */
    final public function render(bool $indirect): string
    {
        $content = $this->content();

        if (!$indirect) {
            return $content;
        }

        return sprintf("%d %d obj\n%s\nendobj\n", $this->objectId(), $this->generation, trim($content));
    }

    final public function format(): string
    {
        return $this->render(false);
    }
}
