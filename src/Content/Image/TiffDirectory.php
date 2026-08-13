<?php

declare(strict_types=1);

namespace MightyPDF\Content\Image;

/**
 * One image directory (IFD) out of a TIFF file, read into the tags this
 * library acts on.
 *
 * TIFF is a container rather than a format: a file is a list of tagged
 * fields, and what the pixels mean is assembled from a dozen of them that
 * may appear in any order and mostly have defaults. That is why "read a
 * TIFF" is not one decoder but a small dispatch, and why this exists
 * separately from the code that turns the result into a PDF image.
 *
 * A file may hold several images -- a multi-page fax, a scanned document
 * -- chained one directory to the next, which is what all() walks.
 */
final class TiffDirectory
{
    /**
     * A file claiming more pages than this is not a scan, it is a loop or
     * an attempt to make a reader walk one.
     */
    private const int MAX_PAGES = 4_096;

    /** How many entries one directory may have before it stops being one. */
    private const int MAX_ENTRIES = 4_096;

    /**
     * How many tag values the whole file may yield, across every entry of
     * every directory.
     *
     * The per-entry bounds below are each correct and together are not a
     * limit at all: every entry is checked against the length of the file,
     * and nothing stops four thousand of them pointing at the same
     * megabyte. Read that way a 1 MB file yields four billion integers,
     * which is tens of gigabytes of PHP array -- the amplification does
     * not need a large file, only a repetitive one.
     *
     * A million is far past anything real. The tags with many values are
     * /StripOffsets and /StripByteCounts, one pair per strip, and an image
     * in a million strips is not an image.
     */
    private const int MAX_TOTAL_VALUES = 1_048_576;

    /** @param array<int, list<int>> $tags tag number => its values */
    private function __construct(
        private readonly array $tags,
        private readonly string $bytes,
        private readonly bool $littleEndian,
    ) {
    }

    /**
     * Every image in the file, in order.
     *
     * @return list<self>
     */
    public static function all(string $bytes): array
    {
        if (strlen($bytes) < 8) {
            throw new \InvalidArgumentException('This is too short to be a TIFF file.');
        }

        $order = substr($bytes, 0, 2);

        $littleEndian = match ($order) {
            'II' => true,
            'MM' => false,
            default => throw new \InvalidArgumentException(
                'This is not a TIFF file: it begins with neither "II" nor "MM".',
            ),
        };

        $magic = self::short($bytes, 2, $littleEndian);

        if ($magic !== 42) {
            throw new \InvalidArgumentException(sprintf(
                'This is not a TIFF file: the magic number is %d rather than 42.%s',
                $magic,
                $magic === 43 ? ' (43 is BigTIFF, which is a different format.)' : '',
            ));
        }

        $offset = self::long($bytes, 4, $littleEndian);
        $directories = [];
        $seen = [];

        // Spent down across every directory in the file rather than reset
        // per directory: the chain is as good a place to multiply as the
        // entry list is.
        $budget = self::MAX_TOTAL_VALUES;

        while ($offset !== 0 && count($directories) < self::MAX_PAGES) {
            // A directory chain that revisits an offset is a cycle, and a
            // file is free to contain one.
            if (isset($seen[$offset]) || $offset + 2 > strlen($bytes)) {
                break;
            }

            $seen[$offset] = true;

            [$tags, $next] = self::readDirectory($bytes, $offset, $littleEndian, $budget);
            $directories[] = new self($tags, $bytes, $littleEndian);
            $offset = $next;
        }

        if ($directories === []) {
            throw new \InvalidArgumentException('This TIFF file has no image directories in it.');
        }

        return $directories;
    }

    /** The first value of a tag, or $default if it is not there. */
    public function value(int $tag, int $default): int
    {
        return $this->tags[$tag][0] ?? $default;
    }

    /** @return list<int> */
    public function values(int $tag): array
    {
        return $this->tags[$tag] ?? [];
    }

    public function has(int $tag): bool
    {
        return isset($this->tags[$tag]);
    }

    /**
     * The bytes of one strip, by index.
     *
     * Bounds-checked against the file rather than trusted: the offsets and
     * lengths are numbers in the file, and a file is free to say its strip
     * runs a gigabyte past the end.
     */
    public function strip(int $index): string
    {
        $offsets = $this->values(TiffTag::StripOffsets->value);
        $counts = $this->values(TiffTag::StripByteCounts->value);

        $offset = $offsets[$index] ?? null;
        $length = $counts[$index] ?? null;

        if ($offset === null || $length === null || $offset < 0 || $length < 0) {
            return '';
        }

        if ($offset >= strlen($this->bytes)) {
            return '';
        }

        return substr($this->bytes, $offset, min($length, strlen($this->bytes) - $offset));
    }

