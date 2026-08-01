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

    public function testFlateRejectsADecompressionBomb(): void
    {
        // A valid zlib stream that inflates to far more than the (small,
        // injected) cap. Left unbounded this is how a few hundred bytes
        // demand gigabytes of memory -- a fatal, uncatchable error.
        $bomb = gzcompress(str_repeat("\x00", 4 * 1024 * 1024));

        $this->expectException(ParseException::class);
        $this->expectExceptionMessage('decompression bomb');

        (new FlateDecode(1024))->decode($bomb, new DecodeParms());
    }

    public function testFlateRejectsARawDeflateBomb(): void
    {
        $bomb = gzdeflate(str_repeat("\x00", 4 * 1024 * 1024));

        $this->expectException(ParseException::class);
        $this->expectExceptionMessage('decompression bomb');

        (new FlateDecode(1024))->decode($bomb, new DecodeParms());
    }

    public function testFlateBoundsTheSalvagePathToo(): void
    {
        // A bomb truncated just before its end marker fails the whole-buffer
        // gzuncompress()/gzinflate() calls and falls through to salvage.
        // The salvage pass feeds its input in slices for exactly this
        // reason -- so the cap still applies and the bomb cannot simply
        // reappear there and exhaust memory.
        $raw = gzdeflate(str_repeat("\x00", 4 * 1024 * 1024));
        $truncated = substr($raw, 0, strlen($raw) - 20);

        $this->expectException(ParseException::class);
        $this->expectExceptionMessage('decompression bomb');

        (new FlateDecode(1024))->decode($truncated, new DecodeParms());
    }

    public function testFlateStillInflatesAStreamWithinTheCap(): void
    {
        // The cap must not get in the way of ordinary streams.
        self::assertSame('hello world', (new FlateDecode(1024))->decode(gzcompress('hello world'), new DecodeParms()));
    }

    public function testRunLengthRejectsAnExpansionBomb(): void
    {
        // Marker 0x81 repeats the next byte 128 times; a stream of them
        // expands ~64x, enough to blow past a real cap from a small input.
        $bomb = str_repeat("\x81\x41", 20_000);

        $this->expectException(ParseException::class);
        $this->expectExceptionMessage('decompression bomb');

        (new RunLengthDecode(1024))->decode($bomb, new DecodeParms());
    }

    public function testLzwRejectsAnExpansionBomb(): void
    {
        // clear(256), 'A'(65), then every table code as it is defined
        // (258, 259, ...) -- the classic run-of-one-character encoding,
        // whose table entries grow by a byte each and whose output grows
        // quadratically.
        $codes = [256, 65];

        for ($code = 258; $code < 4000; ++$code) {
            $codes[] = $code;
        }

        $this->expectException(ParseException::class);
        $this->expectExceptionMessage('decompression bomb');

        (new LzwDecode(1024))->decode(self::packLzwCodes($codes), new DecodeParms());
    }

    private static function flate(string $data): string
    {
        return (new FlateDecode())->decode($data, new DecodeParms());
    }

    /**
     * Packs a list of LZW codes into bytes at the same growing bit width
     * the decoder expects, so a test can hand-build a stream.
     *
     * @param list<int> $codes
     */
    private static function packLzwCodes(array $codes): string
    {
        $bits = '';
        $width = 9;
        $next = 258;

        foreach ($codes as $code) {
            $bits .= str_pad(decbin($code), $width, '0', STR_PAD_LEFT);

            if ($code === 256) {
                $width = 9;
                $next = 258;
            } elseif ($code !== 257) {
                ++$next;

                if ($next >= (1 << $width) - 1 && $width < 12) {
                    ++$width;
                }
            }
        }

        $bytes = '';

        for ($i = 0, $length = strlen($bits); $i < $length; $i += 8) {
            $bytes .= chr((int) bindec(str_pad(substr($bits, $i, 8), 8, '0')));
        }

        return $bytes;
    }
}
