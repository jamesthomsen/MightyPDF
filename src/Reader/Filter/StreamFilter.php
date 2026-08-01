<?php

declare(strict_types=1);

namespace MightyPDF\Reader\Filter;

/**
 * One decoding stage of a stream's /Filter chain.
 *
 * A pure byte transform: everything a filter needs beyond its input is in
 * the DecodeParms it is handed, so a filter can be tested against a
 * fixture with no document, no reader and no PDF anywhere in sight.
 */
interface StreamFilter
{
    /**
     * A hard ceiling on the bytes a single decode stage may produce.
     *
     * The compressing filters (Flate, LZW) and RunLength all expand their
     * input -- Flate by as much as ~1000:1 -- so a few kilobytes of
     * hostile input can demand gigabytes of memory. Because exhausting
     * memory is a fatal, uncatchable error in PHP, the expansion has to be
     * bounded as it happens rather than caught afterwards. 128 MiB matches
     * the ceiling PngImage already puts on decoded image data: far above
     * any stream this library realistically opens, far below a bomb. A
     * document that genuinely needs more is not one this reader is built
     * to open; raise it only with a real file that needs it.
     */
    public const int MAX_DECODED_BYTES = 128 * 1024 * 1024;

    public function decode(string $data, DecodeParms $parms): string;
}
