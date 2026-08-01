<?php

declare(strict_types=1);

namespace MightyPDF\Tests\Reader\Filter;

use MightyPDF\Reader\Filter\DecodeParms;
use MightyPDF\Reader\Filter\Predictor;
use MightyPDF\Reader\ParseException;
use PHPUnit\Framework\TestCase;

final class PredictorTest extends TestCase
{
    public function testPredictorOneIsNoPrediction(): void
    {
        self::assertSame('untouched', Predictor::undo('untouched', new DecodeParms(predictor: 1)));
    }

    public function testUndoesPngNone(): void
    {
        self::assertSame(
            "\x0A\x14\x1E",
            Predictor::undo("\x00\x0A\x14\x1E", self::png(columns: 3)),
        );
    }

    public function testUndoesPngSub(): void
    {
        // Each byte is stored as its difference from the one before it in
        // the same row.
        self::assertSame(
            "\x0A\x1E\x3C",
            Predictor::undo("\x01\x0A\x14\x1E", self::png(columns: 3)),
        );
    }

    public function testUndoesPngUp(): void
    {
        // The predictor cross-reference streams are conventionally written
        // with: each byte is its difference from the byte above.
        $predicted = "\x02\x0A\x14\x1E" . "\x02\x05\x05\x05";

        self::assertSame(
            "\x0A\x14\x1E" . "\x0F\x19\x23",
            Predictor::undo($predicted, self::png(columns: 3)),
        );
    }

    public function testUndoesPngAverage(): void
    {
        // Each byte is its difference from the average of the one to its
        // left and the one above, rounded down.
        $predicted = "\x00\x0A\x14\x1E" . "\x03\x05\x07\x09";
        $decoded = Predictor::undo($predicted, self::png(columns: 3));

        self::assertSame("\x0A\x14\x1E", substr($decoded, 0, 3));
        self::assertSame(10, ord($decoded[3]));  // 5 + floor((left 0  + up 10) / 2)
        self::assertSame(22, ord($decoded[4]));  // 7 + floor((left 10 + up 20) / 2)
        self::assertSame(35, ord($decoded[5]));  // 9 + floor((left 22 + up 30) / 2)
    }

    public function testUndoesPngPaeth(): void
    {
        // With no row above, Paeth picks the left neighbour, so this
        // behaves exactly like Sub on the first row.
        self::assertSame(
            "\x0A\x1E\x3C",
            Predictor::undo("\x04\x0A\x14\x1E", self::png(columns: 3)),
        );
    }

    public function testCarriesPredictionAcrossMultiBytePixels(): void
    {
        // With 3 colours at 8 bits the predicting neighbour is 3 bytes
        // back, not 1 -- a red byte predicts the next red byte.
        $decoded = Predictor::undo(
            "\x01\x0A\x14\x1E\x01\x02\x03",
            new DecodeParms(predictor: 11, colors: 3, bitsPerComponent: 8, columns: 2),
        );

        self::assertSame("\x0A\x14\x1E\x0B\x16\x21", $decoded);
    }

    public function testTreatsASubBytePixelAsOneByteBack(): void
    {
        // At 1 bit per component consecutive pixels share a byte, so the
        // predictor works on the previous byte instead.
        $decoded = Predictor::undo(
            "\x01\x0F\x01",
            new DecodeParms(predictor: 11, colors: 1, bitsPerComponent: 1, columns: 16),
        );

        self::assertSame("\x0F\x10", $decoded);
    }

    public function testUndoesTiffPrediction(): void
    {
        self::assertSame(
            "\x0A\x1E\x3C",
            Predictor::undo("\x0A\x14\x1E", new DecodeParms(predictor: 2, columns: 3)),
        );
    }

    public function testPadsATruncatedFinalRowRatherThanFailing(): void
    {
        // Damage at the very end of a stream should not cost the whole
        // document -- in a cross-reference stream the padding reads back
        // as a free entry, which is ignored.
        $decoded = Predictor::undo("\x02\x0A\x14\x1E" . "\x02\x05", self::png(columns: 3));

        self::assertSame(6, strlen($decoded));
        self::assertSame("\x0F\x14\x1E", substr($decoded, 3));
    }

    public function testRejectsAnUnknownPngFilterType(): void
    {
        $this->expectException(ParseException::class);

        Predictor::undo("\x09\x00\x00\x00", self::png(columns: 3));
    }

    public function testRejectsTiffPredictionAtUnsupportedDepths(): void
    {
        $this->expectException(ParseException::class);
        $this->expectExceptionMessage('bits per component');

        Predictor::undo('anything', new DecodeParms(predictor: 2, bitsPerComponent: 4, columns: 2));
    }

    public function testRejectsARowWiderThanTheWholeStream(): void
    {
        // A denial-of-service vector: a tiny stream body with a /Columns
        // (here) large enough to demand a multi-hundred-megabyte buffer.
        // A predictor never emits more than it consumed, so a row wider
        // than the entire stream cannot be describing this data. It must
        // be refused before allocating -- a fatal out-of-memory is not
        // catchable, so "let it fail" is not an option.
        $this->expectException(ParseException::class);
        $this->expectExceptionMessage('does not describe this data');

        Predictor::undo(
            str_repeat("\x00", 16),
            new DecodeParms(predictor: 12, colors: 1, bitsPerComponent: 8, columns: 900_000_000),
        );
    }

    public function testColorsAndBitsPerComponentAreBoundedToo(): void
    {
        // The same over-allocation is reachable through /Colors and
        // /BitsPerComponent, not just /Columns, since row length is their
        // product.
        $this->expectException(ParseException::class);

        Predictor::undo(
            "\x00\x00",
            new DecodeParms(predictor: 12, colors: 100_000_000, bitsPerComponent: 16, columns: 1),
        );
    }

    public function testEmptyDataDecodesToEmpty(): void
    {
        self::assertSame('', Predictor::undo('', self::png(columns: 3)));
    }

    private static function png(int $columns): DecodeParms
    {
        return new DecodeParms(predictor: 12, colors: 1, bitsPerComponent: 8, columns: $columns);
    }
}
