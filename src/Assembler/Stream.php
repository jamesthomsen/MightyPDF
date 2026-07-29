<?php

declare(strict_types=1);

namespace MightyPDF\Assembler;

use MightyPDF\Assembler\Types\PdfInteger;
use MightyPDF\Assembler\Types\PdfName;

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
    private readonly string $rawBytes;
    private readonly bool $compress;

    public function __construct(int $objectId, string $bytes, bool $compress = true)
    {
        parent::__construct($objectId);
        $this->rawBytes = $bytes;
        $this->compress = $compress;
    }

    protected function content(): string
    {
        if ($this->compress) {
            $bytes = gzcompress($this->rawBytes);
            if ($bytes === false) {
                throw new \RuntimeException('Failed to compress stream data.');
            }
            $this->set('Filter', new PdfName('FlateDecode'));
        } else {
            $bytes = $this->rawBytes;
        }

        $this->set('Length', new PdfInteger(strlen($bytes)));

        return parent::content() . "\nstream\n{$bytes}\nendstream";
    }
}
