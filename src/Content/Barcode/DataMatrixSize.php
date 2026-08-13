<?php

declare(strict_types=1);

namespace MightyPDF\Content\Barcode;

/**
 * One of the symbol sizes ECC200 defines (ISO/IEC 16022 Table 7).
 *
 * A Data Matrix is not any size that fits: it is one of twenty-four
 * squares or six rectangles, each with a fixed split between data and
 * error-correction codewords. Choosing is therefore picking the smallest
 * entry in this table that holds the data, not computing a size. (One of
 * the twenty-four is deliberately not offered here -- see all().)
 *
 * $regions is how many data regions the symbol is divided into per side.
 * Above 26x26 a symbol is not one grid but several, each with its own
 * finder pattern, which is what keeps the alignment references close
 * enough together for a scanner to track across a large symbol. It is
 * also why the mapping matrix is smaller than the symbol by two modules
 * per region rather than by two overall.
 */
final readonly class DataMatrixSize
{
    /**
     * @param int $rows total symbol height in modules, finder patterns included
     * @param int $columns total symbol width in modules
     * @param int $dataCodewords how much data it holds
     * @param int $eccCodewords the Reed-Solomon check codewords that follow
     * @param int $blocks how many interleaved error-correction blocks the
     *        codewords are split into -- one for every symbol small enough
     *        that a single block stays inside the field's correction limit
     * @param int $regionRows data regions down the symbol
     * @param int $regionColumns data regions across it
     */
    private function __construct(
        public int $rows,
        public int $columns,
        public int $dataCodewords,
        public int $eccCodewords,
        public int $blocks,
        public int $regionRows,
        public int $regionColumns,
    ) {
    }

    public function isSquare(): bool
    {
        return $this->rows === $this->columns;
    }

    /** The height of the bit-placement matrix: the symbol less its finders. */
    public function mappingRows(): int
    {
        return $this->rows - 2 * $this->regionRows;
    }

    public function mappingColumns(): int
    {
        return $this->columns - 2 * $this->regionColumns;
    }

    /**
     * Every size this library produces, smallest first, so that choosing
     * one is a scan rather than a search.
     *
     * **144x144 is deliberately absent**, and it is the only one. It is
     * the single size whose ten error-correction blocks are not all the
     * same shape -- eight hold 156 data codewords and two hold 155 -- and
     * the interleaving that goes with that is not the one every other
     * multi-block size uses. Producing it on the strength of a guess would
     * mean shipping a symbol that is the right shape, has the right finder
     * patterns, and cannot be read; the sizes here are all checked against
     * a reference decoder, and this one could not be made to pass. What is
     * lost is the difference between 1304 and 1558 data codewords at the
     * very top of the range, and a caller who is genuinely against that
     * ceiling wants two symbols rather than the largest one there is.
     *
     * @return list<self>
     */
    public static function all(): array
    {
        static $sizes = null;

        return $sizes ??= array_map(
            static fn (array $row): self => new self(...$row),
            [
                // rows, cols, data, ecc, blocks, region rows, region cols
                [10, 10, 3, 5, 1, 1, 1],
                [12, 12, 5, 7, 1, 1, 1],
                [8, 18, 5, 7, 1, 1, 1],
                [14, 14, 8, 10, 1, 1, 1],
                [8, 32, 10, 11, 1, 1, 2],
                [16, 16, 12, 12, 1, 1, 1],
                [12, 26, 16, 14, 1, 1, 1],
                [18, 18, 18, 14, 1, 1, 1],
                [20, 20, 22, 18, 1, 1, 1],
                [12, 36, 22, 18, 1, 1, 2],
                [22, 22, 30, 20, 1, 1, 1],
                [16, 36, 32, 24, 1, 1, 2],
                [24, 24, 36, 24, 1, 1, 1],
                [26, 26, 44, 28, 1, 1, 1],
                [16, 48, 49, 28, 1, 1, 2],
                [32, 32, 62, 36, 1, 2, 2],
                [36, 36, 86, 42, 1, 2, 2],
                [40, 40, 114, 48, 1, 2, 2],
                [44, 44, 144, 56, 1, 2, 2],
                [48, 48, 174, 68, 1, 2, 2],
                [52, 52, 204, 84, 2, 2, 2],
                [64, 64, 280, 112, 2, 4, 4],
                [72, 72, 368, 144, 4, 4, 4],
                [80, 80, 456, 192, 4, 4, 4],
                [88, 88, 576, 224, 4, 4, 4],
                [96, 96, 696, 272, 4, 4, 4],
                [104, 104, 816, 336, 6, 4, 4],
                [120, 120, 1050, 408, 6, 6, 6],
                [132, 132, 1304, 496, 8, 6, 6],
            ],
        );
    }

    /** The smallest size of the requested shape that holds $codewords. */
    public static function smallestFor(int $codewords, DataMatrixShape $shape = DataMatrixShape::Square): ?self
    {
        foreach (self::all() as $size) {
            if ($size->isSquare() !== ($shape === DataMatrixShape::Square)) {
                continue;
            }

            if ($size->dataCodewords >= $codewords) {
                return $size;
            }
        }

        return null;
    }

    /** The largest capacity of that shape, for reporting what would have fitted. */
    public static function largestCapacity(DataMatrixShape $shape = DataMatrixShape::Square): int
    {
        $best = 0;

        foreach (self::all() as $size) {
            if ($size->isSquare() === ($shape === DataMatrixShape::Square)) {
                $best = max($best, $size->dataCodewords);
            }
        }

        return $best;
    }
}
