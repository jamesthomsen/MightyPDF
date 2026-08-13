<?php

declare(strict_types=1);

namespace MightyPDF\Content\Barcode;

use MightyPDF\Content\Text\Utf8;
use MightyPDF\Exception\InvalidArgumentException;

/**
 * Code 39 (ISO/IEC 16388, aka "3 of 9") 1D barcode encoding.
 *
 * Produces a flat list of bar/space element widths in abstract "module"
 * units (narrow = 1 module, wide = $wideRatio modules); it has no drawing
 * or PDF knowledge at all -- PageBuilder::drawBarcode() is the only thing
 * that turns this into actual points and rectangles, the same separation
 * FontMetrics keeps from ContentStream.
 *
 * $value is framed with the start/stop character ('*') automatically;
 * callers supply only the data characters and must not include '*'
 * themselves. Supported characters: 0-9, A-Z (lowercase is upper-cased
 * automatically, since Code 39 has no lowercase), space, and - . $ / + %.
 */
final class Code39
{
    private function __construct()
    {
    }

    private const string START_STOP = '*';

    /**
     * @var array<string, string> character => 9-element bar/space pattern
     *   (alternating bar, space, bar, ..., bar -- 5 bars, 4 spaces), where
     *   '0' is a narrow element and '1' is a wide one. Every pattern has
     *   exactly three wide elements, per the format's name ("3 of 9").
     */
    private const array PATTERNS = [
        '0' => '000110100', '1' => '100100001', '2' => '001100001', '3' => '101100000',
        '4' => '000110001', '5' => '100110000', '6' => '001110000', '7' => '000100101',
        '8' => '100100100', '9' => '001100100',
        'A' => '100001001', 'B' => '001001001', 'C' => '101001000', 'D' => '000011001',
        'E' => '100011000', 'F' => '001011000', 'G' => '000001101', 'H' => '100001100',
        'I' => '001001100', 'J' => '000011100', 'K' => '100000011', 'L' => '001000011',
        'M' => '101000010', 'N' => '000010011', 'O' => '100010010', 'P' => '001010010',
        'Q' => '000000111', 'R' => '100000110', 'S' => '001000110', 'T' => '000010110',
        'U' => '110000001', 'V' => '011000001', 'W' => '111000000', 'X' => '010010001',
        'Y' => '110010000', 'Z' => '011010000',
        '-' => '010000101', '.' => '110000100', ' ' => '011000100',
        '$' => '010101000', '/' => '010100010', '+' => '010001010', '%' => '000101010',
        self::START_STOP => '010010100',
    ];

    /**
     * @return list<array{isBar: bool, widthModules: float}> left to right,
     *   including the framing start/stop characters and the mandatory
     *   single-narrow-space gap between every pair of characters
     */
    public static function elements(string $value, float $wideRatio = 2.0): array
    {
        $value = strtoupper($value);

        if (str_contains($value, self::START_STOP)) {
            throw new InvalidArgumentException('Code 39 value must not contain "*" -- the start/stop character is added automatically.');
        }

        $characters = [self::START_STOP, ...Utf8::characters($value), self::START_STOP];
        $lastIndex = count($characters) - 1;

        $out = [];
        foreach ($characters as $index => $character) {
            $pattern = self::PATTERNS[$character] ?? null;
            if ($pattern === null) {
                throw new InvalidArgumentException("Character '$character' is not supported by Code 39.");
            }

            foreach (str_split($pattern) as $elementIndex => $bit) {
                $out[] = ['isBar' => $elementIndex % 2 === 0, 'widthModules' => $bit === '1' ? $wideRatio : 1.0];
            }

            if ($index !== $lastIndex) {
                $out[] = ['isBar' => false, 'widthModules' => 1.0];
            }
        }

        return $out;
    }
}
