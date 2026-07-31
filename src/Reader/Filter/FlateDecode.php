<?php

declare(strict_types=1);

namespace MightyPDF\Reader\Filter;

use MightyPDF\Reader\ParseException;

/**
 * /FlateDecode -- zlib/deflate, by far the most common filter in a PDF
 * (ISO 32000-2 §7.4.4).
 *
 * Tries three things in turn, because plenty of real files are not quite
 * what they claim: a proper zlib stream, then raw deflate with no zlib
 * header (some generators omit it), then a salvage pass that keeps
 * whatever inflated before the data went bad. That last one matters
 * because a stream truncated at the end is still almost entirely
 * readable, and a page that renders with its last few operators missing
 * beats a document that will not open.
 */
final class FlateDecode implements StreamFilter
{
    public function decode(string $data, DecodeParms $parms): string
    {
        return Predictor::undo($this->inflate($data), $parms);
    }

    private function inflate(string $data): string
    {
        // Leading white space before the zlib header is not legal but is
        // produced in the wild, and it is what makes the header fail to
        // be recognised.
        $trimmed = ltrim($data, "\x00\x09\x0A\x0C\x0D\x20");

        foreach ([$data, $trimmed] as $candidate) {
            $inflated = @gzuncompress($candidate);

            if ($inflated !== false) {
                return $inflated;
            }

            $inflated = @gzinflate($candidate);

            if ($inflated !== false) {
                return $inflated;
            }
        }

        $salvaged = self::salvage($data) ?? self::salvage($trimmed);

        if ($salvaged === null) {
            throw new ParseException('Stream is marked /FlateDecode but could not be inflated.');
        }

        return $salvaged;
    }

    /**
     * Inflates as far as the data allows, keeping the output instead of
     * discarding it. gzuncompress()/gzinflate() are all-or-nothing; the
     * incremental API is the only way to recover a truncated stream.
     */
    private static function salvage(string $data): ?string
    {
        foreach ([ZLIB_ENCODING_DEFLATE, ZLIB_ENCODING_RAW] as $encoding) {
            $context = @inflate_init($encoding);

            if ($context === false) {
                continue;
            }

            $out = @inflate_add($context, $data, ZLIB_SYNC_FLUSH);

            if ($out !== false && $out !== '') {
                return $out;
            }
        }

        return null;
    }
}
