<?php

declare(strict_types=1);

namespace MightyPDF\Reader\Filter;

/**
 * /ASCII85Decode (ISO 32000-2 §7.4.3): four bytes encoded as five
 * printable characters base-85, "z" as shorthand for four zero bytes,
 * terminated by "~>".
 */
final class Ascii85Decode implements StreamFilter
{
    public function decode(string $data, DecodeParms $parms): string
    {
        $data = self::trimDelimiters($data);

        $out = '';
        $tuple = 0;
        $count = 0;
        $length = strlen($data);

        for ($i = 0; $i < $length; ++$i) {
            $byte = $data[$i];

            // "z" stands for four zero bytes, but only where a group
            // starts -- mid-group it is simply not a legal character.
            if ($byte === 'z' && $count === 0) {
                $out .= "\x00\x00\x00\x00";
                continue;
            }

            if ($byte < '!' || $byte > 'u') {
                // White space is explicitly ignorable, and skipping any
                // other stray byte recovers more streams than it damages.
                continue;
            }

            $tuple = $tuple * 85 + (ord($byte) - 33);

            if (++$count === 5) {
                $out .= self::pack($tuple, 4);
                $tuple = 0;
                $count = 0;
            }
        }

        if ($count > 0) {
            // A short final group encodes count-1 bytes. The missing
            // characters are treated as the maximum digit "u" so that the
            // bytes that *are* present round-trip exactly.
            for ($i = $count; $i < 5; ++$i) {
                $tuple = $tuple * 85 + 84;
            }

            $out .= self::pack($tuple, $count - 1);
        }

        return $out;
    }

    private static function trimDelimiters(string $data): string
    {
        $data = ltrim($data, "\x00\x09\x0A\x0C\x0D\x20");

        if (str_starts_with($data, '<~')) {
            $data = substr($data, 2);
        }

        $end = strpos($data, '~>');

        return $end === false ? $data : substr($data, 0, $end);
    }

    /** The high $bytes bytes of a 32-bit big-endian value. */
    private static function pack(int $tuple, int $bytes): string
    {
        // Masked because a full group can reach 85^5 - 1, which overflows
        // 32 bits even though the value it encodes does not.
        return substr(pack('N', $tuple & 0xFFFFFFFF), 0, $bytes);
    }
}
