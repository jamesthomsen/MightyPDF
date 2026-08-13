<?php

declare(strict_types=1);

namespace MightyPDF\Assembler;

/**
 * A ByteSink that writes straight to an open stream -- a file, a socket,
 * php://output -- so that a document never has to exist as one string.
 *
 * The handle is not closed here, and not rewound. This sink is given a
 * stream, not ownership of one: the caller that opened php://output did
 * not mean for it to be closed underneath the rest of the response, and
 * a caller who wants to read back what was written to a temporary file
 * can rewind it themselves and knows whether it is seekable.
 * Document::saveToFile() owns the handle it opens, and closes it itself.
 */
final class StreamSink implements ByteSink
{
    private int $offset = 0;

    /** @var resource */
    private $handle;

    /**
     * @param resource $handle an open stream, opened for writing
     */
    public function __construct($handle)
    {
        // Checked here rather than left to fwrite(), which reports a
        // closed or non-stream handle as a warning and a false return --
        // at which point the document is already half-written and the
        // caller is holding a truncated file with no exception to say so.
        if (!is_resource($handle) || get_resource_type($handle) !== 'stream') {
            throw new \InvalidArgumentException(
                'A StreamSink needs an open stream resource, such as the return of fopen() -- '
                . 'got ' . get_debug_type($handle) . '.',
            );
        }

        $this->handle = $handle;
    }

    public function write(string $bytes): void
    {
        $length = strlen($bytes);
        $written = 0;

        // fwrite() is allowed to write less than it was given -- a full
        // pipe, a slow socket, a disk with no room left -- and a short
        // write taken for a complete one is the worst failure this class
        // can produce: the xref keeps counting from where the bytes
        // *should* have landed, so the result is a file whose offsets
        // all point a little past the truth. Every reader then reports a
        // damaged document, and nothing in the writer noticed.
        while ($written < $length) {
            $result = fwrite($this->handle, $written === 0 ? $bytes : substr($bytes, $written));

            // A zero-byte write is treated as failure rather than
            // retried. On a blocking stream it means the destination is
            // gone; on a non-blocking one it may be transient, but
            // spinning on it would hang the process instead of telling
            // anyone -- and a PDF writer is not the right place to
            // implement a retry policy for someone else's socket.
            if ($result === false || $result === 0) {
                throw new \RuntimeException(sprintf(
                    'Failed writing the PDF to its destination after %d of %d bytes (%d written overall).',
                    $written,
                    $length,
                    $this->offset + $written,
                ));
            }

            $written += $result;
        }

        $this->offset += $length;
    }

    public function offset(): int
    {
        return $this->offset;
    }
}
