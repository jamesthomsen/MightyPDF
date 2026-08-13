<?php

declare(strict_types=1);

namespace MightyPDF\Assembler;

/**
 * A ByteSink that keeps the document in memory, for callers that asked
 * for the bytes rather than for somewhere to put them --
 * Document::save() and everything downstream of it.
 *
 * This is the old behaviour, unchanged and no slower: save() always did
 * build one string by concatenation. What has changed is that it is now
 * one of the choices rather than the only one. See StreamSink for the
 * other, and Document::writeTo() for why it matters.
 */
final class StringSink implements ByteSink
{
    private string $bytes = '';

    public function write(string $bytes): void
    {
        $this->bytes .= $bytes;
    }

    public function offset(): int
    {
        return strlen($this->bytes);
    }

    /** Everything written so far. */
    public function contents(): string
    {
        return $this->bytes;
    }
}
