<?php

declare(strict_types=1);

namespace MightyPDF\Assembler;

/**
 * Where a document's bytes go as they are produced, and how far into the
 * file they have got.
 *
 * The second half is why this is an interface rather than a bare stream
 * resource. Every cross-reference entry is a byte offset from the start
 * of the file, so whatever swallows the bytes must also be able to say
 * how many have gone past it -- and asking the destination (ftell()) is
 * not the same question: php://output cannot answer it at all, and a
 * handle positioned somewhere other than the start would answer it with
 * a number that is not a PDF offset.
 *
 * So the count is kept here, starting at zero on the first write. That
 * makes the contract explicit: a sink is handed a whole document from
 * its %PDF header, and offset() is the offset the next write lands at.
 * Appending a document to a handle that already has bytes in it is not
 * something this library can produce a valid xref for, and this is the
 * class that says so.
 *
 * Having one object own both operations is the same discipline
 * IndirectObjectRegistry already applies to offsets: one place counts,
 * and nothing else does arithmetic on lengths.
 */
interface ByteSink
{
    public function write(string $bytes): void;

    /**
     * How many bytes have been written so far -- equivalently, the
     * offset at which the next write will begin.
     */
    public function offset(): int;
}
