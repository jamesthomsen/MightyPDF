<?php

declare(strict_types=1);

namespace MightyPDF\Tests\Content\Barcode;

use MightyPDF\Content\Barcode\QrCode;
use MightyPDF\Content\Barcode\QrEccLevel;
use MightyPDF\Content\Barcode\QrMatrix;
use MightyPDF\Content\Barcode\ReedSolomon;
use PHPUnit\Framework\TestCase;

/**
 * A QR encoder is a lot of table-driven arithmetic whose output is a
 * picture, so "it produced something" is worth nothing as a test. These
 * check it against the standard's own worked example, against the
 * published capacity figures, and against the structural invariants a
 * scanner relies on -- including reading the format information back out
 * of the finished matrix, which is an independent check of the BCH code
 * that put it there.
 */
final class QrCodeTest extends TestCase
{
    /**
     * ISO/IEC 18004 Annex I works "01234567" through a version-1-M symbol
     * and prints the codewords. This is that example.
     *
     * Numeric mode: the mode indicator 0001, a count of 8 in ten bits,
     * then 012, 345, 67 as 10, 10 and 7 bits -- then the terminator, the
     * pad to a byte, and the alternating pad bytes.
     */
    public function testTheStandardsWorkedExampleProducesItsPublishedDataCodewords(): void
    {
        self::assertSame(
            [0x10, 0x20, 0x0C, 0x56, 0x61, 0x80, 0xEC, 0x11, 0xEC, 0x11, 0xEC, 0x11, 0xEC, 0x11, 0xEC, 0x11],
            $this->dataCodewords('01234567', 1, QrEccLevel::Medium),
        );
    }

    /** And the ten error-correction codewords the same example prints. */
    public function testTheStandardsWorkedExampleProducesItsPublishedEccCodewords(): void
    {
        $data = [0x10, 0x20, 0x0C, 0x56, 0x61, 0x80, 0xEC, 0x11, 0xEC, 0x11, 0xEC, 0x11, 0xEC, 0x11, 0xEC, 0x11];

        self::assertSame(
            [0xA5, 0x24, 0xD4, 0xC1, 0xED, 0x36, 0xC7, 0x87, 0x2C, 0x55],
            ReedSolomon::remainder($data, 10),
        );
    }

    /**
     * The published capacity table, at both ends and in the middle. These
     * follow from the two tables in QrCode plus the module geometry, so
     * agreeing with the standard here is what says the derivation is
     * right and the tables were transcribed correctly.
     */
    public function testDataCapacitiesMatchThePublishedTable(): void
    {
        $expected = [
            [1, QrEccLevel::Low, 19], [1, QrEccLevel::Medium, 16],
            [1, QrEccLevel::Quartile, 13], [1, QrEccLevel::High, 9],
            [2, QrEccLevel::Low, 34], [2, QrEccLevel::High, 16],
            [10, QrEccLevel::Medium, 216], [10, QrEccLevel::High, 122],
            [26, QrEccLevel::Quartile, 754],
            [40, QrEccLevel::Low, 2956], [40, QrEccLevel::High, 1276],
        ];

        foreach ($expected as [$version, $level, $codewords]) {
            self::assertSame(
                $codewords,
                $this->call('dataCodewords', $version, $level),
                "version $version at level {$level->name}",
            );
        }
    }

    public function testModeIsChosenAsTheMostCompactThatFits(): void
    {
        // Numeric packs three digits into ten bits, so 41 of them still
        // fit a version 1 at level L; as bytes they would not.
        self::assertSame(1, QrCode::encode(str_repeat('7', 41), QrEccLevel::Low)->version);

        // Alphanumeric is uppercase and a handful of punctuation only, so
        // one lowercase letter drops the whole thing to byte mode.
        self::assertSame(1, QrCode::encode('HELLO WORLD', QrEccLevel::Quartile)->version);
        self::assertSame(2, QrCode::encode('Hello, world!', QrEccLevel::Quartile)->version);
    }

    public function testSizeFollowsTheVersion(): void
    {
        self::assertSame(21, QrCode::encode('x')->size());
        self::assertSame(25, QrCode::encode('x', minVersion: 2)->size());
        self::assertSame(177, QrCode::encode('x', minVersion: 40)->size());
    }

