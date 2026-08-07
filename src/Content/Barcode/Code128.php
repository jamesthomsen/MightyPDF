<?php

declare(strict_types=1);

namespace MightyPDF\Content\Barcode;

/**
 * Code 128 (ISO/IEC 15417): the general-purpose 1D symbology, and the one
 * a modern SKU, tracking number or licence plate is actually printed in.
 *
 * Where Code 39 can carry 43 characters and spends 12-16 modules on each,
 * this carries the whole of ASCII and spends 11 -- and half that for
 * digits, which it packs two to a symbol. A twelve-digit number is 79
 * modules here against 224 in Code 39, which is the difference between a
 * label that fits and one that does not.
 *
 * Like Code39 this produces module widths and nothing else: no drawing,
 * no PDF. PageBuilder::drawBarcode() turns it into rectangles.
 *
 * **Code sets.** The symbology has three, and which one is in effect
 * decides what a symbol means. A encodes control characters, B the
 * printable ASCII including lowercase, and C encodes *pairs* of digits.
 * Choosing between them is the encoder's job and is done here greedily:
 * start in C if the data opens with four or more digits, switch into it
 * for any run of six or more, and otherwise stay in B unless a control
 * character forces A. That is the strategy the standard's own annex
 * describes, and it produces the shortest symbol for every input this
 * library has been pointed at without the dynamic-programming search an
 * optimal encoder would need.
 *
 * **The check digit is not optional** and is not the caller's to supply:
 * every symbol carries one, computed here, weighted by position modulo
 * 103. A Code 128 without it is not a Code 128.
 */
final class Code128
{
    private function __construct()
    {
    }

    private const int START_A = 103;
    private const int START_B = 104;
    private const int START_C = 105;
    private const int STOP = 106;

    private const int SHIFT = 98;
    private const int CODE_C = 99;
    private const int CODE_B = 100;
    private const int CODE_A = 101;

    /**
     * Symbol value => element widths in modules, alternating bar, space,
     * bar, space, bar, space.
     *
     * Every entry is six elements totalling 11 modules, except the stop
     * pattern at 106, which is seven elements and 13 -- the extra bar is
     * what marks the end of the symbol.
     *
     * @var list<string>
     */
    private const array PATTERNS = [
        '212222', '222122', '222221', '121223', '121322', '131222', '122213', '122312', '132212', '221213',
        '221312', '231212', '112232', '122132', '122231', '113222', '123122', '123221', '223211', '221132',
        '221231', '213212', '223112', '312131', '311222', '321122', '321221', '312212', '322112', '322211',
        '212123', '212321', '232121', '111323', '131123', '131321', '112313', '132113', '132311', '211313',
        '231113', '231311', '112133', '112331', '132131', '113123', '113321', '133121', '313121', '211331',
        '231131', '213113', '213311', '213131', '311123', '311321', '331121', '312113', '312311', '332111',
        '314111', '221411', '431111', '111224', '111422', '121124', '121421', '141122', '141221', '112214',
        '112412', '122114', '122411', '142112', '142211', '241211', '221114', '413111', '241112', '134111',
        '111242', '121142', '121241', '114212', '124112', '124211', '411212', '421112', '421211', '212141',
        '214121', '412121', '111143', '111341', '131141', '114113', '114311', '411113', '411311', '113141',
        '114131', '311141', '411131', '211412', '211214', '211232', '2331112',
    ];

    /**
     * The clear space a scanner needs either side, in modules. Not part
     * of elements(), which describes bars only -- see
     * PageBuilder::drawBarcode() for how it is reserved.
     */
    public const int QUIET_ZONE_MODULES = 10;

    /**
     * @return list<array{isBar: bool, widthModules: float}> left to right,
     *   including the start symbol, the check symbol and the stop pattern
     */
    public static function elements(string $value): array
    {
        $symbols = self::symbols($value);

        $out = [];
        foreach ($symbols as $symbol) {
            foreach (str_split(self::PATTERNS[$symbol]) as $index => $width) {
                $out[] = ['isBar' => $index % 2 === 0, 'widthModules' => (float) $width];
            }
        }

        return $out;
    }

