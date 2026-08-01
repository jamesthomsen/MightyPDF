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
    /**
     * How much input to hand inflate_add() at a time on the salvage path.
     *
     * Small on purpose: a bomb decompresses ~1000:1, so feeding it whole
     * would let a single call allocate the very buffer the cap exists to
     * prevent. Feeding it in slices caps the memory in flight at one
     * slice's worth of expansion, which is checked after every slice.
     */
    private const int SALVAGE_CHUNK = 8192;

    /**
     * @param int $maxDecodedBytes the decompression-bomb ceiling. Defaults
     *        to the shared cap; a test injects a small one so it can prove
     *        the limit fires without inflating 128 MiB to do it.
     */
    public function __construct(private readonly int $maxDecodedBytes = self::MAX_DECODED_BYTES)
    {
    }

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

        // The length cap is the whole defence against a decompression
        // bomb: with it set, gzuncompress()/gzinflate() refuse a stream
        // that would inflate past it rather than allocating gigabytes from
        // a few hostile kilobytes. They return false in that case, exactly
        // as they do for corrupt data -- which is fine, since the salvage
        // pass below is bounded too and will re-detect the bomb.
        foreach ([$data, $trimmed] as $candidate) {
            $inflated = @gzuncompress($candidate, $this->maxDecodedBytes);

            if ($inflated !== false) {
                return $inflated;
            }

            $inflated = @gzinflate($candidate, $this->maxDecodedBytes);

            if ($inflated !== false) {
                return $inflated;
            }
        }

        $salvaged = $this->salvage($data) ?? $this->salvage($trimmed);

        if ($salvaged === null) {
            throw new ParseException('Stream is marked /FlateDecode but could not be inflated.');
        }

        return $salvaged;
    }

    /**
     * Inflates as far as the data allows, keeping the output instead of
     * discarding it. gzuncompress()/gzinflate() are all-or-nothing; the
     * incremental API is the only way to recover a truncated stream.
     *
     * The input is fed in slices rather than all at once so that the same
     * length cap the whole-buffer calls enforce applies here too: a bomb
     * that failed those calls (a valid but over-large stream fails them
     * with the cap set) must not simply reappear here and exhaust memory.
     */
    private function salvage(string $data): ?string
    {
        foreach ([ZLIB_ENCODING_DEFLATE, ZLIB_ENCODING_RAW] as $encoding) {
            $context = @inflate_init($encoding);

            if ($context === false) {
                continue;
            }

            $out = '';
            $failed = false;

            for ($offset = 0, $length = strlen($data); $offset < $length; $offset += self::SALVAGE_CHUNK) {
                $piece = @inflate_add($context, substr($data, $offset, self::SALVAGE_CHUNK), ZLIB_SYNC_FLUSH);

                if ($piece === false) {
                    $failed = true;
                    break;
                }

                $out .= $piece;

                if (strlen($out) > $this->maxDecodedBytes) {
                    throw new ParseException(
                        'Stream inflates to more than ' . $this->maxDecodedBytes . ' bytes; refusing it as a decompression bomb.',
                    );
                }
            }

            if (!$failed && $out !== '') {
                return $out;
            }
        }

        return null;
    }
}
