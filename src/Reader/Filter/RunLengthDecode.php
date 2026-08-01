<?php

declare(strict_types=1);

namespace MightyPDF\Reader\Filter;

use MightyPDF\Reader\ParseException;

/**
 * /RunLengthDecode (ISO 32000-2 §7.4.5): a length byte, then either that
 * many literal bytes or one byte to repeat.
 */
final class RunLengthDecode implements StreamFilter
{
    /**
     * @param int $maxDecodedBytes the expansion ceiling. Defaults to the
     *        shared cap; a test injects a small one to prove the limit
     *        fires without decoding 128 MiB to do it.
     */
    public function __construct(private readonly int $maxDecodedBytes = self::MAX_DECODED_BYTES)
    {
    }

    public function decode(string $data, DecodeParms $parms): string
    {
        $out = '';
        $offset = 0;
        $length = strlen($data);

        while ($offset < $length) {
            $marker = ord($data[$offset++]);

            if ($marker === 128) {
                break;
            }

            if ($marker < 128) {
                $out .= substr($data, $offset, $marker + 1);
                $offset += $marker + 1;
            } elseif ($offset >= $length) {
                break;
            } else {
                // Each run byte expands to as many as 128 output bytes, so
                // a small stream can still expand past the cap. Refuse it
                // before the buffer grows rather than after -- memory
                // exhaustion is fatal and uncatchable.
                $out .= str_repeat($data[$offset++], 257 - $marker);
            }

            if (strlen($out) > $this->maxDecodedBytes) {
                throw new ParseException(
                    'Stream decodes to more than ' . $this->maxDecodedBytes . ' bytes; refusing it as a decompression bomb.',
                );
            }
        }

        return $out;
    }
}
