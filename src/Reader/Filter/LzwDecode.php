<?php

declare(strict_types=1);

namespace MightyPDF\Reader\Filter;

/**
 * /LZWDecode (ISO 32000-2 §7.4.4.2): variable-width LZW, codes growing
 * from 9 to 12 bits, with 256 meaning "clear the table" and 257 "end of
 * data".
 *
 * The /EarlyChange parameter is the one place this differs from the LZW
 * everyone else implements: with its default of 1, the code width grows
 * one code *earlier* than the table strictly requires. Getting it wrong
 * does not fail loudly -- it desynchronises the bit reader partway
 * through and yields plausible-looking garbage -- which is why it is
 * carried explicitly in DecodeParms rather than assumed.
 */
final class LzwDecode implements StreamFilter
{
    private const int CLEAR_TABLE = 256;
    private const int END_OF_DATA = 257;
    private const int FIRST_FREE_CODE = 258;
    private const int MAX_CODE_WIDTH = 12;

    public function decode(string $data, DecodeParms $parms): string
    {
        /** @var array<int, string> $table */
        $table = [];
        $nextCode = self::FIRST_FREE_CODE;
        $codeWidth = 9;
        $previous = null;

        $out = '';
        $bitBuffer = 0;
        $bitCount = 0;
        $offset = 0;
        $length = strlen($data);

        while (true) {
            while ($bitCount < $codeWidth && $offset < $length) {
                $bitBuffer = ($bitBuffer << 8) | ord($data[$offset++]);
                $bitCount += 8;
            }

            if ($bitCount < $codeWidth) {
                break;
            }

            $bitCount -= $codeWidth;
            $code = ($bitBuffer >> $bitCount) & ((1 << $codeWidth) - 1);

            if ($code === self::END_OF_DATA) {
                break;
            }

            if ($code === self::CLEAR_TABLE) {
                $table = [];
                $nextCode = self::FIRST_FREE_CODE;
                $codeWidth = 9;
                $previous = null;
                continue;
            }

            if ($code < 256) {
                $entry = chr($code);
            } elseif (isset($table[$code])) {
                $entry = $table[$code];
            } elseif ($previous !== null) {
                // The encoder is allowed to use a code one step before
                // defining it, when a sequence is immediately followed by
                // its own first character.
                $entry = $previous . $previous[0];
            } else {
                break;
            }

            $out .= $entry;

            if ($previous !== null) {
                $table[$nextCode++] = $previous . $entry[0];
            }

            $previous = $entry;

            if ($nextCode >= (1 << $codeWidth) - $parms->earlyChange && $codeWidth < self::MAX_CODE_WIDTH) {
                ++$codeWidth;
            }
        }

        return Predictor::undo($out, $parms);
    }
}
