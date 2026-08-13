<?php

declare(strict_types=1);

namespace MightyPDF\Tests\Content\Barcode;

use MightyPDF\Content\Barcode\DataMatrix;
use MightyPDF\Content\Barcode\DataMatrixShape;
use MightyPDF\Content\Barcode\DataMatrixSize;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The symbols asserted here were checked against libdmtx -- the reference
 * decoder -- module for module, and every size in the table was
 * round-tripped through it. What that buys is that these fixtures are not
 * "what this code produced when it was written": they are what a scanner
 * reads back as the input.
 */
final class DataMatrixTest extends TestCase
{
    public function testEncodesADigitPairPerCodeword(): void
    {
        // 130 + the two-digit number. This is why ASCII mode is worth
        // keeping: numbers are the common payload and they pack two deep.
        self::assertSame([142, 164, 186], DataMatrix::toCodewords('123456'));
    }

    public function testEncodesAsciiOffsetByOne(): void
    {
        // 'A' is 65, 'b' is 98; the odd digit falls back to a single.
        self::assertSame([66, 50, 99], DataMatrix::toCodewords('A1b'));
    }

    public function testShiftsForTheUpperHalfOfLatin1(): void
    {
        // 235 is the upper shift, then the character less 128, plus one.
        self::assertSame([100, 98, 103, 235, 106], DataMatrix::toCodewords("caf\xe9"));
    }

    public function testAnOddTrailingDigitIsNotPaired(): void
    {
        self::assertSame([142, 52], DataMatrix::toCodewords('123'));
    }

    public function testProducesTheKnownSymbolForDigits(): void
    {
        self::assertSame(
            [
                '1010101010',
                '1100101101',
                '1100000100',
                '1100011101',
                '1100001000',
                '1000001111',
                '1110110000',
                '1111011001',
                '1001110100',
                '1111111111',
            ],
            DataMatrix::encode('123456')->toStrings(),
        );
    }

    public function testProducesTheKnownSymbolForASinglePaddedCharacter(): void
    {
        // Exercises the pad codewords, which the digits case does not:
        // one character, three codewords of capacity.
        self::assertSame(
            [
                '1010101010',
                '1101100011',
                '1000110100',
                '1001101011',
                '1001010000',
                '1001001011',
                '1101001100',
                '1100111101',
                '1100001000',
                '1111111111',
            ],
            DataMatrix::encode('A')->toStrings(),
        );
    }

    public function testTheFinderIsSolidOnTwoSidesAndAClockOnTheOther(): void
    {
        $grid = DataMatrix::encode('123456');

        for ($y = 0; $y < $grid->height(); ++$y) {
            self::assertTrue($grid->isDark(0, $y), "left edge should be solid at row $y");
        }

        for ($x = 0; $x < $grid->width(); ++$x) {
            self::assertTrue($grid->isDark($x, $grid->height() - 1), "bottom edge should be solid at column $x");
            self::assertSame($x % 2 === 0, $grid->isDark($x, 0), "top edge should alternate at column $x");
        }

        for ($y = 0; $y < $grid->height(); ++$y) {
            self::assertSame($y % 2 === 1, $grid->isDark($grid->width() - 1, $y), "right edge should alternate at row $y");
        }
    }

    /** @return list<array{string, int}> */
    public static function sizeCases(): array
    {
        return [
            ['A', 10],            // 1 codeword
            ['123456', 10],       // 3, exactly filling the smallest symbol
            ['ABCDE', 12],        // 5
            ['Hello, World!', 18],
            [self::digits(88), 26],
        ];
    }

    #[DataProvider('sizeCases')]
    public function testChoosesTheSmallestSquareThatFits(string $value, int $expected): void
    {
        $grid = DataMatrix::encode($value);

        self::assertSame($expected, $grid->width());
        self::assertSame($expected, $grid->height());
    }

    public function testGrowsIntoMultipleDataRegions(): void
    {
        // Up to 26x26 a symbol is one region, with finder patterns only
        // at its edges.
        $single = DataMatrix::encode(self::digits(88));
        self::assertSame(26, $single->width());

        // Above it, a symbol is several regions each with a finder of its
        // own, so solid lines appear in the interior. 32x32 is 2x2 regions
        // of 14 mapping columns, so the second region's solid left edge is
        // column 16.
        $split = DataMatrix::encode(self::digits(124));
        self::assertSame(32, $split->width());

        for ($y = 1; $y <= 14; ++$y) {
            self::assertTrue($split->isDark(16, $y), "region boundary should be solid at row $y");
        }

        // And the clock track that runs down the first region's right edge.
        for ($y = 1; $y <= 14; ++$y) {
            self::assertSame($y % 2 === 1, $split->isDark(15, $y), "region clock should alternate at row $y");
        }
    }

    public function testARectangleIsOnlyChosenWhenAskedFor(): void
    {
        self::assertSame(12, DataMatrix::encode('ABCDE')->height());

        $rectangle = DataMatrix::encode('ABCDE', DataMatrixShape::Rectangular);

        self::assertSame(8, $rectangle->height());
        self::assertSame(18, $rectangle->width());
    }

    public function testRefusesAnEmptyValue(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('has to encode something');

        DataMatrix::encode('');
    }

    public function testRefusesAValueTooLongForAnySymbol(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('largest square Data Matrix holds 1304');

        DataMatrix::encode(self::digits(3000));
    }

    public function testSaysASquareWouldHaveFittedWhenARectangleWillNot(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('or use a square, which goes up to 1304');

        // 60 digits is 30 codewords; the largest rectangle holds 49.
        DataMatrix::encode(self::digits(120), DataMatrixShape::Rectangular);
    }

    public function testTheLargestSymbolIsDeliberatelyNotOffered(): void
    {
        // 144x144's ten error-correction blocks are not all the same
        // shape, and a guess at its interleaving would produce a symbol
        // that looks right and does not scan. See DataMatrixSize::all().
        foreach (DataMatrixSize::all() as $size) {
            self::assertNotSame(144, $size->rows);
        }

        self::assertSame(1304, DataMatrixSize::largestCapacity());
    }

    public function testEverySizeInTheTableIsSelfConsistent(): void
    {
        foreach (DataMatrixSize::all() as $size) {
            $capacity = $size->mappingRows() * $size->mappingColumns();
            $codewords = ($size->dataCodewords + $size->eccCodewords) * 8;

            // The mapping matrix holds every codeword, with at most the
            // four modules of the leftover corner to spare.
            self::assertGreaterThanOrEqual(
                $codewords,
                $capacity,
                "{$size->rows}x{$size->columns} cannot hold its own codewords",
            );
            self::assertLessThanOrEqual(
                $codewords + 4,
                $capacity,
                "{$size->rows}x{$size->columns} has more room than its codewords need",
            );

            self::assertSame(
                0,
                $size->eccCodewords % $size->blocks,
                "{$size->rows}x{$size->columns} cannot split its check codewords evenly",
            );
        }
    }

    private static function digits(int $count): string
    {
        return substr(str_repeat('1234567890', (int) ceil($count / 10)), 0, $count);
    }
}
