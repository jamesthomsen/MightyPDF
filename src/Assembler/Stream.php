<?php

declare(strict_types=1);

namespace MightyPDF\Assembler;

use MightyPDF\Assembler\Types\PdfInteger;
use MightyPDF\Assembler\Types\PdfName;
use MightyPDF\Exception\RuntimeException;

/**
 * A stream object (ISO 32000-2 §7.3.8): a dictionary describing the data,
 * followed by "stream\n{bytes}\nendstream". Always an indirect object in
 * practice (a stream with no object number would be meaningless), though
 * nothing here enforces that beyond what PdfObject already does.
 *
 * This is the class whose 2012 equivalent (MightyPDF_Stream) fatally
 * errored on every real use, calling a never-implemented
 * asIndirectObject(). Here content() simply appends the stream body to
 * the inherited dictionary rendering -- there is no separate wrapping
 * step to get wrong (see PdfObject's doc comment).
 */
final class Stream extends Dictionary
{
    private string $rawBytes;
    private readonly bool $compress;

    public function __construct(int $objectId, string $bytes, bool $compress = true, int $generation = 0)
    {
        parent::__construct($objectId, $generation);
        $this->rawBytes = $bytes;
        $this->compress = $compress;

        if ($compress) {
            // Declared up front rather than at render time so that
            // entries() always describes the bytes encodedBytes() will
            // produce. Anything copying this stream -- the encryption
            // layer, say -- would otherwise read a dictionary that does
            // not yet mention the compression it is about to inherit.
            $this->set('Filter', new PdfName('FlateDecode'));
        }
    }

    /**
     * Appends more raw bytes to this stream's body. Used by the content
     * layer so a whole page's worth of drawing operations can share one
     * content stream object (and one object id) instead of allocating a
     * new stream per operation -- content() re-derives Length/Filter from
     * whatever bytes are present at render time, so this is safe to call
     * any number of times before the document is saved.
     */
    public function appendBytes(string $bytes): void
    {
        $this->rawBytes .= $bytes;
    }

    /**
     * Replaces this stream's body outright.
     *
     * For a stream whose content cannot be known when it is registered:
     * an embedded font program, which is a subset of a font file
     * containing exactly the glyphs the document turned out to use, and
     * so is built during the finalize pass (see Finalizable) rather than
     * at the draw call that first needed the font. Distinct from
     * appendBytes() because that case replaces rather than accumulates,
     * and must give the same answer if it runs twice.
     */
    public function replaceBytes(string $bytes): void
    {
        $this->rawBytes = $bytes;
    }

    /**
     * The bytes exactly as this object holds them -- which is *not* always
     * the same thing as the bytes that will be written. The pair
     * (rawBytes, $compress) is the real state: with compression on these
     * are plain bytes that content() will deflate on the way out; with it
     * off they are already in final form, and content() emits them
     * untouched.
     *
     * That second case is how a stream read back out of an existing file
     * is represented (see MightyPDF\Reader\ObjectParser), so for a parsed
     * stream this returns the stored, still-encoded bytes -- whatever the
     * dictionary's /Filter says they are -- not decoded content.
     */
    public function rawBytes(): string
    {
        return $this->rawBytes;
    }

    /**
     * The bytes as they will appear between "stream" and "endstream":
     * compressed if this stream compresses, and already in final form if
     * it does not.
     *
     * Distinct from rawBytes() because anything that has to act on a
     * stream's *encoded* form -- encryption sits outside the filter chain,
     * not inside it -- needs the bytes after compression rather than
     * before, and must not have to reimplement the encoding to get them.
     */
    public function encodedBytes(): string
    {
        if (!$this->compress) {
            return $this->rawBytes;
        }

        $bytes = gzcompress($this->rawBytes);

        if ($bytes === false) {
            throw new RuntimeException('Failed to compress stream data.');
        }

        return $bytes;
    }

    protected function content(): string
    {
        $bytes = $this->encodedBytes();

        $this->set('Length', new PdfInteger(strlen($bytes)));

        return parent::content() . "\nstream\n{$bytes}\nendstream";
    }
}
