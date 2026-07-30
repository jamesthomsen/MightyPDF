<?php

declare(strict_types=1);

namespace MightyPDF\Tests\Content\Barcode;

use MightyPDF\Content\Barcode\Code39;
use PHPUnit\Framework\TestCase;

final class Code39Test extends TestCase
{
    public function testEveryCharacterPatternHasExactlyThreeWideElements(): void
    {
        // The defining property of "3 of 9": each 9-element character
        // pattern has exactly 3 wide elements, regardless of value --
        // checked here via the public encoder's output rather than the
        // private table directly.
        foreach (str_split('0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZ-. $/+%') as $char) {
            $elements = Code39::elements($char);
            // Strip the two framing '*' characters' 9 elements each, plus
            // the two inter-character gaps, leaving just this character's 9.
            $charElements = array_slice($elements, 10, 9);

            $wideCount = count(array_filter($charElements, static fn (array $e): bool => $e['widthModules'] > 1.0));
            self::assertSame(3, $wideCount, "character '$char' should have exactly 3 wide elements");
        }
    }

    public function testFramesValueWithStartAndStopCharacters(): void
    {
        $elements = Code39::elements('A');

        // 3 characters (*, A, *) * 9 elements + 2 inter-character gaps = 29.
        self::assertCount(29, $elements);
    }

    public function testRejectsValueContainingStartStopCharacter(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Code39::elements('AB*CD');
    }

    public function testRejectsUnsupportedCharacter(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Code39::elements('café'); // lowercase accented -- not transliteratable to the Code 39 set
    }

    public function testLowercaseIsUppercasedAutomatically(): void
    {
        self::assertSame(Code39::elements('AB'), Code39::elements('ab'));
    }

    public function testWideRatioScalesWideElementsOnly(): void
    {
        $narrow = Code39::elements('A', wideRatio: 3.0);

        foreach ($narrow as $element) {
            self::assertContains($element['widthModules'], [1.0, 3.0]);
        }
    }

    /**
     * Round-trips the encoder's own output back to characters using an
     * independent (test-local) decoder built from the same pattern table,
     * by measuring element widths rather than reading the encoder's
     * internal state. This verifies the encoder's *geometry* is
     * self-consistent (correct element count/order/grouping, gaps in the
     * right places) -- it cannot, on its own, prove the table matches the
     * real ISO/IEC 16388 assignment for each character, since both sides
     * of this test share the same table.
     */
    public function testRoundTripsThroughAnIndependentWidthBasedDecoder(): void
    {
        $value = 'CODE39-1.5 TEST';
        $elements = Code39::elements($value, wideRatio: 2.0);

        self::assertSame('*' . $value . '*', self::decode($elements));
    }

    /** @param list<array{isBar: bool, widthModules: float}> $elements */
    private static function decode(array $elements): string
    {
        $patterns = array_flip([
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
            '*' => '010010100',
        ]);

        // Elements alternate 9-per-character with a single-narrow-space
        // gap in between (but no trailing gap after the last character);
        // walk them back out into per-character groups of 9.
        $characters = [];
        $i = 0;
        $count = count($elements);
        while ($i < $count) {
            $bits = implode('', array_map(
                static fn (array $e): string => $e['widthModules'] > 1.0 ? '1' : '0',
                array_slice($elements, $i, 9),
            ));
            $characters[] = $patterns[$bits] ?? '?';
            $i += 9;
            if ($i < $count) {
                $i += 1; // skip the inter-character gap
            }
        }

        return implode('', $characters);
    }
}
