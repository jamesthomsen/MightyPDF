<?php

declare(strict_types=1);

namespace MightyPDF\Content\Barcode;

/**
 * Data Matrix, ECC200 (ISO/IEC 16022).
 *
 * The 2D symbology of small things: a component the size of a fingernail,
 * a vial, a postal item, a form field that has to survive a fax. It packs
 * more into a small area than QR does, reads at lower contrast, and unlike
 * QR it does not need a quiet zone on all four sides to be found -- the
 * solid "L" down two sides is the finder, and the alternating modules on
 * the other two are the clock a scanner counts modules against.
 *
 * **Encoding is ASCII mode throughout.** The standard also defines C40,
 * Text, X12, EDIFACT and Base 256 modes, which pack uppercase text more
 * tightly (three characters per two codewords against ASCII's one per
 * one). ASCII mode encodes anything -- and encodes *digits* at two per
 * codeword, which is the case that actually dominates, since the things
 * Data Matrix is printed on are mostly numbered rather than described. So
 * a symbol here is exactly the size a numeric one should be, and up to
 * about a third larger than it could be for a long run of letters. That is
 * the same trade this library makes in Code 128 and in QR: conforming and
 * predictable, occasionally not minimal.
 *
 * Nothing here draws. encode() returns a ModuleGrid and
 * PageBuilder::drawDataMatrix() puts it on a page.
 */
final class DataMatrix
{
    /**
     * The quiet zone, in modules, on every side.
     *
     * One module, and it is not optional: §16022 7.1 requires it, and it
     * is the smallest quiet zone of any common symbology precisely because
     * the finder does most of the work. A symbol printed hard against
     * other content still fails to read.
     */
    public const int QUIET_ZONE_MODULES = 1;

    /** Latch to the upper half of Latin-1, for one character (§5.2.3). */
    private const int UPPER_SHIFT = 235;

    /** The first pad codeword; the rest are randomised from it. */
    private const int PAD = 129;

    private function __construct()
    {
    }

    /** @param DataMatrixShape $shape square or rectangular -- see the enum. */
    public static function encode(string $value, DataMatrixShape $shape = DataMatrixShape::Square): ModuleGrid
    {
        if ($value === '') {
            throw new \InvalidArgumentException('A Data Matrix has to encode something; the value is empty.');
        }

        $codewords = self::toCodewords($value);
        $size = DataMatrixSize::smallestFor(count($codewords), $shape)
            ?? throw new \InvalidArgumentException(sprintf(
                'This value needs %d codewords and the largest %s Data Matrix holds %d. Shorten it%s.',
                count($codewords),
                $shape === DataMatrixShape::Square ? 'square' : 'rectangular',
                DataMatrixSize::largestCapacity($shape),
                $shape === DataMatrixShape::Rectangular
                    ? ', or use a square, which goes up to ' . DataMatrixSize::largestCapacity()
                    : '',
            ));

        $codewords = self::pad($codewords, $size->dataCodewords);

        return self::draw($size, self::withErrorCorrection($codewords, $size));
    }

    /**
     * The value as ASCII-mode codewords, before padding.
     *
     * @return list<int>
     */
    public static function toCodewords(string $value): array
    {
        $codewords = [];
        $length = strlen($value);

        for ($i = 0; $i < $length; ++$i) {
            $byte = ord($value[$i]);

            // A pair of digits goes in one codeword, which is the whole
            // reason ASCII mode is competitive: 130 + the two-digit number.
            if ($i + 1 < $length && self::isDigit($byte) && self::isDigit(ord($value[$i + 1]))) {
                $codewords[] = 130 + (int) substr($value, $i, 2);
                ++$i;

                continue;
            }

            if ($byte < 128) {
                // ASCII is offset by one because 0 is not a codeword.
                $codewords[] = $byte + 1;

                continue;
            }

            // Latin-1's upper half: a shift, then the character with its
            // top bit cleared, offset the same way.
            $codewords[] = self::UPPER_SHIFT;
            $codewords[] = $byte - 128 + 1;
        }

        return $codewords;
    }

