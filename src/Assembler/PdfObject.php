<?php

declare(strict_types=1);

namespace MightyPDF\Assembler;

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
 */
abstract class PdfObject
{
    public function __construct(private readonly int $objectId)
    {
    }

    public function objectId(): int
    {
        return $this->objectId;
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

        return sprintf("%d 0 obj\n%s\nendobj\n", $this->objectId, trim($content));
    }
}
