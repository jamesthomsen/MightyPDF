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
    /** The five filters, in the order the PNG spec numbers them (§9.2). */
    private const int NONE = 0;
    private const int SUB = 1;
    private const int UP = 2;
    private const int AVERAGE = 3;
    private const int PAETH = 4;

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
     *
     * One method per filter rather than one loop that asks which filter
     * it is, because it is asked once per *byte* and answered the same
     * way every time: a megapixel image with an alpha channel is four
     * million bytes, and the branch, the two bounds checks and the
     * ord()/chr() pair around each of them were together most of what it
     * cost to embed one. Measured on a 1200x900 RGBA PNG, undoing the
     * filters took 851 ms of the 1054 ms the whole embed took; splitting
     * the loops and working on unpack()ed integers rather than on
     * single-byte strings cut that by between 1.8x (Paeth) and 3.3x
     * (Up), with None becoming free.
     *
     * It is the same arithmetic in five copies, which is a real cost to
     * pay for speed. It is worth it here and would not be in most
     * places: this is the innermost loop in the library, the five
     * filters are fixed by a spec that has not changed since 1996, and
     * the tests exercise each one against the general formula it came
     * from.
     *
     * The other caller of this benefits as much: PDF's /Predictor 10-15
     * is these same filters, so every cross-reference or object stream
     * in a modern PDF is unfiltered through here when the file is
     * opened.
     */
    public static function reconstructRow(int $filterType, string $row, string $previousRow, int $bytesPerPixel): ?string
    {
        if ($filterType < 0 || $filterType > self::PAETH) {
            return null;
        }

        // None predicts nothing, so the row is already what it says.
        // Worth its own case rather than a loop that adds zero: it is
        // what an image saved without filtering uses for every row.
        if ($filterType === self::NONE || $row === '') {
            return $row;
        }

        /** @var list<int> $raw */
        $raw = array_values((array) unpack('C*', $row));
        /** @var list<int> $up */
        $up = array_values((array) unpack('C*', $previousRow));

        $out = match ($filterType) {
            self::SUB => self::undoSub($raw, $bytesPerPixel),
            self::UP => self::undoUp($raw, $up),
            self::AVERAGE => self::undoAverage($raw, $up, $bytesPerPixel),
            default => self::undoPaeth($raw, $up, $bytesPerPixel),
        };

        return pack('C*', ...$out);
    }

    /**
     * Sub: each byte is stored as its difference from the byte one pixel
     * to the left, which is why this reads back out of what it has
     * already written.
     *
     * @param list<int> $raw
     * @return list<int>
     */
    private static function undoSub(array $raw, int $bytesPerPixel): array
    {
        $length = count($raw);
        $out = [];

        for ($i = 0; $i < $bytesPerPixel && $i < $length; ++$i) {
            $out[$i] = $raw[$i];
        }

        for ($i = $bytesPerPixel; $i < $length; ++$i) {
            $out[$i] = ($raw[$i] + $out[$i - $bytesPerPixel]) & 0xFF;
        }

        return $out;
    }

    /**
     * Up: the difference from the byte directly above. Nothing here
     * depends on anything else in this row, which is what makes it the
     * cheapest of the four.
     *
     * @param list<int> $raw
     * @param list<int> $up
     * @return list<int>
     */
    private static function undoUp(array $raw, array $up): array
    {
        $length = count($raw);
        $out = [];

        for ($i = 0; $i < $length; ++$i) {
            $out[$i] = ($raw[$i] + $up[$i]) & 0xFF;
        }

        return $out;
    }

    /**
     * Average: the difference from the mean of the bytes to the left and
     * above, rounded down. A shift rather than intdiv(), which is the
     * same thing on a sum that cannot be negative.
     *
     * @param list<int> $raw
     * @param list<int> $up
     * @return list<int>
     */
    private static function undoAverage(array $raw, array $up, int $bytesPerPixel): array
    {
        $length = count($raw);
        $out = [];

        for ($i = 0; $i < $bytesPerPixel && $i < $length; ++$i) {
            $out[$i] = ($raw[$i] + ($up[$i] >> 1)) & 0xFF;
        }

        for ($i = $bytesPerPixel; $i < $length; ++$i) {
            $out[$i] = ($raw[$i] + (($out[$i - $bytesPerPixel] + $up[$i]) >> 1)) & 0xFF;
        }

        return $out;
    }

    /**
     * Paeth: the difference from whichever of the three neighbours is
     * closest to their linear estimate (PNG spec §9.4), with ties broken
     * left, then up.
     *
     * The predictor is spelled out here rather than called, and abs() is
     * written as a comparison: at four million calls an image, both show
     * up. The leading pixel is the general case with the two missing
     * neighbours taken as zero, which reduces to "up".
     *
     * @param list<int> $raw
     * @param list<int> $up
     * @return list<int>
     */
    private static function undoPaeth(array $raw, array $up, int $bytesPerPixel): array
    {
        $length = count($raw);
        $out = [];

        for ($i = 0; $i < $bytesPerPixel && $i < $length; ++$i) {
            $out[$i] = ($raw[$i] + $up[$i]) & 0xFF;
        }

        for ($i = $bytesPerPixel; $i < $length; ++$i) {
            $left = $out[$i - $bytesPerPixel];
            $above = $up[$i];
            $upLeft = $up[$i - $bytesPerPixel];

            $estimate = $left + $above - $upLeft;
            $toLeft = $estimate > $left ? $estimate - $left : $left - $estimate;
            $toAbove = $estimate > $above ? $estimate - $above : $above - $estimate;
            $toUpLeft = $estimate > $upLeft ? $estimate - $upLeft : $upLeft - $estimate;

            if ($toLeft <= $toAbove && $toLeft <= $toUpLeft) {
                $predictor = $left;
            } else {
                $predictor = $toAbove <= $toUpLeft ? $above : $upLeft;
            }

            $out[$i] = ($raw[$i] + $predictor) & 0xFF;
        }

        return $out;
    }
}
