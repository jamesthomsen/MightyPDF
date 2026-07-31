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
    public function decode(string $data, DecodeParms $parms): string;
}
