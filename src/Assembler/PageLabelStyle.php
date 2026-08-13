<?php

declare(strict_types=1);

namespace MightyPDF\Assembler;

/**
 * How the numbers in a run of page labels are written (ISO 32000-2
 * §12.4.2, Table 159) -- the /S of one /PageLabels entry.
 *
 * These are the labels a *reader* shows in its page box and its thumbnail
 * captions. They have nothing to do with anything printed on the page, and
 * nothing here draws a folio: a document whose front matter is numbered i,
 * ii, iii and whose body restarts at 1 needs both, and this is the half
 * that stops the reader's toolbar disagreeing with the paper.
 */
enum PageLabelStyle: string
{
    /** 1, 2, 3, ... */
    case Decimal = 'D';

    /** I, II, III, IV, ... */
    case UppercaseRoman = 'R';

    /** i, ii, iii, iv, ... */
    case LowercaseRoman = 'r';

    /** A, B, ... Z, AA, BB, ... -- doubled, not spreadsheet columns. */
    case UppercaseLetters = 'A';

    /** a, b, ... z, aa, bb, ... */
    case LowercaseLetters = 'a';

    /**
     * No number at all: every page in the range is labelled with the
     * prefix alone. What "Cover", "Insert" or a run of unnumbered plates
     * is written as.
     */
    case None = '';

    /**
     * The label this style gives to the $ordinal-th page of its range,
     * counting from 1.
     *
     * Present so that a caller can ask what a reader will show -- to put
     * the same string in a table of contents, or to check that a document
     * is numbered the way it was meant to be, which is otherwise only
     * discoverable by opening it.
     */
    public function format(int $ordinal): string
    {
        return match ($this) {
            self::Decimal => (string) $ordinal,
            self::UppercaseRoman => self::roman($ordinal),
            self::LowercaseRoman => strtolower(self::roman($ordinal)),
            self::UppercaseLetters => self::letters($ordinal),
            self::LowercaseLetters => strtolower(self::letters($ordinal)),
            self::None => '',
        };
    }

    /**
     * Roman numerals, subtractive.
     *
     * Above 3999 there is no agreed notation and readers differ; repeating
     * M is what they mostly do, and is what the loop produces without
     * being told to. Zero and below have no numeral at all -- the spec's
     * numbering starts at 1 (/St is required to) and there is nothing
     * sensible to write for "the zeroth page of this range".
     */
    private static function roman(int $ordinal): string
    {
        if ($ordinal < 1) {
            return '';
        }

        static $values = [
            1000 => 'M', 900 => 'CM', 500 => 'D', 400 => 'CD',
            100 => 'C', 90 => 'XC', 50 => 'L', 40 => 'XL',
            10 => 'X', 9 => 'IX', 5 => 'V', 4 => 'IV', 1 => 'I',
        ];

        $out = '';

        foreach ($values as $value => $numeral) {
            while ($ordinal >= $value) {
                $out .= $numeral;
                $ordinal -= $value;
            }
        }

        return $out;
    }

    /**
     * A, B, ... Z, AA, BB, ... ZZ, AAA, ...
     *
     * Doubled letters, not base-26: Table 159 says "A to Z for the first
     * 26, AA to ZZ for the next 26, and so on", so the 27th is AA and the
     * 28th is BB. Spreadsheet-style columns would make the 28th AB, which
     * is the reading everyone reaches for and is wrong here.
     */
    private static function letters(int $ordinal): string
    {
        if ($ordinal < 1) {
            return '';
        }

        $letter = chr(ord('A') + (($ordinal - 1) % 26));

        return str_repeat($letter, intdiv($ordinal - 1, 26) + 1);
    }
}
