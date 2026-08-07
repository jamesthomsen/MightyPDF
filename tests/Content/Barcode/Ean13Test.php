<?php

declare(strict_types=1);

namespace MightyPDF\Tests\Content\Barcode;

use MightyPDF\Content\Barcode\Ean13;
use PHPUnit\Framework\TestCase;

final class Ean13Test extends TestCase
{
    /**
     * Two barcodes with published check digits: the standard's own
     * example, and one off a real product.
     */
    public function testCheckDigitsMatchPublishedExamples(): void
    {
        self::assertSame(7, Ean13::checkDigit('590123412345'));
        self::assertSame(1, Ean13::checkDigit('400638133393'));
        self::assertSame(0, Ean13::checkDigit('000000000000'));
    }

    public function testTwelveDigitsGetTheirCheckDigitComputed(): void
    {
        self::assertSame('5901234123457', Ean13::normalize('590123412345'));
    }

    public function testThirteenDigitsHaveTheirCheckDigitVerified(): void
    {
        self::assertSame('5901234123457', Ean13::normalize('5901234123457'));
    }

    /**
     * A wrong check digit is a barcode that scans as a different product,
     * so it is refused rather than quietly corrected -- and the message
     * says what the right one was, since the usual cause is a typo in a
     * number the caller believed.
     */
    public function testAWrongCheckDigitIsRefusedRatherThanCorrected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('it should be 7, not 3');

        Ean13::normalize('5901234123453');
    }

    public function testSpacesAndHyphensAreIgnoredBecauseThatIsHowTheyArePrinted(): void
    {
        self::assertSame('5901234123457', Ean13::normalize('5-901234-123457'));
        self::assertSame('5901234123457', Ean13::normalize('590123 412345'));

        // UPC-A too, which is printed with them just as often.
        self::assertSame(
            Ean13::upcAElements('036000291452'),
            Ean13::upcAElements('0 36000 29145 2'),
        );
    }

    public function testTheWrongNumberOfDigitsIsRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('twelve digits, or thirteen');

        Ean13::normalize('12345');
    }

    public function testLettersAreRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        Ean13::normalize('59012341234A');
    }

    // -- The symbol --------------------------------------------------------

    /** Always 95 modules, whatever the digits. */
    public function testTheSymbolIsAlwaysNinetyFiveModules(): void
    {
        foreach (['5901234123457', '0000000000000', '9999999999994'] as $value) {
            $total = array_sum(array_column(Ean13::elements($value), 'widthModules'));

            self::assertSame(95.0, $total, $value);
        }
    }

    public function testTheSymbolStartsAndEndsWithAGuardBar(): void
    {
        $elements = Ean13::elements('5901234123457');

        self::assertTrue($elements[0]['isBar']);
        self::assertSame(1.0, $elements[0]['widthModules']);
        self::assertTrue($elements[count($elements) - 1]['isBar']);
        self::assertSame(1.0, $elements[count($elements) - 1]['widthModules']);
    }

    public function testRunsAreCoalescedSoNoTwoAdjacentElementsShareAColour(): void
    {
        $elements = Ean13::elements('5901234123457');

        foreach ($elements as $index => $element) {
            if ($index === 0) {
                continue;
            }

            self::assertNotSame(
                $elements[$index - 1]['isBar'],
                $element['isBar'],
                "elements $index and " . ($index - 1) . ' should differ',
            );
        }
    }

    /**
     * The first digit is not drawn: it is carried in which of two
     * encodings each of the six left-hand digits uses. So two codes
     * differing only in their first digit must produce different bars --
     * which is the property that would silently fail if the parity table
     * were ignored.
     */
    public function testTheFirstDigitChangesTheBarsThroughParityAlone(): void
    {
        $zero = Ean13::elements('0901234123452');
        $five = Ean13::elements('5901234123457');

        self::assertNotEquals($zero, $five);

        // A leading 0 is all-odd parity, which is what UPC-A relies on.
        self::assertSame(
            Ean13::elements('0901234123452'),
            Ean13::upcAElements('901234123452'),
        );
    }

    public function testUpcATakesElevenDigitsAndComputesItsCheck(): void
    {
        self::assertSame(
            Ean13::elements('0036000291452'),
            Ean13::upcAElements('03600029145'),
        );
    }

    public function testUpcAAlsoTakesTwelveWithTheCheckDigitAlreadyOnIt(): void
    {
        self::assertSame(
            Ean13::elements('0036000291452'),
            Ean13::upcAElements('036000291452'),
        );
    }

    public function testUpcARefusesTheWrongLength(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('eleven digits, or twelve');

        Ean13::upcAElements('12345678901234');
    }
}
