<?php

declare(strict_types=1);

namespace MightyPDF\Content\Font\TrueType;

/**
 * Just enough of a 'CFF ' table to answer one question: are this font's
 * glyphs addressed by glyph index, or by character id through a charset
 * of its own?
 *
 * A CID-keyed CFF is the shape a CJK font usually takes, and embedding
 * one whole while addressing it by glyph index -- which is what this
 * library does -- draws the wrong glyphs rather than failing, because
 * both numbering schemes are dense small integers. Hence this: the
 * marker is the Top DICT's ROS operator, and reaching it means walking
 * the header, the name INDEX, and the first entry of the Top DICT INDEX.
 *
 * Deliberately not a CFF parser. Nothing else here reads charstrings,
 * subroutines or private dictionaries, because nothing here takes a CFF
 * font apart -- it is embedded exactly as it arrived.
 */
final class CffHeader
{
    /** Two-byte operators are introduced by 12; ROS is 12 30. */
    private const int ESCAPE = 12;
    private const int ROS = 30;

    private function __construct()
    {
    }

    public static function isCidKeyed(string $cff): bool
    {
        $topDict = self::topDict($cff);

        if ($topDict === null) {
            return false;
        }

        // Operands come before their operator, and every one of them is
        // self-delimiting, so a scan that skips operands correctly never
        // mistakes an operand's byte for an operator's.
        for ($i = 0, $length = strlen($topDict); $i < $length;) {
            $byte = ord($topDict[$i]);

            if ($byte === self::ESCAPE) {
                if (($i + 1) < $length && ord($topDict[$i + 1]) === self::ROS) {
                    return true;
                }

                $i += 2;

                continue;
            }

            $i += self::operandLength($topDict, $i);
        }

        return false;
    }

    /** The first font's Top DICT, or null where the table is not one. */
    private static function topDict(string $cff): ?string
    {
        if (strlen($cff) < 4) {
            return null;
        }

        // The header states its own size, which lets a future version add
        // fields without moving the name INDEX.
        $offset = ord($cff[2]);
        $offset = self::skipIndex($cff, $offset);

        if ($offset === null) {
            return null;
        }

        return self::firstIndexEntry($cff, $offset);
    }

    /**
     * An INDEX is a count, an offset size, count + 1 offsets, then the
     * data they point into.
     */
    private static function skipIndex(string $cff, int $offset): ?int
    {
        $end = self::indexEnd($cff, $offset);

        return $end;
    }

    private static function firstIndexEntry(string $cff, int $offset): ?string
    {
        if ($offset + 2 > strlen($cff)) {
            return null;
        }

        $count = (ord($cff[$offset]) << 8) | ord($cff[$offset + 1]);

        if ($count === 0) {
            return null;
        }

        $offsetSize = ord($cff[$offset + 2]);

        if ($offsetSize < 1 || $offsetSize > 4) {
            return null;
        }

        $offsets = $offset + 3;
        $data = $offsets + ($count + 1) * $offsetSize - 1;

        $first = self::number($cff, $offsets, $offsetSize);
        $second = self::number($cff, $offsets + $offsetSize, $offsetSize);

        if ($first === null || $second === null || $second < $first || $data + $second > strlen($cff)) {
            return null;
        }

        return substr($cff, $data + $first, $second - $first);
    }

    private static function indexEnd(string $cff, int $offset): ?int
    {
        if ($offset + 2 > strlen($cff)) {
            return null;
        }

        $count = (ord($cff[$offset]) << 8) | ord($cff[$offset + 1]);

        // An empty INDEX is its count and nothing else.
        if ($count === 0) {
            return $offset + 2;
        }

        $offsetSize = ord($cff[$offset + 2]);

        if ($offsetSize < 1 || $offsetSize > 4) {
            return null;
        }

        $offsets = $offset + 3;
        $last = self::number($cff, $offsets + $count * $offsetSize, $offsetSize);

        if ($last === null) {
            return null;
        }

        return $offsets + ($count + 1) * $offsetSize - 1 + $last;
    }

    /**
     * How many bytes the operand at $i occupies, including its own first
     * byte -- CFF's DICT operands say their length in their first byte
     * (Adobe's CFF specification, table 3).
     */
    private static function operandLength(string $dict, int $i): int
    {
        $byte = ord($dict[$i]);

        return match (true) {
            $byte >= 32 && $byte <= 246 => 1,
            $byte >= 247 && $byte <= 254 => 2,
            $byte === 28 => 3,
            $byte === 29 => 5,
            $byte === 30 => self::realLength($dict, $i),
            // An operator, or a byte that means nothing here: either way
            // one byte, and the scan carries on.
            default => 1,
        };
    }

    /** A real number runs to the nibble 0xF that ends it. */
    private static function realLength(string $dict, int $i): int
    {
        for ($j = $i + 1, $length = strlen($dict); $j < $length; ++$j) {
            $byte = ord($dict[$j]);

            if (($byte & 0x0F) === 0x0F || ($byte >> 4) === 0x0F) {
                return $j - $i + 1;
            }
        }

        return $length - $i;
    }

    private static function number(string $bytes, int $offset, int $size): ?int
    {
        if ($offset + $size > strlen($bytes)) {
            return null;
        }

        $number = 0;

        for ($i = 0; $i < $size; ++$i) {
            $number = ($number << 8) | ord($bytes[$offset + $i]);
        }

        return $number;
    }
}
