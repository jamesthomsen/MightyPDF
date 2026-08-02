<?php

declare(strict_types=1);

namespace MightyPDF\Content\Font\TrueType;

/**
 * Bounds-checked reads of the big-endian integer types an sfnt font file
 * is made of.
 *
 * Every read goes through here rather than through bare unpack() calls at
 * each parse site, for the same reason the reader's Lexer exists: a font
 * file is external input, and unpack() past the end of a string does not
 * fail loudly -- it returns a short array, and the missing element reads
 * as null, which then quietly becomes 0. A truncated table would parse as
 * a font with zero glyphs rather than as a broken file.
 */
final class SfntReader
{
    public function __construct(private readonly string $bytes)
    {
    }

    public function length(): int
    {
        return strlen($this->bytes);
    }

    public function uint8(int $offset): int
    {
        return ord($this->slice($offset, 1));
    }

    public function uint16(int $offset): int
    {
        $values = unpack('n', $this->slice($offset, 2));

        return $values[1];
    }

    /** A signed 16-bit value -- glyph coordinates, ascenders, and deltas are all signed. */
    public function int16(int $offset): int
    {
        $value = $this->uint16($offset);

        return $value >= 0x8000 ? $value - 0x10000 : $value;
    }

    public function uint32(int $offset): int
    {
        $values = unpack('N', $this->slice($offset, 4));

        return $values[1];
    }

    /**
     * A 16.16 fixed-point number (post's italic angle, the sfnt version),
     * as a float. The fractional half is unsigned even when the whole is
     * negative, which is why this splits rather than dividing the raw
     * 32-bit value: -0.5 is stored as 0xFFFF8000, and reading that as a
     * signed integer and dividing by 65536 gives -0.5 only by accident of
     * two's complement -- true here, but not for the sign-magnitude
     * reading people reach for first.
     */
    public function fixed(int $offset): float
    {
        return $this->int16($offset) + $this->uint16($offset + 2) / 65536.0;
    }

    public function tag(int $offset): string
    {
        return $this->slice($offset, 4);
    }

    public function slice(int $offset, int $length): string
    {
        if ($offset < 0 || $length < 0 || $offset + $length > strlen($this->bytes)) {
            throw new FontException(sprintf(
                'Font data ends early: wanted %d byte(s) at offset %d, file holds %d.',
                $length,
                $offset,
                strlen($this->bytes),
            ));
        }

        return substr($this->bytes, $offset, $length);
    }

    /** Whether $length bytes starting at $offset are actually present. */
    public function has(int $offset, int $length): bool
    {
        return $offset >= 0 && $length >= 0 && $offset + $length <= strlen($this->bytes);
    }
}
