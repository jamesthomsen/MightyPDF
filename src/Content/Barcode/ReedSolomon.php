<?php

declare(strict_types=1);

namespace MightyPDF\Content\Barcode;

/**
 * Reed-Solomon error correction over GF(256), as QR codes and Data Matrix
 * use it.
 *
 * This is what makes a QR code readable with a corner torn off. The
 * encoder appends check codewords computed so that the whole block is a
 * multiple of a generator polynomial; a decoder can then reconstruct up
 * to half that many corrupted codewords. Nothing here decodes -- a writer
 * has no need to -- so this is the remainder of one polynomial division
 * and the field arithmetic underneath it.
 *
 * The field is the integers 0-255 under a primitive polynomial. Addition
 * is XOR; multiplication is carry-less multiplication reduced by that
 * polynomial. Both are done directly rather than through log tables: the
 * tables are the classic optimisation and they buy nothing at the sizes
 * here (a QR block is at most 30 check codewords), while costing a second
 * representation of the field that has to agree with the first.
 *
 * **Two symbologies, two fields.** QR reduces by 0x11D and builds its
 * generator from roots starting at alpha^0; Data Matrix reduces by 0x12D
 * and starts at alpha^1. Neither choice is better and neither is
 * negotiable -- they are what the two standards say, and a symbol built
 * with the other one's arithmetic is a symbol no scanner reads. Both are
 * parameters here rather than two copies of the same long division.
 */
final class ReedSolomon
{
    /** x^8 + x^4 + x^3 + x^2 + 1 -- QR's field (ISO/IEC 18004 §8.5.2). */
    public const int QR_PRIMITIVE = 0x11D;

    /** x^8 + x^5 + x^3 + x^2 + 1 -- Data Matrix's (ISO/IEC 16022 §5.7). */
    public const int DATA_MATRIX_PRIMITIVE = 0x12D;

    private function __construct()
    {
    }

    /**
     * The check codewords for one block.
     *
     * @param list<int> $data 0-255 each
     * @param int $degree how many check codewords to produce
     * @param int $primitive the field's reducing polynomial
     * @param int $firstRoot the field element the generator's first root
     *        is: 1 for alpha^0 (QR), 2 for alpha^1 (Data Matrix)
     *
     * @return list<int> exactly $degree of them
     */
    public static function remainder(
        array $data,
        int $degree,
        int $primitive = self::QR_PRIMITIVE,
        int $firstRoot = 1,
    ): array {
        if ($degree < 1 || $degree > 255) {
            throw new \InvalidArgumentException("Between 1 and 255 check codewords, got $degree.");
        }

        $divisor = self::generator($degree, $primitive, $firstRoot);
        $result = array_fill(0, $degree, 0);

        foreach ($data as $byte) {
            // Long division, one dividend byte at a time: the leading
            // coefficient decides the multiple of the divisor to subtract
            // (which is to say XOR), and the remainder shifts up.
            $factor = $byte ^ array_shift($result);
            $result[] = 0;

            foreach ($divisor as $index => $coefficient) {
                $result[$index] ^= self::multiply($coefficient, $factor, $primitive);
            }
        }

        return $result;
    }

    /**
     * The generator polynomial of the given degree: the product of
     * (x - alpha^i) for i counting up from $firstRoot's exponent, with its
     * leading 1 left off since it is always there.
     *
     * @return list<int> coefficients, highest power first
     */
    public static function generator(
        int $degree,
        int $primitive = self::QR_PRIMITIVE,
        int $firstRoot = 1,
    ): array {
        $result = array_fill(0, $degree, 0);
        $result[$degree - 1] = 1;

        // Multiplied out one root at a time. 0x02 is the field's
        // generator element, so its powers run through every non-zero
        // value before repeating.
        $root = $firstRoot;

        for ($i = 0; $i < $degree; ++$i) {
            for ($j = 0; $j < $degree; ++$j) {
                $result[$j] = self::multiply($result[$j], $root, $primitive);

                if ($j + 1 < $degree) {
                    $result[$j] ^= $result[$j + 1];
                }
            }

            $root = self::multiply($root, 0x02, $primitive);
        }

        return $result;
    }

    /** Carry-less multiplication in GF(256), reduced by $primitive. */
    public static function multiply(int $a, int $b, int $primitive = self::QR_PRIMITIVE): int
    {
        $result = 0;

        for ($i = 7; $i >= 0; --$i) {
            // Square, then add the multiplicand where this bit of the
            // multiplier is set. The reduction happens on the square,
            // which is the only place the value can leave the field.
            $result = ($result << 1) ^ (($result >> 7) * $primitive);
            $result ^= (($b >> $i) & 1) * $a;
        }

        return $result & 0xFF;
    }
}