    /**
     * The whole symbol as values: start, data, check, stop.
     *
     * Public because it is the thing worth testing directly -- the
     * element widths are a mechanical expansion of it, and a wrong code
     * set or a wrong check digit is invisible in a list of bar widths.
     *
     * @return list<int>
     */
    public static function symbols(string $value): array
    {
        if ($value === '') {
            throw new \InvalidArgumentException('Code 128 cannot encode an empty value.');
        }

        $length = strlen($value);

        for ($i = 0; $i < $length; ++$i) {
            if (ord($value[$i]) > 127) {
                throw new \InvalidArgumentException(sprintf(
                    'Code 128 encodes ASCII only; byte 0x%02X at offset %d is outside it. '
                    . 'Text with accents or non-Latin characters needs a 2D symbology -- see drawQrCode().',
                    ord($value[$i]),
                    $i,
                ));
            }
        }

        $set = self::initialSet($value);
        $symbols = [match ($set) {
            'A' => self::START_A,
            'B' => self::START_B,
            default => self::START_C,
        }];

        $position = 0;

        while ($position < $length) {
            if ($set === 'C') {
                if (self::digitsAt($value, $position) >= 2) {
                    $symbols[] = (int) substr($value, $position, 2);
                    $position += 2;

                    continue;
                }

                $set = self::needsCodeA($value[$position]) ? 'A' : 'B';
                $symbols[] = $set === 'A' ? self::CODE_A : self::CODE_B;

                continue;
            }

            $run = self::digitsAt($value, $position);

            // Switching into C costs a symbol and saves one per pair, so
            // it pays from six digits in -- or from four at the very
            // start, where the switch rides on the start symbol instead.
            if ($run >= 6 || ($position === 0 && $run >= 4)) {
                if ($run % 2 === 1) {
                    // An odd run would leave a single digit stranded at
                    // the far end, where switching back costs more than
                    // the pair saved. Spend one symbol here instead.
                    $symbols[] = self::value($value[$position], $set);
                    ++$position;
                }

                $set = 'C';
                $symbols[] = self::CODE_C;

                continue;
            }

            $character = $value[$position];

            if (self::encodableIn($character, $set)) {
                $symbols[] = self::value($character, $set);
                ++$position;

                continue;
            }

            // One character out of set is a shift; two in a row is worth
            // a switch, since a shift costs a symbol every time and a
            // switch costs one only once.
            $other = $set === 'A' ? 'B' : 'A';

            if ($position + 1 < $length && !self::encodableIn($value[$position + 1], $set)) {
                $set = $other;
                $symbols[] = $other === 'A' ? self::CODE_A : self::CODE_B;

                continue;
            }

            $symbols[] = self::SHIFT;
            $symbols[] = self::value($character, $other);
            ++$position;
        }

        $symbols[] = self::checkSymbol($symbols);
        $symbols[] = self::STOP;

        return $symbols;
    }

    /**
     * The check symbol: every symbol so far weighted by its position,
     * modulo 103. The start symbol counts once, the first data symbol
     * once, the second twice, and so on.
     *
     * @param list<int> $symbols the start symbol followed by the data
     */
    private static function checkSymbol(array $symbols): int
    {
        $sum = $symbols[0];

        foreach (array_slice($symbols, 1) as $index => $symbol) {
            $sum += $symbol * ($index + 1);
        }

        return $sum % 103;
    }

    private static function initialSet(string $value): string
    {
        $run = self::digitsAt($value, 0);

        if ($run >= 4 || ($run === strlen($value) && $run % 2 === 0)) {
            return 'C';
        }

        // A is only for control characters. Deciding on the *first* one
        // that settles the question -- rather than on the first character
        // outright -- is what keeps "a\tb" in B with a shift instead of
        // starting in A and switching straight back.
        $length = strlen($value);

        for ($i = 0; $i < $length; ++$i) {
            if (self::needsCodeA($value[$i])) {
                return 'A';
            }

            if (!self::encodableIn($value[$i], 'A')) {
                return 'B';
            }
        }

        return 'B';
    }

    /** How many digits run from $position, which may be none. */
    private static function digitsAt(string $value, int $position): int
    {
        $run = 0;
        $length = strlen($value);

        while ($position + $run < $length && ctype_digit($value[$position + $run])) {
            ++$run;
        }

        return $run;
    }

    /** A control character: encodable in A and nowhere else. */
    private static function needsCodeA(string $character): bool
    {
        return ord($character) < 32;
    }

    private static function encodableIn(string $character, string $set): bool
    {
        $code = ord($character);

        return $set === 'A' ? ($code < 96) : ($code >= 32);
    }

    private static function value(string $character, string $set): int
    {
        $code = ord($character);

        if ($set === 'A') {
            // A runs space to underscore, then the control characters
            // fold back on top at 64.
            return $code >= 32 ? $code - 32 : $code + 64;
        }

        return $code - 32;
    }
}