    public function stripCount(): int
    {
        return count($this->values(TiffTag::StripOffsets->value));
    }

    /** Every strip run together, which for most files is the one strip. */
    public function pixels(): string
    {
        $out = '';

        for ($index = 0; $index < $this->stripCount(); ++$index) {
            $out .= $this->strip($index);
        }

        return $out;
    }

    /**
     * @param int $budget how many values may still be read out of this
     *        file, spent down as they are -- see MAX_TOTAL_VALUES
     *
     * @return array{array<int, list<int>>, int} the tags, and where the
     *         next directory starts (0 for none)
     */
    private static function readDirectory(string $bytes, int $offset, bool $littleEndian, int &$budget): array
    {
        $count = min(self::short($bytes, $offset, $littleEndian), self::MAX_ENTRIES);
        $tags = [];

        for ($index = 0; $index < $count; ++$index) {
            $at = $offset + 2 + $index * 12;

            if ($at + 12 > strlen($bytes)) {
                break;
            }

            $tag = self::short($bytes, $at, $littleEndian);
            $type = self::short($bytes, $at + 2, $littleEndian);
            $length = self::long($bytes, $at + 4, $littleEndian);

            $values = self::readValues($bytes, $at + 8, $type, $length, $littleEndian, $budget);

            if ($values !== []) {
                $tags[$tag] = $values;
            }
        }

        $nextAt = $offset + 2 + $count * 12;
        $next = $nextAt + 4 <= strlen($bytes) ? self::long($bytes, $nextAt, $littleEndian) : 0;

        return [$tags, $next];
    }

    /**
     * One field's values.
     *
     * A field's data sits inline when it fits in the four bytes the entry
     * has for it, and at an offset when it does not -- which is the one
     * piece of TIFF that catches every first implementation.
     *
     * @return list<int>
     */
    private static function readValues(
        string $bytes,
        int $at,
        int $type,
        int $length,
        bool $littleEndian,
        int &$budget,
    ): array {
        $width = match ($type) {
            1, 2, 6, 7 => 1,   // BYTE, ASCII, SBYTE, UNDEFINED
            3, 8 => 2,         // SHORT, SSHORT
            4, 9 => 4,         // LONG, SLONG
            5, 10 => 8,        // RATIONAL, SRATIONAL
            11 => 4,           // FLOAT
            12 => 8,           // DOUBLE
            default => 0,
        };

        if ($width === 0 || $length <= 0) {
            return [];
        }

        // Guarded before multiplying: a length of 2^31 times eight bytes
        // is a number this should not be computing with.
        if ($length > self::MAX_ENTRIES * 256) {
            $length = self::MAX_ENTRIES * 256;
        }

        // Dropped rather than truncated. Half a tag's values is not a
        // shorter answer to what the tag says, it is a wrong one -- a
        // /StripOffsets missing its tail would place the image out of a
        // file that has already been established as hostile.
        if ($length > $budget) {
            return [];
        }

        $total = $length * $width;
        $from = $total <= 4 ? $at : self::long($bytes, $at, $littleEndian);

        if ($from < 0 || $from + $total > strlen($bytes)) {
            return [];
        }

        $budget -= $length;
        $values = [];

        for ($index = 0; $index < $length; ++$index) {
            $position = $from + $index * $width;

            $values[] = match ($width) {
                1 => ord($bytes[$position]),
                2 => self::short($bytes, $position, $littleEndian),
                default => self::long($bytes, $position, $littleEndian),
            };
        }

        return $values;
    }

    private static function short(string $bytes, int $at, bool $littleEndian): int
    {
        if ($at + 2 > strlen($bytes)) {
            return 0;
        }

        $value = unpack($littleEndian ? 'v' : 'n', substr($bytes, $at, 2));

        return $value === false ? 0 : $value[1];
    }

    private static function long(string $bytes, int $at, bool $littleEndian): int
    {
        if ($at + 4 > strlen($bytes)) {
            return 0;
        }

        $value = unpack($littleEndian ? 'V' : 'N', substr($bytes, $at, 4));

        return $value === false ? 0 : $value[1];
    }
}
