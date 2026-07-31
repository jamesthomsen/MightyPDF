<?php

declare(strict_types=1);

namespace MightyPDF\Reader\Filter;

/**
 * /RunLengthDecode (ISO 32000-2 §7.4.5): a length byte, then either that
 * many literal bytes or one byte to repeat.
 */
final class RunLengthDecode implements StreamFilter
{
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
                continue;
            }

            if ($offset >= $length) {
                break;
            }

            $out .= str_repeat($data[$offset++], 257 - $marker);
        }

        return $out;
    }
}
