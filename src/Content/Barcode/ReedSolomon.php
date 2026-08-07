<?php

declare(strict_types=1);

namespace MightyPDF\Content\Barcode;

/**
 * Reed-Solomon error correction over GF(256), as QR codes use it.
 *
 * This is what makes a QR code readable with a corner torn off. The
 * encoder appends check codewords computed so that the whole block is a
 * multiple of a generator polynomial; a decoder can then reconstruct up
 * to half that many corrupted codewords. Nothing here decodes -- a writer
 * has no need to -- so this is the remainder of one polynomial division
 * and the field arithmetic underneath it.
 *
 * The field is the integers 0-255 under the primitive polynomial
 * x^8 + x^4 + x^3 + x^2 + 1 (0x11D), which is the one QR specifies.
 * Addition is XOR; multiplication is carry-less multiplication reduced by
 * that polynomial. Both are done directly rather than through log tables:
 * the tables are the classic optimisation and they buy nothing at the
 * sizes here (a QR block is at most 30 check codewords), while costing a
 * second representation of the field that has to agree with the first.
 */
final class ReedSolomon
{
    private function __construct()
    {
    }

    /**
     * The check codewords for one block.
     *
     * @param list<int> $data 0-255 each
     * @param int $degree how many check codewords to produce
     *
     * @return list<int> exactly $degree of them
     */
    public static function remainder(array $data, int $degree): array
    {
        if ($degree < 1 || $degree > 255) {
            throw new \InvalidArgumentException("Between 1 and 255 check codewords, got $degree.");
        }

        $divisor = self::generator($degree);
        $result = array_fill(0, $degree, 0);

        foreach ($data as $byte) {
            // Long division, one dividend byte at a time: the leading
            // coefficient decides the multiple of the divisor to subtract
            // (which is to say XOR), and the remainder shifts up.
            $factor = $byte ^ array_shift($result);
            $result[] = 0;

            foreach ($divisor as $index => $coefficient) {
                $result[$index] ^= self::multiply($coefficient, $factor);
            }
        }

        return $result;
    }

    /**
     * The generator polynomial of the given degree: the product of
     * (x - 2^i) for i from 0, with its leading 1 left off since it is
     * always there.
     *
     * @return list<int> coefficients, highest power first
     */
    public static function generator(int $degree): array
    {
        $result = array_fill(0, $degree, 0);
        $result[$degree - 1] = 1;

        // Multiplied out one root at a time. 0x02 is the field's
        // generator element, so its powers run through every non-zero
        // value before repeating.
        $root = 1;

        for ($i = 0; $i < $degree; ++$i) {
            for ($j = 0; $j < $degree; ++$j) {
                $result[$j] = self::multiply($result[$j], $root);

                if ($j + 1 < $degree) {
                    $result[$j] ^= $result[$j + 1];
                }
            }

            $root = self::multiply($root, 0x02);
        }

        return $result;
    }

    /** Carry-less multiplication in GF(256), reduced by 0x11D. */
    public static function multiply(int $a, int $b): int
    {
        $result = 0;

        for ($i = 7; $i >= 0; --$i) {
            // Square, then add the multiplicand where this bit of the
            // multiplier is set. The reduction happens on the square,
            // which is the only place the value can leave the field.
            $result = ($result << 1) ^ (($result >> 7) * 0x11D);
            $result ^= (($b >> $i) & 1) * $a;
        }

        return $result & 0xFF;
    }
}
