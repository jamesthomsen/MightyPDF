<?php

declare(strict_types=1);

namespace MightyPDF\Content\Barcode;

/**
 * Where each bit of each codeword goes in a Data Matrix (ISO/IEC 16022
 * Annex F).
 *
 * A codeword is not eight modules in a row. It is an L-shaped block of
 * eight that walks the matrix diagonally, wrapping around the edges, so
 * that the bits of any one codeword end up spread across the symbol.
 * That is deliberate and it is what makes the error correction worth
 * having: damage in a real symbol is a smudge or a scratch, which is
 * local, and a local injury has to be spread across many codewords before
 * a code that corrects a few codewords can do anything about it.
 *
 * The algorithm is transcribed from the standard's own pseudocode rather
 * than reasoned out, including the four corner cases, which exist because
 * the diagonal walk does not meet the matrix edges cleanly at every size.
 * There is no derivation to follow -- they are four specific patterns for
 * four specific shapes, and the standard states them as such.
 */
final class DataMatrixPlacement
{
    private function __construct()
    {
    }

    /**
     * @param list<int> $codewords
     * @return list<list<bool>> [row][column] of the mapping matrix
     */
    public static function place(int $rows, int $columns, array $codewords): array
    {
        /** @var list<list<bool|null>> $matrix */
        $matrix = array_fill(0, $rows, array_fill(0, $columns, null));

        $codeword = 0;
        $row = 4;
        $column = 0;

        do {
            // The corners, checked before each pass of the walk.
            if ($row === $rows && $column === 0) {
                self::cornerA($matrix, $rows, $columns, $codewords[$codeword++] ?? 0);
            } elseif ($row === $rows - 2 && $column === 0 && ($columns % 4) !== 0) {
                self::cornerB($matrix, $rows, $columns, $codewords[$codeword++] ?? 0);
            } elseif ($row === $rows - 2 && $column === 0 && ($columns % 8) === 4) {
                self::cornerC($matrix, $rows, $columns, $codewords[$codeword++] ?? 0);
            } elseif ($row === $rows + 4 && $column === 2 && ($columns % 8) === 0) {
                self::cornerD($matrix, $rows, $columns, $codewords[$codeword++] ?? 0);
            }

            // Up and to the right.
            do {
                if ($row < $rows && $column >= 0 && $matrix[$row][$column] === null) {
                    self::block($matrix, $rows, $columns, $row, $column, $codewords[$codeword++] ?? 0);
                }

                $row -= 2;
                $column += 2;
            } while ($row >= 0 && $column < $columns);

            ++$row;
            $column += 3;

            // Down and to the left.
            do {
                if ($row >= 0 && $column < $columns && $matrix[$row][$column] === null) {
                    self::block($matrix, $rows, $columns, $row, $column, $codewords[$codeword++] ?? 0);
                }

                $row += 2;
                $column -= 2;
            } while ($row < $rows && $column >= 0);

            $row += 3;
            ++$column;
        } while ($row < $rows || $column < $columns);

        // The bottom-right 2x2 is left over at some sizes, and the
        // standard fills it with a fixed checkerboard rather than data --
        // there is no codeword left to put there.
        if ($matrix[$rows - 1][$columns - 1] === null) {
            $matrix[$rows - 1][$columns - 1] = true;
            $matrix[$rows - 2][$columns - 2] = true;
            $matrix[$rows - 1][$columns - 2] = false;
            $matrix[$rows - 2][$columns - 1] = false;
        }

        return array_map(
            static fn (array $row): array => array_map(
                static fn (?bool $module): bool => $module ?? false,
                $row,
            ),
            $matrix,
        );
    }

    /**
     * One codeword's eight bits, as the L-shaped block of Figure F.1.
     *
     * @param list<list<bool|null>> $matrix
     */
    private static function block(
        array &$matrix,
        int $rows,
        int $columns,
        int $row,
        int $column,
        int $codeword,
    ): void {
        self::module($matrix, $rows, $columns, $row - 2, $column - 2, $codeword, 1);
        self::module($matrix, $rows, $columns, $row - 2, $column - 1, $codeword, 2);
        self::module($matrix, $rows, $columns, $row - 1, $column - 2, $codeword, 3);
        self::module($matrix, $rows, $columns, $row - 1, $column - 1, $codeword, 4);
        self::module($matrix, $rows, $columns, $row - 1, $column, $codeword, 5);
        self::module($matrix, $rows, $columns, $row, $column - 2, $codeword, 6);
        self::module($matrix, $rows, $columns, $row, $column - 1, $codeword, 7);
        self::module($matrix, $rows, $columns, $row, $column, $codeword, 8);
    }

