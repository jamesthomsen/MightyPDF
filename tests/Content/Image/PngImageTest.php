<?php

declare(strict_types=1);

namespace MightyPDF\Tests\Content\Image;

use MightyPDF\Assembler\IndirectObjectRegistry;
use MightyPDF\Content\Image\PngImage;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class PngImageTest extends TestCase
{
    private const string FIXTURES = __DIR__ . '/../../fixtures/images';

    public function testTruecolorPngProducesCorrectDictionaryEntries(): void
    {
        $rendered = PngImage::fromFile(new IndirectObjectRegistry(), self::FIXTURES . '/sample.png')->render(true);

        self::assertStringContainsString('/Width 4', $rendered);
        self::assertStringContainsString('/Height 4', $rendered);
        self::assertStringContainsString('/BitsPerComponent 8', $rendered);
        self::assertStringContainsString('/ColorSpace /DeviceRGB', $rendered);
        self::assertStringContainsString('/Filter /FlateDecode', $rendered);
        self::assertStringContainsString('/Predictor 15', $rendered);
        self::assertStringContainsString('/Colors 3', $rendered);
        self::assertStringContainsString('/Columns 4', $rendered);
    }

    public function testTruecolorPngIdatInflatesToOneFilterBytePlusPixelsPerRow(): void
    {
        $rendered = PngImage::fromFile(new IndirectObjectRegistry(), self::FIXTURES . '/sample.png')->render(true);

        preg_match('/stream\n(.*?)\nendstream/s', $rendered, $matches);
        $inflated = gzuncompress($matches[1]);

        // Every PNG scanline is exactly [1 filter-type byte][width * 3
        // RGB bytes], regardless of which of the 5 predictor algorithms
        // was used for that row -- true for all filter types, so this
        // length check doesn't depend on knowing which one PIL picked.
        self::assertSame(4 * (1 + 4 * 3), strlen($inflated));
    }

    public function testIndexedPngIncludesThePaletteAsAnIndexedColorSpace(): void
    {
        $rendered = PngImage::fromFile(new IndirectObjectRegistry(), self::FIXTURES . '/sample-indexed.png')->render(true);

        self::assertStringContainsString('/BitsPerComponent 4', $rendered);
        self::assertStringContainsString('/Colors 1', $rendered);
        self::assertStringContainsString(
            '/ColorSpace [/Indexed /DeviceRGB 15 '
                . '<ffffffffff0000ffff00ff00009600c864328080805a5a5a404040ff00ffff00002c00000a141e0000ff000096000000>]',
            $rendered,
        );
    }

    /**
     * sample-alpha.png is a hand-built 2x2 truecolor+alpha (color type 6)
     * PNG: opaque red, half-transparent green, fully transparent blue,
     * mostly-transparent yellow -- see the regeneration snippet below.
     * Regenerate with:
     *   python3 -c "
     *   from PIL import Image
     *   im = Image.new('RGBA', (2, 2))
     *   im.putdata([(255,0,0,255), (0,255,0,128), (0,0,255,0), (255,255,0,64)])
     *   im.save('sample-alpha.png')"
     */
    public function testAlphaPngSplitsIntoAColorStreamAndADeviceGraySmask(): void
    {
        $registry = new IndirectObjectRegistry();
        $image = PngImage::fromFile($registry, self::FIXTURES . '/sample-alpha.png');
        $imageRendered = $image->render(true);

        // buildAlphaImage() allocates the color image first, the SMask
        // second, so with a fresh registry these ids are deterministic.
        self::assertStringContainsString('/Width 2', $imageRendered);
        self::assertStringContainsString('/Height 2', $imageRendered);
        self::assertStringContainsString('/BitsPerComponent 8', $imageRendered);
        self::assertStringContainsString('/ColorSpace /DeviceRGB', $imageRendered);
        self::assertStringContainsString('/SMask 2 0 R', $imageRendered);

        preg_match('/stream\n(.*?)\nendstream/s', $imageRendered, $matches);
        $colorBytes = gzuncompress($matches[1]);
        self::assertSame(
            "\xFF\x00\x00" . "\x00\xFF\x00" . "\x00\x00\xFF" . "\xFF\xFF\x00",
            $colorBytes,
        );

        // The color object (id 1) was never registered with $registry --
        // only the SMask registers itself, as PngImage's own side effect
        // (see its class doc comment) -- so writeAll() here serializes
        // just the SMask object, object id 2.
        $maskBody = $registry->writeAll('')->bytes;

        self::assertStringContainsString('2 0 obj', $maskBody);
        self::assertStringContainsString('/Width 2', $maskBody);
        self::assertStringContainsString('/Height 2', $maskBody);
        self::assertStringContainsString('/BitsPerComponent 8', $maskBody);
        self::assertStringContainsString('/ColorSpace /DeviceGray', $maskBody);

        preg_match('/stream\n(.*?)\nendstream/s', $maskBody, $maskMatches);
        $alphaBytes = gzuncompress($maskMatches[1]);
        self::assertSame("\xFF\x80\x00\x40", $alphaBytes);
    }

    /**
     * sample-interlaced.png is a hand-built 5x5 truecolor (color type 2),
     * 8-bit, Adam7-interlaced PNG with a deterministic pattern --
     * R = x*50, G = y*50, B = (x+y)*25, each mod 256 -- built (and
     * independently cross-checked against Pillow's Adam7 reader) by a
     * small zlib-based encoder, since Pillow itself can only read
     * interlaced PNGs, not write them. 5x5 (not a multiple of 8) is
     * deliberate: it exercises Adam7 passes whose sub-image dimensions
     * don't divide evenly, which is where off-by-one errors in pass
     * geometry show up.
     */
    public function testInterlacedTruecolorPngDeinterlacesToTheDeclaredPattern(): void
    {
        $rendered = PngImage::fromFile(new IndirectObjectRegistry(), self::FIXTURES . '/sample-interlaced.png')->render(true);

        self::assertStringContainsString('/Width 5', $rendered);
        self::assertStringContainsString('/Height 5', $rendered);
        self::assertStringContainsString('/BitsPerComponent 8', $rendered);
        self::assertStringContainsString('/ColorSpace /DeviceRGB', $rendered);
        // De-interlaced output is plain row-major samples, so unlike the
        // non-interlaced verbatim-relay path, there's no PNG Predictor.
        self::assertStringNotContainsString('/Predictor', $rendered);

        preg_match('/stream\n(.*?)\nendstream/s', $rendered, $matches);
        $pixels = gzuncompress($matches[1]);

        self::assertSame(5 * 5 * 3, strlen($pixels));

        for ($y = 0; $y < 5; $y++) {
            for ($x = 0; $x < 5; $x++) {
                $offset = ($y * 5 + $x) * 3;
                self::assertSame(($x * 50) % 256, ord($pixels[$offset]), "R at ($x,$y)");
                self::assertSame(($y * 50) % 256, ord($pixels[$offset + 1]), "G at ($x,$y)");
                self::assertSame((($x + $y) * 25) % 256, ord($pixels[$offset + 2]), "B at ($x,$y)");
            }
        }
    }

    /**
     * sample-interlaced-alpha.png: a 4x4 truecolor+alpha (color type 6),
     * 8-bit, Adam7-interlaced PNG -- same construction as
     * sample-interlaced.png above, but exercising de-interlacing and
     * alpha-splitting together. Pattern: R = x*60, G = y*60,
     * B = (x+y)*30, A = x*17 + y*5, each mod 256.
     */
    public function testInterlacedAlphaPngDeinterlacesThenSplitsIntoColorAndSmask(): void
    {
        $registry = new IndirectObjectRegistry();
        $imageRendered = PngImage::fromFile($registry, self::FIXTURES . '/sample-interlaced-alpha.png')->render(true);

        self::assertStringContainsString('/Width 4', $imageRendered);
        self::assertStringContainsString('/Height 4', $imageRendered);
        self::assertStringContainsString('/ColorSpace /DeviceRGB', $imageRendered);
        self::assertStringContainsString('/SMask 2 0 R', $imageRendered);

        preg_match('/stream\n(.*?)\nendstream/s', $imageRendered, $matches);
        $colorBytes = gzuncompress($matches[1]);

        $maskBody = $registry->writeAll('')->bytes;
        preg_match('/stream\n(.*?)\nendstream/s', $maskBody, $maskMatches);
        $alphaBytes = gzuncompress($maskMatches[1]);

        for ($y = 0; $y < 4; $y++) {
            for ($x = 0; $x < 4; $x++) {
                $pixel = ($y * 4 + $x);
                self::assertSame(($x * 60) % 256, ord($colorBytes[$pixel * 3]), "R at ($x,$y)");
                self::assertSame(($y * 60) % 256, ord($colorBytes[$pixel * 3 + 1]), "G at ($x,$y)");
                self::assertSame((($x + $y) * 30) % 256, ord($colorBytes[$pixel * 3 + 2]), "B at ($x,$y)");
                self::assertSame(($x * 17 + $y * 5) % 256, ord($alphaBytes[$pixel]), "A at ($x,$y)");
            }
        }
    }

    /**
     * sample-alpha-16bit.png: the same 2x2 truecolor+alpha pixels as
     * sample-alpha.png above, re-encoded at 16 bits per channel instead
     * of 8 (opaque red, half-transparent green, fully transparent blue,
     * mostly-transparent yellow), to confirm 16-bit samples survive the
     * split intact -- 2 bytes per channel, big-endian, matching PNG's own
     * sample byte order.
     */
    public function test16BitAlphaPngPreservesFullSamplePrecision(): void
    {
        $registry = new IndirectObjectRegistry();
        $imageRendered = PngImage::fromFile($registry, self::FIXTURES . '/sample-alpha-16bit.png')->render(true);

        self::assertStringContainsString('/BitsPerComponent 16', $imageRendered);
        self::assertStringContainsString('/ColorSpace /DeviceRGB', $imageRendered);
        self::assertStringContainsString('/SMask 2 0 R', $imageRendered);

        preg_match('/stream\n(.*?)\nendstream/s', $imageRendered, $matches);
        $colorBytes = gzuncompress($matches[1]);
        self::assertSame(
            "\xFF\xFF\x00\x00\x00\x00" . "\x00\x00\xFF\xFF\x00\x00" . "\x00\x00\x00\x00\xFF\xFF" . "\xFF\xFF\xFF\xFF\x00\x00",
            $colorBytes,
        );

        $maskBody = $registry->writeAll('')->bytes;
        self::assertStringContainsString('/BitsPerComponent 16', $maskBody);
        preg_match('/stream\n(.*?)\nendstream/s', $maskBody, $maskMatches);
        $alphaBytes = gzuncompress($maskMatches[1]);
        self::assertSame("\xFF\xFF\x80\x80\x00\x00\x40\x40", $alphaBytes);
    }

    public function testReadsAnInterlacedSubByteImage(): void
    {
        $image = PngImage::fromFile(new IndirectObjectRegistry(), self::FIXTURES . '/sample-interlaced-4bit.png');

        // Widened to a byte per pixel: packing would mean writing single
        // bits into bytes shared with other Adam7 passes.
        self::assertSame(8, $image->get('BitsPerComponent')?->value());
        self::assertSame("\x00", $image->rawBytes(), 'a 1x1 image of palette index 0');
    }

    /**
     * A pattern chosen so that every one of the seven Adam7 passes
     * contributes, and so that a nibble read from the wrong half of a byte
     * shows up as a shifted diagonal rather than as nothing.
     */
    public function testDeinterlacesEverySubBytePassIntoTheRightPixels(): void
    {
        $width = 8;
        $height = 8;
        $pixels = [];

        for ($y = 0; $y < $height; ++$y) {
            for ($x = 0; $x < $width; ++$x) {
                $pixels[$y][$x] = $x === $y
                    ? 1
                    : (($x === 0 || $y === 0 || $x === $width - 1 || $y === $height - 1) ? 2 : 0);
            }
        }

        $png = self::interlacedPng($pixels, bitDepth: 4, colorType: 3);
        $image = PngImage::fromBytes(new IndirectObjectRegistry(), $png);

        self::assertSame(self::flatten($pixels), $image->rawBytes());
    }

    /**
     * Grayscale samples have to be scaled to fill a byte -- a 1-bit
     * sample means black or white, not 0 or 1 -- while indexed samples
     * are palette positions and must be left exactly as they are.
     *
     * @param int $bitDepth 1, 2 or 4
     */
    #[DataProvider('subByteDepths')]
    public function testScalesGrayscaleSamplesButNotPaletteIndices(int $bitDepth): void
    {
        $maximum = (1 << $bitDepth) - 1;
        $pixels = [];

        for ($y = 0; $y < 8; ++$y) {
            for ($x = 0; $x < 8; ++$x) {
                $pixels[$y][$x] = ($x + $y) % ($maximum + 1);
            }
        }

        $gray = PngImage::fromBytes(new IndirectObjectRegistry(), self::interlacedPng($pixels, $bitDepth, 0));
        $indexed = PngImage::fromBytes(new IndirectObjectRegistry(), self::interlacedPng($pixels, $bitDepth, 3));

        $scale = intdiv(255, $maximum);

        self::assertSame(
            implode('', array_map(static fn (int $v): string => chr($v * $scale), self::samples($pixels))),
            $gray->rawBytes(),
            'grayscale is scaled to fill a byte',
        );

        self::assertSame(self::flatten($pixels), $indexed->rawBytes(), 'indices are left alone');
    }

    /** @return iterable<string, array{int}> */
    public static function subByteDepths(): iterable
    {
        yield '1 bit' => [1];
        yield '2 bits' => [2];
        yield '4 bits' => [4];
    }

    /** @param array<int, array<int, int>> $pixels */
    private static function flatten(array $pixels): string
    {
        return implode('', array_map(chr(...), self::samples($pixels)));
    }

    /**
     * @param array<int, array<int, int>> $pixels
     * @return list<int>
     */
    private static function samples(array $pixels): array
    {
        $out = [];

        foreach ($pixels as $row) {
            foreach ($row as $value) {
                $out[] = $value;
            }
        }

        return $out;
    }

    /**
     * Encodes $pixels as an Adam7-interlaced PNG at a sub-byte depth --
     * written here rather than borrowed from the decoder, so that the two
     * cannot agree on the same mistake.
     *
     * @param array<int, array<int, int>> $pixels row-major sample values
     */
    private static function interlacedPng(array $pixels, int $bitDepth, int $colorType): string
    {
        $height = count($pixels);
        $width = count($pixels[0]);
        $samplesPerByte = intdiv(8, $bitDepth);
        $raw = '';

        foreach (self::ADAM7 as [$startX, $startY, $stepX, $stepY]) {
            $passWidth = $width > $startX ? intdiv($width - $startX + $stepX - 1, $stepX) : 0;
            $passHeight = $height > $startY ? intdiv($height - $startY + $stepY - 1, $stepY) : 0;

            if ($passWidth === 0 || $passHeight === 0) {
                continue;
            }

            for ($py = 0; $py < $passHeight; ++$py) {
                $row = '';
                $accumulator = 0;
                $filled = 0;

                for ($px = 0; $px < $passWidth; ++$px) {
                    $accumulator = ($accumulator << $bitDepth) | $pixels[$startY + $py * $stepY][$startX + $px * $stepX];

                    if (++$filled === $samplesPerByte) {
                        $row .= chr($accumulator);
                        $accumulator = 0;
                        $filled = 0;
                    }
                }

                if ($filled > 0) {
                    $row .= chr($accumulator << ($bitDepth * ($samplesPerByte - $filled)));
                }

                // Filter type 0, None -- the filters themselves have their
                // own tests.
                $raw .= "\x00" . $row;
            }
        }

        $ihdr = pack('NN', $width, $height) . chr($bitDepth) . chr($colorType) . "\x00\x00\x01";
        $png = "\x89PNG\r\n\x1a\n" . self::chunk('IHDR', $ihdr);

        if ($colorType === 3) {
            $palette = '';

            for ($i = 0; $i < (1 << $bitDepth); ++$i) {
                $palette .= chr($i) . chr($i) . chr($i);
            }

            $png .= self::chunk('PLTE', $palette);
        }

        return $png . self::chunk('IDAT', (string) gzcompress($raw)) . self::chunk('IEND', '');
    }

    /** PNG spec §8.2: startX, startY, stepX, stepY per pass. */
    private const array ADAM7 = [
        [0, 0, 8, 8], [4, 0, 8, 8], [0, 4, 4, 8], [2, 0, 4, 4],
        [0, 2, 2, 4], [1, 0, 2, 2], [0, 1, 1, 2],
    ];

    public function testRejectsNonPngData(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        PngImage::fromBytes(new IndirectObjectRegistry(), 'not a png');
    }

    public function testRejectsTruncatedIhdrChunk(): void
    {
        $png = "\x89PNG\r\n\x1a\n" . self::chunk('IHDR', pack('NN', 1, 1));

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('IHDR chunk is truncated');
        PngImage::fromBytes(new IndirectObjectRegistry(), $png);
    }

    /**
     * Declared dimensions drive a str_repeat() of the whole pixel buffer
     * before any IDAT is examined, so the cap has to be in bytes, not
     * pixels: 9999x10000 is under the 100-megapixel limit but is 762MB at
     * 16-bit RGBA. Memory exhaustion is a fatal error, not a catchable
     * exception, so this must be rejected up front.
     */
    public function testRejectsDimensionsThatWouldExceedTheDecodedByteLimit(): void
    {
        $ihdr = pack('NN', 9999, 10000) . chr(16) . chr(6) . chr(0) . chr(0) . chr(1);
        $png = "\x89PNG\r\n\x1a\n"
            . self::chunk('IHDR', $ihdr)
            . self::chunk('IDAT', gzcompress(str_repeat("\x00", 64)));

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('over the');
        PngImage::fromBytes(new IndirectObjectRegistry(), $png);
    }

    /**
     * A 1x1 image whose IDAT inflates to hundreds of megabytes: declared
     * dimensions say nothing about how much data the zlib stream holds,
     * so the inflate itself needs an explicit ceiling.
     */
    public function testRejectsIdatThatInflatesFarBeyondTheDeclaredDimensions(): void
    {
        $deflate = deflate_init(ZLIB_ENCODING_DEFLATE);
        $chunkOfZeros = str_repeat("\x00", 1024 * 1024);
        $idat = '';
        for ($i = 0; $i < 64; $i++) {
            $idat .= deflate_add($deflate, $chunkOfZeros, ZLIB_NO_FLUSH);
        }
        $idat .= deflate_add($deflate, '', ZLIB_FINISH);

        $ihdr = pack('NN', 1, 1) . chr(8) . chr(6) . chr(0) . chr(0) . chr(0);
        $png = "\x89PNG\r\n\x1a\n" . self::chunk('IHDR', $ihdr) . self::chunk('IDAT', $idat);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Failed to inflate PNG IDAT data');
        PngImage::fromBytes(new IndirectObjectRegistry(), $png);
    }

    private static function chunk(string $type, string $data): string
    {
        return pack('N', strlen($data)) . $type . $data . pack('N', crc32($type . $data));
    }
}
