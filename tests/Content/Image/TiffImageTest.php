<?php

declare(strict_types=1);

namespace MightyPDF\Tests\Content\Image;

use MightyPDF\Assembler\Document;
use MightyPDF\Assembler\Stream;
use MightyPDF\Assembler\Types\PdfArray;
use MightyPDF\Content\Image\TiffDirectory;
use MightyPDF\Content\Image\TiffImage;
use PHPUnit\Framework\TestCase;

/**
 * The fixtures here are built in the test rather than checked in, so what
 * each one contains is visible next to the assertion about it.
 *
 * Every variant this covers was also rendered and compared pixel for pixel
 * against the original raster: interiors matched exactly, with differences
 * only on antialiased edges.
 */
final class TiffImageTest extends TestCase
{
    public function testRelaysAGroup4StripWithoutDecodingIt(): void
    {
        // The point of the whole class. A fax-coded strip is already what
        // /CCITTFaxDecode expects, so a scan embeds as the bytes it
        // arrived as -- no decode, no re-encode, no generation loss, and
        // a file that stays the size the scanner made it.
        $strip = self::faxStrip();
        $image = TiffImage::fromBytes(new Document(), self::tiff(compression: 4, strip: $strip));

        self::assertSame($strip, $image->rawBytes());
        self::assertSame('/CCITTFaxDecode', $image->get('Filter')?->format());
    }

    public function testDescribesTheFaxCodingToTheFilter(): void
    {
        $image = TiffImage::fromBytes(new Document(), self::tiff(compression: 4, strip: self::faxStrip()));
        $parms = $image->get('DecodeParms');

        self::assertNotNull($parms);
        self::assertStringContainsString('/Columns 16', $parms->format());
        self::assertStringContainsString('/Rows 4', $parms->format());

        // K negative is pure two-dimensional coding, which is what Group 4 is.
        self::assertStringContainsString('/K -1', $parms->format());
    }

    public function testGroup3IsOneDimensionalUnlessItSaysOtherwise(): void
    {
        $plain = TiffImage::fromBytes(new Document(), self::tiff(compression: 3, strip: self::faxStrip()));
        self::assertStringContainsString('/K 0', $plain->get('DecodeParms')?->format() ?? '');

        // T4Options bit 1 is "two-dimensional coding used".
        $mixed = TiffImage::fromBytes(
            new Document(),
            self::tiff(compression: 3, strip: self::faxStrip(), extra: [292 => 1]),
        );
        self::assertStringContainsString('/K 1', $mixed->get('DecodeParms')?->format() ?? '');
    }

    public function testFaxPolarityFollowsThePhotometricTag(): void
    {
        // WhiteIsZero is what CCITT assumes and what nearly every fax
        // declares, so the filter's default is right and nothing is said.
        $standard = TiffImage::fromBytes(
            new Document(),
            self::tiff(compression: 4, strip: self::faxStrip(), photometric: 0),
        );
        self::assertStringNotContainsString('BlackIs1', $standard->get('DecodeParms')?->format() ?? '');

        // A file declaring BlackIsZero has had the convention flipped
        // underneath the coder. Getting this wrong gives a page of white
        // text on black -- unmistakable, but only if somebody looks.
        $flipped = TiffImage::fromBytes(
            new Document(),
            self::tiff(compression: 4, strip: self::faxStrip(), photometric: 1),
        );
        self::assertStringContainsString('/BlackIs1 true', $flipped->get('DecodeParms')?->format() ?? '');
    }

    public function testRefusesAFaxSplitAcrossSeveralStrips(): void
    {
        // Each strip is coded independently, so concatenating them
        // produces a stream that decodes correctly until the second strip
        // and then to noise -- exactly the failure worth refusing.
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('coded independently');

        TiffImage::fromBytes(new Document(), self::tiff(
            compression: 4,
            strip: self::faxStrip(),
            secondStrip: self::faxStrip(),
        ));
    }

    public function testReadsAnUncompressedGrayscaleImage(): void
    {
        $pixels = '';

        for ($i = 0; $i < 64; ++$i) {
            $pixels .= chr($i * 4);
        }

        $image = TiffImage::fromBytes(new Document(), self::tiff(
            compression: 1,
            strip: $pixels,
            width: 8,
            height: 8,
            bits: 8,
            photometric: 1,
        ));

        self::assertSame('/DeviceGray', $image->get('ColorSpace')?->format());
        self::assertSame('8', $image->get('BitsPerComponent')?->format());
        self::assertSame($pixels, $image->rawBytes());
    }

