<?php

declare(strict_types=1);

namespace MightyPDF\Content\Barcode;

/**
 * EAN-13 and UPC-A: the barcode on retail packaging.
 *
 * Fixed at thirteen digits and 95 modules, always. There is no variable
 * length to encode and no code set to choose, which makes this the
 * simplest of the three symbologies here and the fussiest about its
 * input: a wrong check digit is a barcode that scans as a different
 * product, so it is computed rather than trusted, and a thirteenth digit
 * that disagrees with the computation is refused rather than corrected.
 *
 * **UPC-A is EAN-13 with a leading zero.** The twelve-digit code on
 * American packaging is the same symbol; upcA() prefixes the zero and
 * hands over. A scanner reads the same thirteen digits either way and
 * drops the zero when reporting a UPC.
 *
 * **The first digit is not drawn.** It is carried in the *parity* of the
 * six digits on the left -- which of two encodings each uses -- which is
 * how thirteen digits fit into twelve digit positions. That is also why
 * the human-readable line under a real EAN-13 has its first digit
 * hanging outside the bars.
 */
final class Ean13
{
    private function __construct()
    {
    }

    /** Left-hand odd parity, digit 0-9, as bars(1) and spaces(0). */
    private const array LEFT_ODD = [
        '0001101', '0011001', '0010011', '0111101', '0100011',
        '0110001', '0101111', '0111011', '0110111', '0001011',
    ];

    /** Left-hand even parity: the same digits, mirrored and inverted. */
    private const array LEFT_EVEN = [
        '0100111', '0110011', '0011011', '0100001', '0011101',
        '0111001', '0000101', '0010001', '0001001', '0010111',
    ];

    /** Right-hand, which is the odd left-hand set inverted -- always bar-first. */
    private const array RIGHT = [
        '1110010', '1100110', '1101100', '1000010', '1011100',
        '1001110', '1010000', '1000100', '1001000', '1110100',
    ];

    /**
     * Which of the six left-hand digits use even parity, by first digit.
     * This is where the thirteenth digit lives.
     */
    private const array PARITY = [
        '000000', '001011', '001101', '001110', '010011',
        '011001', '011100', '010101', '010110', '011010',
    ];

    /**
     * Nine modules on the left and seven on the right, per the standard.
     * Asymmetric because the left edge carries the first digit's printed
     * character.
     */
    public const int QUIET_ZONE_LEFT_MODULES = 9;
    public const int QUIET_ZONE_RIGHT_MODULES = 7;

    /**
     * $value is twelve digits, in which case the check digit is computed
     * and appended, or thirteen, in which case it is verified.
     *
     * @return list<array{isBar: bool, widthModules: float}>
     */
    public static function elements(string $value): array
    {
        return self::toElements(self::bits(self::normalize($value)));
    }

    /**
     * The twelve-digit UPC-A on American packaging, which is this symbol
     * with a leading zero.
     *
     * @return list<array{isBar: bool, widthModules: float}>
     */
    public static function upcAElements(string $value): array
    {
        $digits = preg_replace('/\s+/', '', $value) ?? '';

        if (preg_match('/^\d{11,12}$/', $digits) !== 1) {
            throw new \InvalidArgumentException(
                "UPC-A takes eleven digits, or twelve including the check digit -- got \"$value\".",
            );
        }

        return self::elements('0' . $digits);
    }

    /**
     * The full thirteen digits, check digit included -- for printing the
     * human-readable line under the bars, which a caller does with
     * drawText() and which should say what the symbol actually encodes
     * rather than what was passed in.
     */
    public static function normalize(string $value): string
    {
        $digits = preg_replace('/[\s-]+/', '', $value) ?? '';

        if (preg_match('/^\d{12,13}$/', $digits) !== 1) {
            throw new \InvalidArgumentException(
                "EAN-13 takes twelve digits, or thirteen including the check digit -- got \"$value\".",
            );
        }

        $check = self::checkDigit(substr($digits, 0, 12));

        if (strlen($digits) === 13 && (int) $digits[12] !== $check) {
            throw new \InvalidArgumentException(sprintf(
                'The check digit of "%s" is wrong: it should be %d, not %s. '
                . 'Pass the first twelve digits to have it computed.',
                $digits,
                $check,
                $digits[12],
            ));
        }

        return substr($digits, 0, 12) . $check;
    }

    /**
     * The thirteenth digit: the first twelve weighted 1, 3, 1, 3, ...
     * summed, and whatever takes that sum to a multiple of ten.
     */
    public static function checkDigit(string $twelveDigits): int
    {
        $sum = 0;

        foreach (str_split($twelveDigits) as $index => $digit) {
            $sum += (int) $digit * ($index % 2 === 0 ? 1 : 3);
        }

        return (10 - $sum % 10) % 10;
    }

    /** The 95-module symbol as a bit string, bars set. */
    private static function bits(string $thirteenDigits): string
    {
        $parity = self::PARITY[(int) $thirteenDigits[0]];

        $bits = '101';

        for ($i = 1; $i <= 6; ++$i) {
            $digit = (int) $thirteenDigits[$i];
            $bits .= $parity[$i - 1] === '0' ? self::LEFT_ODD[$digit] : self::LEFT_EVEN[$digit];
        }

        $bits .= '01010';

        for ($i = 7; $i <= 12; ++$i) {
            $bits .= self::RIGHT[(int) $thirteenDigits[$i]];
        }

        return $bits . '101';
    }

    /**
     * Runs of equal modules, coalesced.
     *
     * Unlike Code 39 and Code 128 this symbology is defined module by
     * module rather than as element widths, and two adjacent bars are one
     * bar as far as a scanner and a printer are concerned. Emitting them
     * separately would draw abutting rectangles that hairline-crack along
     * the seam at some resolutions.
     *
     * @return list<array{isBar: bool, widthModules: float}>
     */
    private static function toElements(string $bits): array
    {
        $out = [];
        $run = 0;
        $current = $bits[0];
        $length = strlen($bits);

        for ($i = 0; $i <= $length; ++$i) {
            if ($i < $length && $bits[$i] === $current) {
                ++$run;

                continue;
            }

            $out[] = ['isBar' => $current === '1', 'widthModules' => (float) $run];

            if ($i < $length) {
                $current = $bits[$i];
                $run = 1;
            }
        }

        return $out;
    }
}
