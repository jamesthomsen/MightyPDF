<?php

declare(strict_types=1);

namespace MightyPDF\Png;

/**
 * Reconstructs one PNG-filtered scanline (PNG spec §9.2: None, Sub, Up,
 * Average, Paeth).
 *
 * The same five filters appear in two unrelated places in this library:
 * in a PNG file's IDAT stream, and in PDF's /Predictor 10-15, which is
 * defined by pointing at PNG (ISO 32000-2 §7.4.4.4). Reader and Content
 * are otherwise independent of each other -- both may depend on
 * Assembler, neither depends on the other -- so this lives in its own
 * leaf namespace that depends on nothing, rather than one of them
 * reaching across into the other.
 *
 * Only the per-row arithmetic is shared, because that is all the two
 * callers genuinely have in common. How rows are framed is not: a PNG
 * image knows its row count up front and treats a short row as a corrupt
 * file, whereas a predicted PDF stream reads rows until the data runs out
 * and pads a short final one. Those policies stay with their callers.
 */
final class ScanlineFilter
{
    private function __construct()
    {
    }

    /**
     * Returns $row with filter $filterType undone, given the already
     * reconstructed row above it ($previousRow, all zero bytes for the
     * first row of an image or of an Adam7 pass) and the distance in
     * bytes to the predicting neighbour to the left.
     *
     * $previousRow must be the same length as $row; $bytesPerPixel is the
     * pixel size in whole bytes, floored at 1 -- at sub-byte bit depths
     * consecutive pixels share a byte and the predictor works on the
     * previous byte.
     *
     * Returns null when $filterType is not one of the five defined
     * filters, leaving the exception to the caller: an unknown filter
     * byte means a broken PNG to one caller and a broken PDF stream to
     * the other, and each reports its own kind of failure.
     */
    public static function reconstructRow(int $filterType, string $row, string $previousRow, int $bytesPerPixel): ?string
    {
        if ($filterType < 0 || $filterType > 4) {
            return null;
        }

        $length = strlen($row);
        $out = '';

        for ($i = 0; $i < $length; ++$i) {
            $raw = ord($row[$i]);
            $left = $i >= $bytesPerPixel ? ord($out[$i - $bytesPerPixel]) : 0;
            $up = ord($previousRow[$i]);
            $upLeft = $i >= $bytesPerPixel ? ord($previousRow[$i - $bytesPerPixel]) : 0;

            $value = match ($filterType) {
                0 => $raw,
                1 => $raw + $left,
                2 => $raw + $up,
                3 => $raw + intdiv($left + $up, 2),
                4 => $raw + self::paeth($left, $up, $upLeft),
            };

            $out .= chr($value & 0xFF);
        }

        return $out;
    }

    /**
     * Of the three neighbours, the one closest to their linear estimate
     * (PNG spec §9.4), with ties broken left, then up.
     */
    private static function paeth(int $a, int $b, int $c): int
    {
        $p = $a + $b - $c;
        $pa = abs($p - $a);
        $pb = abs($p - $b);
        $pc = abs($p - $c);

        if ($pa <= $pb && $pa <= $pc) {
            return $a;
        }

        return $pb <= $pc ? $b : $c;
    }
}