    public function testInvertsAWhiteIsZeroImageWithDecodeRatherThanPixels(): void
    {
        $image = TiffImage::fromBytes(new Document(), self::tiff(
            compression: 1,
            strip: str_repeat("\x40", 64),
            width: 8,
            height: 8,
            bits: 8,
            photometric: 0,
        ));

        // /Decode reverses the samples without touching one of them.
        self::assertSame('[1 0]', $image->get('Decode')?->format());
        self::assertSame(str_repeat("\x40", 64), $image->rawBytes());
    }

    public function testReadsPackBits(): void
    {
        // PackBits is byte for byte PDF's own RunLengthDecode.
        $packed = "\xFD\x41\x02\x01\x02\x03";  // 4x'A', then 1,2,3
        $image = TiffImage::fromBytes(new Document(), self::tiff(
            compression: 32_773,
            strip: $packed,
            width: 7,
            height: 1,
            bits: 8,
            photometric: 1,
        ));

        self::assertSame("AAAA\x01\x02\x03", $image->rawBytes());
    }

    public function testReadsDeflate(): void
    {
        $pixels = str_repeat("\x10\x20\x30\x40", 16);
        $image = TiffImage::fromBytes(new Document(), self::tiff(
            compression: 8,
            strip: gzcompress($pixels),
            width: 8,
            height: 8,
            bits: 8,
            photometric: 1,
        ));

        self::assertSame($pixels, $image->rawBytes());
    }

    public function testBuildsAnIndexedColourSpaceFromAColourMap(): void
    {
        // TIFF stores the map as all the reds, then all the greens, then
        // all the blues, in 16-bit values. PDF wants them interleaved and
        // eight-bit -- and missing the run layout gives a red-tinted
        // image rather than an error.
        $map = [0xFFFF, 0x0000, 0x0000, 0xFFFF, 0x0000, 0x0000];

        $image = TiffImage::fromBytes(new Document(), self::tiff(
            compression: 1,
            strip: "\x00\x01",
            width: 2,
            height: 1,
            bits: 8,
            photometric: 3,
            colorMap: $map,
        ));

        $colorSpace = $image->get('ColorSpace');

        self::assertInstanceOf(PdfArray::class, $colorSpace);

        // Two entries: red, then green -- interleaved and narrowed to a
        // byte each, from three separate 16-bit runs.
        self::assertSame('[/Indexed /DeviceRGB 1 <ff000000ff00>]', $colorSpace->format());
    }

    public function testReadsEveryImageInTheFile(): void
    {
        $bytes = self::multiPage();

        self::assertSame(2, TiffImage::pageCount($bytes));
        self::assertSame('16', TiffImage::fromBytes(new Document(), $bytes, 0)->get('Width')?->format());
        self::assertSame('8', TiffImage::fromBytes(new Document(), $bytes, 1)->get('Width')?->format());
    }

    public function testRefusesAnImageThatIsNotThere(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('holds 2 images, numbered 0 to 1; there is no image 5');

        TiffImage::fromBytes(new Document(), self::multiPage(), 5);
    }

    public function testRefusesSomethingThatIsNotATiff(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('begins with neither "II" nor "MM"');

        TiffImage::fromBytes(new Document(), 'not a tiff at all, but long enough');
    }

    public function testNamesBigTiffRatherThanReportingRubbish(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('43 is BigTIFF');

        TiffImage::fromBytes(new Document(), "II\x2B\x00\x08\x00\x00\x00");
    }

    public function testRefusesJpegInTiff(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('JPEG-compressed');

        TiffImage::fromBytes(new Document(), self::tiff(compression: 7, strip: 'x'));
    }

    public function testRefusesSeparatedPlanes(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('colour planes separately');

        TiffImage::fromBytes(new Document(), self::tiff(compression: 1, strip: 'x', extra: [284 => 2]));
    }

    public function testRefusesAnImplausiblyLargeImage(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('more than this library will allocate');

        TiffImage::fromBytes(new Document(), self::tiff(
            compression: 1,
            strip: 'x',
            width: 100_000,
            height: 100_000,
        ));
    }

