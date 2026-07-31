<?php

declare(strict_types=1);

namespace MightyPDF\Tests\Reader\Filter;

use MightyPDF\Assembler\Dictionary;
use MightyPDF\Assembler\Stream;
use MightyPDF\Assembler\Types\PdfArray;
use MightyPDF\Assembler\Types\PdfInteger;
use MightyPDF\Assembler\Types\PdfName;
use MightyPDF\Assembler\Types\PdfReference;
use MightyPDF\Assembler\Types\PdfValue;
use MightyPDF\Reader\Filter\StreamDecoder;
use MightyPDF\Reader\ParseException;
use PHPUnit\Framework\TestCase;

final class StreamDecoderTest extends TestCase
{
    public function testAnUnfilteredStreamComesBackUnchanged(): void
    {
        self::assertSame('plain bytes', (new StreamDecoder())->decode(self::stream('plain bytes')));
    }

    public function testAppliesASingleFilter(): void
    {
        $stream = self::stream(gzcompress('content'))->set('Filter', new PdfName('FlateDecode'));

        self::assertSame('content', (new StreamDecoder())->decode($stream));
    }

    public function testAppliesAFilterChainInOrder(): void
    {
        // "ASCIIHex then Flate" -- the array order is the order the
        // decoder must undo them in, not the reverse.
        $stream = self::stream(bin2hex(gzcompress('content')) . '>')
            ->set('Filter', new PdfArray(new PdfName('ASCIIHexDecode'), new PdfName('FlateDecode')));

        self::assertSame('content', (new StreamDecoder())->decode($stream));
    }

    public function testLinesDecodeParmsUpWithTheRightFilter(): void
    {
        // A parameter meant for the second filter must not be handed to
        // the first: /Predictor applied to the ASCIIHex stage would
        // mangle it.
        $predicted = "\x02\x0A\x14\x1E" . "\x02\x05\x05\x05";
        $stream = self::stream(bin2hex(gzcompress($predicted)) . '>')
            ->set('Filter', new PdfArray(new PdfName('ASCIIHexDecode'), new PdfName('FlateDecode')))
            ->set('DecodeParms', new PdfArray(
                new Dictionary(),
                (new Dictionary())->set('Predictor', new PdfInteger(12))->set('Columns', new PdfInteger(3)),
            ));

        self::assertSame("\x0A\x14\x1E\x0F\x19\x23", (new StreamDecoder())->decode($stream));
    }

    public function testASingleDecodeParmsDictionaryBelongsToTheFirstFilter(): void
    {
        $predicted = "\x02\x0A\x14\x1E";
        $stream = self::stream(gzcompress($predicted))
            ->set('Filter', new PdfName('FlateDecode'))
            ->set('DecodeParms', (new Dictionary())
                ->set('Predictor', new PdfInteger(12))
                ->set('Columns', new PdfInteger(3)));

        self::assertSame("\x0A\x14\x1E", (new StreamDecoder())->decode($stream));
    }

    public function testResolvesAnIndirectFilterName(): void
    {
        $stream = self::stream(gzcompress('content'))->set('Filter', new PdfReference(7));

        $decoder = new StreamDecoder(static fn (?PdfValue $value): ?PdfValue => $value instanceof PdfReference
            ? new PdfName('FlateDecode')
            : $value);

        self::assertSame('content', $decoder->decode($stream));
    }

    public function testNamesAnImageFilterRatherThanReturningItsEncodedBytes(): void
    {
        // Handing back JPEG data labelled as decoded content would let the
        // caller's misunderstanding travel.
        $stream = self::stream('jpeg bytes')->set('Filter', new PdfName('DCTDecode'));

        $this->expectException(ParseException::class);
        $this->expectExceptionMessage('/DCTDecode');

        (new StreamDecoder())->decode($stream);
    }

    public function testRejectsAnUnknownFilter(): void
    {
        $stream = self::stream('x')->set('Filter', new PdfName('MadeUpDecode'));

        $this->expectException(ParseException::class);
        $this->expectExceptionMessage('Unknown stream filter /MadeUpDecode');

        (new StreamDecoder())->decode($stream);
    }

    public function testCanDecodeReportsWithoutThrowing(): void
    {
        $decoder = new StreamDecoder();

        self::assertTrue($decoder->canDecode(self::stream('x')->set('Filter', new PdfName('FlateDecode'))));
        self::assertFalse($decoder->canDecode(self::stream('x')->set('Filter', new PdfName('DCTDecode'))));
    }

    private static function stream(string $bytes): Stream
    {
        // compress: false -- these stand in for streams read from a file,
        // whose bytes are already in their stored, encoded form.
        return new Stream(1, $bytes, false);
    }
}
