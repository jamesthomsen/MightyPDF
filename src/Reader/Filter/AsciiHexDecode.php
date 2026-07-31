<?php

declare(strict_types=1);

namespace MightyPDF\Reader\Filter;

/**
 * /ASCIIHexDecode (ISO 32000-2 §7.4.2): each byte as two hex digits,
 * terminated by ">".
 */
final class AsciiHexDecode implements StreamFilter
{
    public function decode(string $data, DecodeParms $parms): string
    {
        $end = strpos($data, '>');

        if ($end !== false) {
            $data = substr($data, 0, $end);
        }

        $digits = preg_replace('/[^0-9A-Fa-f]/', '', $data) ?? '';

        // A final odd digit is defined to be padded with zero, exactly as
        // in a hexadecimal string object.
        if (strlen($digits) % 2 === 1) {
            $digits .= '0';
        }

        $binary = hex2bin($digits);

        return $binary === false ? '' : $binary;
    }
}