    /**
     * One bit, with the standard's wrap-around.
     *
     * A negative coordinate does not clamp and does not mean "skip": it
     * wraps to the far side *and shifts along the other axis*, which is
     * what makes the placement tile the matrix without leaving holes. The
     * shift constants are the standard's.
     *
     * @param list<list<bool|null>> $matrix
     * @param int $bit 1 is the most significant
     */
    private static function module(
        array &$matrix,
        int $rows,
        int $columns,
        int $row,
        int $column,
        int $codeword,
        int $bit,
    ): void {
        if ($row < 0) {
            $row += $rows;
            $column += 4 - (($rows + 4) % 8);
        }

        if ($column < 0) {
            $column += $columns;
            $row += 4 - (($columns + 4) % 8);
        }

        $matrix[$row][$column] = (($codeword >> (8 - $bit)) & 1) === 1;
    }

    /** @param list<list<bool|null>> $matrix */
    private static function cornerA(array &$matrix, int $rows, int $columns, int $codeword): void
    {
        self::at($matrix, $rows - 1, 0, $codeword, 1);
        self::at($matrix, $rows - 1, 1, $codeword, 2);
        self::at($matrix, $rows - 1, 2, $codeword, 3);
        self::at($matrix, 0, $columns - 2, $codeword, 4);
        self::at($matrix, 0, $columns - 1, $codeword, 5);
        self::at($matrix, 1, $columns - 1, $codeword, 6);
        self::at($matrix, 2, $columns - 1, $codeword, 7);
        self::at($matrix, 3, $columns - 1, $codeword, 8);
    }

    /** @param list<list<bool|null>> $matrix */
    private static function cornerB(array &$matrix, int $rows, int $columns, int $codeword): void
    {
        self::at($matrix, $rows - 3, 0, $codeword, 1);
        self::at($matrix, $rows - 2, 0, $codeword, 2);
        self::at($matrix, $rows - 1, 0, $codeword, 3);
        self::at($matrix, 0, $columns - 4, $codeword, 4);
        self::at($matrix, 0, $columns - 3, $codeword, 5);
        self::at($matrix, 0, $columns - 2, $codeword, 6);
        self::at($matrix, 0, $columns - 1, $codeword, 7);
        self::at($matrix, 1, $columns - 1, $codeword, 8);
    }

    /** @param list<list<bool|null>> $matrix */
    private static function cornerC(array &$matrix, int $rows, int $columns, int $codeword): void
    {
        self::at($matrix, $rows - 3, 0, $codeword, 1);
        self::at($matrix, $rows - 2, 0, $codeword, 2);
        self::at($matrix, $rows - 1, 0, $codeword, 3);
        self::at($matrix, 0, $columns - 2, $codeword, 4);
        self::at($matrix, 0, $columns - 1, $codeword, 5);
        self::at($matrix, 1, $columns - 1, $codeword, 6);
        self::at($matrix, 2, $columns - 1, $codeword, 7);
        self::at($matrix, 3, $columns - 1, $codeword, 8);
    }

    /** @param list<list<bool|null>> $matrix */
    private static function cornerD(array &$matrix, int $rows, int $columns, int $codeword): void
    {
        self::at($matrix, $rows - 1, 0, $codeword, 1);
        self::at($matrix, $rows - 1, $columns - 1, $codeword, 2);
        self::at($matrix, 0, $columns - 3, $codeword, 3);
        self::at($matrix, 0, $columns - 2, $codeword, 4);
        self::at($matrix, 0, $columns - 1, $codeword, 5);
        self::at($matrix, 1, $columns - 3, $codeword, 6);
        self::at($matrix, 1, $columns - 2, $codeword, 7);
        self::at($matrix, 1, $columns - 1, $codeword, 8);
    }

    /**
     * A corner-case bit. No wrapping: the corner patterns name absolute
     * positions, which is the point of their being special cases.
     *
     * @param list<list<bool|null>> $matrix
     */
    private static function at(array &$matrix, int $row, int $column, int $codeword, int $bit): void
    {
        $matrix[$row][$column] = (($codeword >> (8 - $bit)) & 1) === 1;
    }
}
