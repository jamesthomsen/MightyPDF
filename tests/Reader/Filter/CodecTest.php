<?php

declare(strict_types=1);

namespace MightyPDF\Tests\Reader\Filter;

use MightyPDF\Reader\Filter\Ascii85Decode;
use MightyPDF\Reader\Filter\AsciiHexDecode;
use MightyPDF\Reader\Filter\DecodeParms;
use MightyPDF\Reader\Filter\FlateDecode;
use MightyPDF\Reader\Filter\LzwDecode;
use MightyPDF\Reader\Filter\RunLengthDecode;
use MightyPDF\Reader\ParseException;
use PHPUnit\Framework\TestCase;

/**
 * The individual stream codecs. Predictor handling, which Flate and LZW
 * both delegate to, has its own test.
 */
final class CodecTest extends TestCase
{
    public function testInflatesAZlibStream(): void
    {
        self::assertSame('hello world', self::flate(gzcompress('hello world')));
    }

    public function testInflatesRawDeflateWithNoZlibHeader(): void
    {
        // Not legal, but produced in the wild.
        self::assertSame('hello world', self::flate(gzdeflate('hello world')));
    }

    public function testInflatesPastLeadingWhitespace(): void
    {
        self::assertSame('hello world', self::flate("\n " . gzcompress('hello world')));
    }

    public function testSalvagesATruncatedStream(): void
    {
        // A stream cut short is still almost entirely readable, and a page
        // missing its last few operators beats a document that will not
        // open at all.
        $complete = gzcompress(str_repeat('MightyPDF ', 200));
        $salvaged = self::flate(substr($complete, 0, intdiv(strlen($complete), 2)));

        self::assertNotSame('', $salvaged);
        self::assertStringStartsWith('MightyPDF MightyPDF ', $salvaged);
    }

    public function testRejectsDataThatIsNotDeflateAtAll(): void
    {
        $this->expectException(ParseException::class);
        $this->expectExceptionMessage('could not be inflated');

        self::flate('this is definitely not compressed data');
    }

    public function testDecodesAsciiHex(): void
    {
        self::assertSame('Hello', (new AsciiHexDecode())->decode('48656C6C6F>', new DecodeParms()));
    }

    public function testAsciiHexIgnoresWhitespaceAndPadsAnOddDigit(): void
    {
        self::assertSame("\xAB\xC0", (new AsciiHexDecode())->decode("A B\nC>", new DecodeParms()));
    }

    public function testAsciiHexStopsAtTheEndMarker(): void
    {
        self::assertSame('Hi', (new AsciiHexDecode())->decode('4869> 4142', new DecodeParms()));
    }

    public function testDecodesAscii85(): void
    {
        self::assertSame('Man sure', (new Ascii85Decode())->decode('9jqo^F*2M7~>', new DecodeParms()));
    }

    public function testAscii85TreatsZAsFourZeroBytes(): void
    {
        self::assertSame("\x00\x00\x00\x00", (new Ascii85Decode())->decode('z~>', new DecodeParms()));
    }

    public function testAscii85HandlesAShortFinalGroup(): void
    {
        // A partial group of n characters encodes n-1 bytes.
        self::assertSame('Man', (new Ascii85Decode())->decode('9jqo~>', new DecodeParms()));
    }

    public function testAscii85AcceptsTheOpeningDelimiter(): void
    {
        self::assertSame('Man sure', (new Ascii85Decode())->decode('<~9jqo^F*2M7~>', new DecodeParms()));
    }

    public function testDecodesRunLengthLiterals(): void
    {
        // Marker 2 means "the next 3 bytes are literal".
        self::assertSame('abc', (new RunLengthDecode())->decode("\x02abc\x80", new DecodeParms()));
    }

    public function testDecodesRunLengthRuns(): void
    {
        // Marker 254 means "repeat the next byte 257 - 254 = 3 times".
        self::assertSame('xxx', (new RunLengthDecode())->decode("\xFEx\x80", new DecodeParms()));
    }

    public function testRunLengthStopsAtTheEndOfDataMarker(): void
    {
        self::assertSame('ab', (new RunLengthDecode())->decode("\x01ab\x80\x01zz", new DecodeParms()));
    }

    public function testDecodesLzw(): void
    {
        // Hand-built 9-bit codes: clear(256), 'A'(65), 'B'(66), EOD(257).
        self::assertSame('AB', (new LzwDecode())->decode("\x80\x10\x48\x50\x10", new DecodeParms()));
    }

    public function testDecodesAnLzwCodeUsedOneStepBeforeItIsDefined(): void
    {
        // clear(256), 'A'(65), 258, EOD(257) -- where 258 is only defined
        // by the very code that uses it. The encoder is allowed to do this
        // when a sequence is immediately followed by its own first
        // character, and a decoder that does not handle it desynchronises.
        self::assertSame('AAA', (new LzwDecode())->decode("\x80\x10\x60\x50\x10", new DecodeParms()));
    }

    private static function flate(string $data): string
    {
        return (new FlateDecode())->decode($data, new DecodeParms());
    }
}