    public function testMinVersionPinsTheDensitySoARunOfCodesMatches(): void
    {
        $short = QrCode::encode('1', minVersion: 5);
        $long = QrCode::encode(str_repeat('1', 40), minVersion: 5);

        self::assertSame($short->size(), $long->size());
    }

    public function testDataTooBigForTheLargestVersionIsRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('too much for a version-40 QR code');

        QrCode::encode(str_repeat('x', 3000), QrEccLevel::High);
    }

    public function testAVersionRangeThatCannotBeSatisfiedIsRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Versions run from 1 to 40');

        QrCode::encode('x', minVersion: 10, maxVersion: 5);
    }

    // -- Structure a scanner relies on ------------------------------------

    /**
     * Three finder squares, not four: the empty corner is what tells a
     * scanner which way up the symbol is.
     */
    public function testTheThreeFinderPatternsAreWhereTheyShouldBe(): void
    {
        $code = QrCode::encode('MIGHTYPDF');
        $last = $code->size() - 1;

        foreach ([[0, 0], [$last - 6, 0], [0, $last - 6]] as [$originX, $originY]) {
            for ($dy = 0; $dy < 7; ++$dy) {
                for ($dx = 0; $dx < 7; ++$dx) {
                    $ring = max(abs($dx - 3), abs($dy - 3));

                    self::assertSame(
                        $ring !== 2,
                        $code->isDark($originX + $dx, $originY + $dy),
                        "finder module ($dx, $dy) at ($originX, $originY)",
                    );
                }
            }
        }

        // And nothing finder-shaped in the fourth corner.
        self::assertFalse($code->isDark($last - 3, $last - 3));
    }

    public function testTheTimingPatternsAlternate(): void
    {
        $code = QrCode::encode('MIGHTYPDF');

        for ($i = 8; $i < $code->size() - 8; ++$i) {
            self::assertSame($i % 2 === 0, $code->isDark($i, 6), "horizontal timing at $i");
            self::assertSame($i % 2 === 0, $code->isDark(6, $i), "vertical timing at $i");
        }
    }

    public function testTheDarkModuleIsAlwaysDark(): void
    {
        foreach ([1, 7, 20, 40] as $version) {
            $code = QrCode::encode('x', minVersion: $version);

            self::assertTrue($code->isDark(8, $code->size() - 8), "version $version");
        }
    }

    /**
     * Reads the format information back out of the finished matrix and
     * checks it says what was actually used.
     *
     * This is the one check here that does not trust the encoder at all:
     * it undoes the XOR mask, verifies the BCH code by brute force
     * against all 32 valid words, and compares the recovered level and
     * mask against the ones the object reports.
     */
    public function testTheFormatInformationReadsBackAsTheLevelAndMaskInUse(): void
    {
        foreach (QrEccLevel::cases() as $level) {
            $code = QrCode::encode('MIGHTYPDF', $level);

            $bits = 0;

            // The first copy, wrapped around the top-left finder.
            for ($i = 0; $i <= 5; ++$i) {
                $bits |= ($code->isDark(8, $i) ? 1 : 0) << $i;
            }

            $bits |= ($code->isDark(8, 7) ? 1 : 0) << 6;
            $bits |= ($code->isDark(8, 8) ? 1 : 0) << 7;
            $bits |= ($code->isDark(7, 8) ? 1 : 0) << 8;

            for ($i = 9; $i <= 14; ++$i) {
                $bits |= ($code->isDark(14 - $i, 8) ? 1 : 0) << $i;
            }

            $data = $this->decodeFormat($bits);

            self::assertNotNull($data, 'the format bits should be a valid BCH codeword');
            self::assertSame($level->value, $data >> 3, 'the error-correction level');
            self::assertSame($code->mask, $data & 7, 'the mask actually applied');
        }
    }

    /** The second copy has to agree with the first, or a torn corner loses both. */
    public function testTheSecondCopyOfTheFormatInformationAgreesWithTheFirst(): void
    {
        $code = QrCode::encode('MIGHTYPDF');
        $size = $code->size();

        for ($i = 0; $i <= 5; ++$i) {
            self::assertSame($code->isDark(8, $i), $code->isDark($size - 1 - $i, 8), "bit $i");
        }

        for ($i = 9; $i <= 14; ++$i) {
            self::assertSame($code->isDark(14 - $i, 8), $code->isDark(8, $size - 15 + $i), "bit $i");
        }
    }

    /**
     * The alignment pattern positions the standard prints, against the
     * rule QrMatrix derives them from -- including version 32, the one
     * version where the even spacing does not divide and the standard
     * simply states a different step.
     */
    public function testAlignmentPatternPositionsMatchThePublishedTable(): void
    {
        $expected = [
            1 => [],
            2 => [6, 18],
            3 => [6, 22],
            7 => [6, 22, 38],
            10 => [6, 28, 50],
            14 => [6, 26, 46, 66],
            20 => [6, 34, 62, 90],
            21 => [6, 28, 50, 72, 94],
            32 => [6, 34, 60, 86, 112, 138],
            39 => [6, 26, 54, 82, 110, 138, 166],
            40 => [6, 30, 58, 86, 114, 142, 170],
        ];

        foreach ($expected as $version => $positions) {
            self::assertSame($positions, QrMatrix::alignmentPositions($version), "version $version");
        }
    }

    public function testAMaskIsAlwaysOneOfTheEight(): void
    {
        foreach (['1', 'HELLO WORLD', 'https://example.com/x', str_repeat('data ', 40)] as $value) {
            $mask = QrCode::encode($value)->mask;

            self::assertGreaterThanOrEqual(0, $mask);
            self::assertLessThanOrEqual(7, $mask);
        }
    }

    /**
     * Masking exists to break up large blank areas, so a symbol should
     * never come out wildly lopsided -- the fourth penalty rule is what
     * enforces that, and this is the property it is enforcing.
     */
    public function testTheChosenMaskLeavesTheSymbolRoughlyBalanced(): void
    {
        foreach (['1', 'HELLO WORLD', str_repeat('x', 300)] as $value) {
            $code = QrCode::encode($value);
            $dark = 0;

            foreach ($code->modules() as $row) {
                $dark += count(array_filter($row));
            }

            $proportion = $dark / ($code->size() ** 2);

            self::assertGreaterThan(0.35, $proportion, "too light for \"$value\"");
            self::assertLessThan(0.65, $proportion, "too dark for \"$value\"");
        }
    }

    public function testVersionsSevenAndUpCarryVersionInformation(): void
    {
        $code = QrCode::encode('x', minVersion: 7);
        $size = $code->size();

        $bits = 0;

        for ($i = 0; $i < 18; ++$i) {
            $bits |= ($code->isDark($size - 11 + $i % 3, intdiv($i, 3)) ? 1 : 0) << $i;
        }

        self::assertSame(7, $bits >> 12, 'the version, in the top six bits');

        // And the BCH remainder underneath it should check out.
        $remainder = 7;

        for ($i = 0; $i < 12; ++$i) {
            $remainder = ($remainder << 1) ^ (($remainder >> 11) * 0x1F25);
        }

        self::assertSame($remainder, $bits & 0xFFF);
    }

    public function testVersionsBelowSevenCarryNone(): void
    {
        $code = QrCode::encode('x', minVersion: 6);
        $size = $code->size();

        // The version information block would sit here; on a small symbol
        // it is ordinary data, so all this asserts is that the symbol is
        // the size it should be and nothing wrote outside it.
        self::assertSame(41, $size);
        self::assertCount(41, $code->modules());
    }

    /**
     * Undoes the format mask and finds the 5-bit value whose BCH codeword
     * this is, or null if it is not one.
     */
    private function decodeFormat(int $bits): ?int
    {
        $bits ^= 0x5412;

        for ($data = 0; $data < 32; ++$data) {
            $remainder = $data;

            for ($i = 0; $i < 10; ++$i) {
                $remainder = ($remainder << 1) ^ (($remainder >> 9) * 0x537);
            }

            if ((($data << 10) | $remainder) === $bits) {
                return $data;
            }
        }

        return null;
    }

    /**
     * The data codewords for a value, before error correction.
     *
     * Reached by reflection because it is an intermediate rather than a
     * result -- but it is the intermediate the standard publishes, and
     * checking against the published one is worth more than any number of
     * assertions about the finished picture.
     *
     * @return list<int>
     */
    private function dataCodewords(string $value, int $version, QrEccLevel $level): array
    {
        $mode = new \ReflectionMethod(QrCode::class, 'modeFor');

        return $this->call('codewords', $value, $mode->invoke(null, $value), $version, $level);
    }

    private function call(string $method, mixed ...$arguments): mixed
    {
        return (new \ReflectionMethod(QrCode::class, $method))->invoke(null, ...$arguments);
    }
}