    /**
     * A pixel count is not a byte count. MAX_PIXELS bounds width times
     * height and nothing else, so /SamplesPerPixel is the third factor
     * that decides how big the sample buffer is -- and a hundred-byte
     * file declaring one pixel and four billion samples asks for four
     * gigabytes of it.
     */
    public function testRefusesAnImpossibleNumberOfSamplesPerPixel(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('samples per pixel');

        TiffImage::fromBytes(new Document(), self::tiff(
            compression: 1,
            strip: 'x',
            width: 1,
            height: 1,
            bits: 8,
            extraLong: [277 => 500_000_000],
        ));
    }

    /**
     * The same guard from the other side: a sample count that is
     * plausible on its own and enormous once multiplied out.
     */
    public function testRefusesAnImageWhoseSamplesWouldNotFitInMemory(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('more than this library will allocate');

        // 10 000 x 9 999 pixels is under MAX_PIXELS, and at four 16-bit
        // samples each it is 800 MB of buffer.
        TiffImage::fromBytes(new Document(), self::tiff(
            compression: 1,
            strip: 'x',
            width: 10_000,
            height: 9_999,
            bits: 16,
            extra: [277 => 4],
        ));
    }

    /**
     * Every entry here passes the per-entry bounds check -- each one's
     * values really do lie inside the file -- and there is no rule saying
     * they may not all point at the same megabyte. Read literally, this
     * file yields hundreds of millions of integers out of one megabyte,
     * which is where the aggregate budget comes in.
     */
    public function testDoesNotExpandOneMegabyteIntoGigabytesOfTagValues(): void
    {
        $values = 1_048_576;
        $entries = 512;

        $payloadAt = 8 + 2 + $entries * 12 + 4;
        $ifd = pack('v', $entries);

        for ($i = 0; $i < $entries; ++$i) {
            // Distinct tag numbers, well above the ones TiffImage reads,
            // so none of them overwrites another.
            $ifd .= pack('vvVV', 40_000 + $i, 1, $values, $payloadAt);
        }

        $bytes = 'II' . pack('v', 42) . pack('V', 8) . $ifd . pack('V', 0) . str_repeat("\0", $values);

        $before = memory_get_usage(true);
        TiffDirectory::all($bytes);
        $used = memory_get_usage(true) - $before;

        // Comfortably under what even a handful of unbudgeted entries
        // would take: ten of them alone measured 160 MB before the budget
        // existed, and five hundred measured over three gigabytes.
        self::assertLessThan(64 * 1024 * 1024, $used);
    }

    public function testStillReadsAnOrdinaryMultiSampleImage(): void
    {
        // Three samples is RGB, and has to keep working: the guard above
        // is about impossible numbers, not about colour.
        $image = TiffImage::fromBytes(new Document(), self::tiff(
            compression: 1,
            strip: str_repeat("\xFF\x00\x80", 16 * 4),
            bits: 8,
            photometric: 2,
            extra: [277 => 3],
        ));

        self::assertSame('/DeviceRGB', $image->get('ColorSpace')?->format());
        self::assertSame('8', $image->get('BitsPerComponent')?->format());
    }

    public function testBigEndianFilesReadTheSame(): void
    {
        $little = TiffImage::fromBytes(new Document(), self::tiff(compression: 4, strip: self::faxStrip()));
        $big = TiffImage::fromBytes(new Document(), self::tiff(
            compression: 4,
            strip: self::faxStrip(),
            bigEndian: true,
        ));

        self::assertSame($little->rawBytes(), $big->rawBytes());
        self::assertSame($little->get('Width')?->format(), $big->get('Width')?->format());
    }

    /** Four rows of a plausible-looking Group 4 bitstream. */
    private static function faxStrip(): string
    {
        return "\x26\xA0\x9B\x40\x4D\xA0\x26\xC0";
    }

