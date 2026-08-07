<?php

declare(strict_types=1);

namespace MightyPDF\Tests\Content\Barcode;

use MightyPDF\Content\Barcode\Code128;
use PHPUnit\Framework\TestCase;

final class Code128Test extends TestCase
{
    private const int START_A = 103;
    private const int START_B = 104;
    private const int START_C = 105;
    private const int CODE_C = 99;
    private const int SHIFT = 98;
    private const int STOP = 106;

    /**
     * The textbook example: "Wikipedia" in code set B, whose check symbol
     * is 88. A check digit is the one part of this symbology a caller
     * cannot eyeball, so it gets an example with a published answer.
     */
    public function testTheTextbookExampleProducesItsPublishedCheckSymbol(): void
    {
        $symbols = Code128::symbols('Wikipedia');

        self::assertSame(
            [self::START_B, 55, 73, 75, 73, 80, 69, 68, 73, 65, 88, self::STOP],
            $symbols,
        );
    }

    /**
     * Digits go two to a symbol in code set C, which is what makes this
     * symbology worth choosing over Code 39 for a part number.
     */
    public function testAllDigitsStartInCodeSetCAndPackTwoToASymbol(): void
    {
        self::assertSame(
            [self::START_C, 12, 34, 56, 78, 47, self::STOP],
            Code128::symbols('12345678'),
        );
    }

    public function testAShortRunOfDigitsIsNotWorthSwitchingFor(): void
    {
        // Four digits in the middle would cost a switch symbol to save
        // two, so they stay in B.
        $symbols = Code128::symbols('AB1234CD');

        self::assertNotContains(self::CODE_C, $symbols);
        self::assertSame(self::START_B, $symbols[0]);
    }

    public function testALongRunOfDigitsIsWorthSwitchingFor(): void
    {
        $symbols = Code128::symbols('AB123456789012CD');

        self::assertContains(self::CODE_C, $symbols);
    }

    /**
     * An odd-length run leaves a digit stranded at the far end, where
     * switching back costs more than the pair saved -- so the first digit
     * is spent in the current set and the rest go in pairs.
     */
    public function testAnOddRunSpendsItsFirstDigitBeforeSwitching(): void
    {
        $symbols = Code128::symbols('A1234567');

        // Start B, 'A', then '1' in B, then the switch, then three pairs.
        self::assertSame(self::START_B, $symbols[0]);
        self::assertSame(33, $symbols[1], "'A'");
        self::assertSame(17, $symbols[2], "'1' still in code set B");
        self::assertSame(self::CODE_C, $symbols[3]);
        self::assertSame([23, 45, 67], array_slice($symbols, 4, 3));
    }

    public function testFourDigitsAtTheStartAreWorthItBecauseTheSwitchRidesOnTheStartSymbol(): void
    {
        $symbols = Code128::symbols('1234AB');

        self::assertSame(self::START_C, $symbols[0]);
        self::assertSame([12, 34], array_slice($symbols, 1, 2));
    }

    public function testAControlCharacterStartsInCodeSetA(): void
    {
        $symbols = Code128::symbols("\tHELLO");

        self::assertSame(self::START_A, $symbols[0]);
        self::assertSame(73, $symbols[1], 'tab is 9, which folds to 73 in code set A');
    }

    /**
     * One character out of set is a shift, which costs a symbol each
     * time; two in a row are worth a switch, which costs one only once.
     *
     * Lowercase, because that is what makes the surrounding text set-B
     * only -- uppercase and a control character are both in set A, and
     * the encoder rightly starts there instead (see below).
     */
    public function testASingleOutOfSetCharacterIsAShiftRatherThanASwitch(): void
    {
        $symbols = Code128::symbols("ab\tcd");

        self::assertSame(self::START_B, $symbols[0]);
        self::assertContains(self::SHIFT, $symbols);
    }

    public function testTwoOutOfSetCharactersAreWorthASwitch(): void
    {
        $symbols = Code128::symbols("ab\t\tcd");

        self::assertNotContains(self::SHIFT, $symbols);
    }

    /**
     * The initial set is decided by the first character that settles the
     * question, not by the first character outright: uppercase is in both
     * sets, so text that is uppercase and control characters belongs
     * wholly in A and needs neither a shift nor a switch.
     */
    public function testTheInitialSetIsDecidedByTheFirstCharacterThatSettlesIt(): void
    {
        $symbols = Code128::symbols("AB\tCD");

        self::assertSame(self::START_A, $symbols[0]);
        self::assertNotContains(self::SHIFT, $symbols);
        self::assertSame([33, 34, 73, 35, 36], array_slice($symbols, 1, 5));
    }

    public function testEveryValueRoundTripsThroughItsCheckSymbol(): void
    {
        foreach (['A', 'Wikipedia', '12345678', "\tx", 'abc-123_XYZ', str_repeat('9', 41)] as $value) {
            $symbols = Code128::symbols($value);
            $check = array_slice($symbols, -2, 1)[0];

            $sum = $symbols[0];

            foreach (array_slice($symbols, 1, count($symbols) - 3) as $index => $symbol) {
                $sum += $symbol * ($index + 1);
            }

            self::assertSame($sum % 103, $check, "check symbol for \"$value\"");
        }
    }

    // -- Elements ---------------------------------------------------------

    /**
     * Eleven modules for every symbol, and thirteen for the stop -- which
     * is what makes the extra bar at the end the end.
     */
    public function testEverySymbolIsElevenModulesAndTheStopIsThirteen(): void
    {
        $elements = Code128::elements('Wikipedia');
        $symbolCount = count(Code128::symbols('Wikipedia'));

        $total = array_sum(array_column($elements, 'widthModules'));

        self::assertSame((float) (11 * ($symbolCount - 1) + 13), $total);
    }

    public function testASymbolStartsAndEndsWithABar(): void
    {
        $elements = Code128::elements('12345678');

        self::assertTrue($elements[0]['isBar']);
        self::assertTrue($elements[count($elements) - 1]['isBar']);
    }

    public function testElementsAlternateBarAndSpaceWithinEachSymbol(): void
    {
        $elements = Code128::elements('A');

        // Three symbols of six elements (start, 'A', check) plus the
        // stop's seven.
        self::assertCount(3 * 6 + 7, $elements);

        foreach ($elements as $index => $element) {
            self::assertSame($index % 6 === 0 || $index % 6 === 2 || $index % 6 === 4, $element['isBar'], "element $index");
        }
    }

    // -- Refusals ---------------------------------------------------------

    public function testAnEmptyValueIsRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        Code128::symbols('');
    }

    public function testANonAsciiByteIsRefusedWithAPointerToTheAlternative(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('drawQrCode');

        Code128::symbols('café');
    }
}