    /**
     * Fills the block out to the symbol's capacity.
     *
     * The first pad is 129 and every one after it is scrambled by position
     * (§5.2.4.2, algorithm 253-state). That looks like pointless
     * complication and is not: a run of identical pad codewords would
     * produce a large regular block of modules, and a regular block is
     * exactly what a scanner's finder logic mistakes for a finder pattern.
     *
     * @param list<int> $codewords
     * @return list<int>
     */
    private static function pad(array $codewords, int $capacity): array
    {
        if (count($codewords) >= $capacity) {
            return $codewords;
        }

        $codewords[] = self::PAD;

        while (count($codewords) < $capacity) {
            // The position is 1-based and counts the pad being placed.
            $position = count($codewords) + 1;
            $scrambled = self::PAD + (((149 * $position) % 253) + 1);

            $codewords[] = $scrambled > 254 ? $scrambled - 254 : $scrambled;
        }

        return $codewords;
    }

    /**
     * Appends the Reed-Solomon check codewords.
     *
     * Above 52x52 the codewords are split into several blocks, each with
     * its own check codewords, and the blocks are *interleaved* in the
     * symbol. That is not for capacity -- it is so that a scratch across
     * the symbol damages a few codewords of every block rather than
     * destroying one block outright, which is the failure a single block
     * cannot correct however many check codewords it has.
     *
     * @param list<int> $data exactly $size->dataCodewords of them
     * @return list<int> data followed by error correction
     */
    private static function withErrorCorrection(array $data, DataMatrixSize $size): array
    {
        $blocks = $size->blocks;
        $eccPerBlock = intdiv($size->eccCodewords, $blocks);
        $checks = [];

        for ($block = 0; $block < $blocks; ++$block) {
            $slice = [];

            for ($i = $block; $i < count($data); $i += $blocks) {
                $slice[] = $data[$i];
            }

            $checks[$block] = ReedSolomon::remainder(
                $slice,
                $eccPerBlock,
                ReedSolomon::DATA_MATRIX_PRIMITIVE,
                firstRoot: 2,
            );
        }

        $out = $data;

        for ($i = 0; $i < $eccPerBlock; ++$i) {
            for ($block = 0; $block < $blocks; ++$block) {
                $out[] = $checks[$block][$i];
            }
        }

        return $out;
    }

    /**
     * Lays the codewords into the mapping matrix and wraps it in finder
     * patterns.
     *
     * @param list<int> $codewords
     */
    private static function draw(DataMatrixSize $size, array $codewords): ModuleGrid
    {
        $mapping = DataMatrixPlacement::place($size->mappingRows(), $size->mappingColumns(), $codewords);

        $regionHeight = intdiv($size->mappingRows(), $size->regionRows);
        $regionWidth = intdiv($size->mappingColumns(), $size->regionColumns);

        $rows = [];

        for ($y = 0; $y < $size->rows; ++$y) {
            $row = [];

            for ($x = 0; $x < $size->columns; ++$x) {
                // Which region this module is in, and where inside it.
                $localY = $y % ($regionHeight + 2);
                $localX = $x % ($regionWidth + 2);

                $row[] = match (true) {
                    // The solid L: left edge and bottom edge of the region.
                    $localX === 0, $localY === $regionHeight + 1 => true,
                    // The clock track: alternating along the top and the
                    // right edge, dark at the corner each shares with the L.
                    $localY === 0 => $localX % 2 === 0,
                    $localX === $regionWidth + 1 => $localY % 2 === 1,
                    default => $mapping
                        [intdiv($y, $regionHeight + 2) * $regionHeight + $localY - 1]
                        [intdiv($x, $regionWidth + 2) * $regionWidth + $localX - 1],
                };
            }

            $rows[] = $row;
        }

        return ModuleGrid::of($rows);
    }

    private static function isDigit(int $byte): bool
    {
        return $byte >= 0x30 && $byte <= 0x39;
    }
}