    /**
     * A minimal single-strip TIFF, built here so that what it contains is
     * next to the test that asserts about it.
     *
     * @param array<int, int> $extra additional single-value tags
     * @param list<int> $colorMap
     */
    private static function tiff(
        int $compression,
        string $strip,
        int $width = 16,
        int $height = 4,
        int $bits = 1,
        int $photometric = 0,
        ?string $secondStrip = null,
        array $extra = [],
        array $extraLong = [],
        array $colorMap = [],
        bool $bigEndian = false,
    ): string {
        $short = static fn (int $v): string => pack($bigEndian ? 'n' : 'v', $v);
        $long = static fn (int $v): string => pack($bigEndian ? 'N' : 'V', $v);

        // Header, then the strip data, then the directory after it, so
        // every offset is known before it is written.
        $header = ($bigEndian ? 'MM' : 'II') . $short(42);
        $data = $strip . ($secondStrip ?? '');

        $mapBytes = '';

        foreach ($colorMap as $value) {
            $mapBytes .= $short($value);
        }

        $stripAt = 8;
        $mapAt = $stripAt + strlen($data);
        $arraysAt = $mapAt + strlen($mapBytes);

        // Two-strip files need their offsets and counts out of line.
        $offsetsAt = $arraysAt;
        $countsAt = $arraysAt + 8;
        $arrays = $secondStrip === null
            ? ''
            : $long($stripAt) . $long($stripAt + strlen($strip))
                . $long(strlen($strip)) . $long(strlen($secondStrip));

        $ifdAt = $arraysAt + strlen($arrays);

        $tags = [
            256 => [3, 1, $width],
            257 => [3, 1, $height],
            258 => [3, 1, $bits],
            259 => [3, 1, $compression],
            262 => [3, 1, $photometric],
            273 => $secondStrip === null ? [4, 1, $stripAt] : [4, 2, $offsetsAt],
            278 => [3, 1, $secondStrip === null ? $height : intdiv($height, 2)],
            279 => $secondStrip === null ? [4, 1, strlen($strip)] : [4, 2, $countsAt],
            284 => [3, 1, 1],
        ];

        if ($colorMap !== []) {
            $tags[320] = [3, count($colorMap), $mapAt];
        }

        foreach ($extra as $tag => $value) {
            $tags[$tag] = [3, 1, $value];
        }

        // LONG rather than SHORT, for the tags whose point is a value too
        // big to fit in one -- a SHORT would silently keep its low half.
        foreach ($extraLong as $tag => $value) {
            $tags[$tag] = [4, 1, $value];
        }

        ksort($tags);

        $ifd = $short(count($tags));

        foreach ($tags as $tag => [$type, $count, $value]) {
            $ifd .= $short($tag) . $short($type) . $long($count);
            // A SHORT that fits inline sits in the high half on a
            // big-endian file and the low half on a little-endian one.
            $ifd .= $type === 3 && $count === 1
                ? ($bigEndian ? $short($value) . "\0\0" : $long($value))
                : $long($value);
        }

        $ifd .= $long(0);

        return $header . $long($ifdAt) . $data . $mapBytes . $arrays . $ifd;
    }

    /**
     * A file with two images chained one directory to the next, which is
     * how a multi-page fax arrives.
     *
     * Laid out explicitly -- header, both strips, both directories -- so
     * every offset is computed once from known sizes rather than patched
     * afterwards.
     */
    private static function multiPage(): string
    {
        $strip = self::faxStrip();

        $firstStripAt = 8;
        $secondStripAt = $firstStripAt + strlen($strip);
        $firstIfdAt = $secondStripAt + strlen($strip);

        $first = self::directory(16, $firstStripAt, strlen($strip));
        $secondIfdAt = $firstIfdAt + strlen($first);

        return 'II' . pack('v', 42) . pack('V', $firstIfdAt)
            . $strip
            . $strip
            // The first directory points at the second.
            . substr($first, 0, -4) . pack('V', $secondIfdAt)
            . self::directory(8, $secondStripAt, strlen($strip));
    }

    /** One image directory, ending in its "no more images" pointer. */
    private static function directory(int $width, int $stripAt, int $stripLength): string
    {
        $tags = [
            256 => [3, $width],
            257 => [3, 4],
            258 => [3, 1],
            259 => [3, 4],
            262 => [3, 0],
            273 => [4, $stripAt],
            278 => [3, 4],
            279 => [4, $stripLength],
            284 => [3, 1],
        ];

        $out = pack('v', count($tags));

        foreach ($tags as $tag => [$type, $value]) {
            $out .= pack('v', $tag) . pack('v', $type) . pack('V', 1) . pack('V', $value);
        }

        return $out . pack('V', 0);
    }
}
