<?php

declare(strict_types=1);

namespace MightyPDF\Content\Image;

use MightyPDF\Assembler\Dictionary;
use MightyPDF\Assembler\IndirectObjectRegistry;
use MightyPDF\Assembler\Stream;
use MightyPDF\Assembler\Types\PdfArray;
use MightyPDF\Assembler\Types\PdfHexString;
use MightyPDF\Assembler\Types\PdfInteger;
use MightyPDF\Assembler\Types\PdfName;
use MightyPDF\Assembler\Types\PdfReference;
use MightyPDF\Assembler\Types\PdfValue;
use MightyPDF\Png\ScanlineFilter;

/**
 * Builds an Image XObject (ISO 32000-2 §8.9.5) from a PNG file.
 *
 * PNG's IDAT data is a zlib (deflate) stream of scanlines, each prefixed
 * with a filter-type byte (the PNG predictor algorithm) -- which is
 * exactly what PDF's /Filter /FlateDecode with
 * /DecodeParms << /Predictor 15 ... >> expects. So for the common case --
 * non-interlaced, no-alpha color types (0, 2, 3) -- the IDAT bytes are
 * relayed verbatim, with no decompression or re-filtering, as long as
 * /Colors, /BitsPerComponent and /Columns in DecodeParms match the PNG's
 * own IHDR values.
 *
 * Two situations break that verbatim-relay trick and require real
 * pixel-level work instead: inflating the IDAT stream, undoing the PNG
 * predictor per scanline (§9.3 in the PNG spec: None/Sub/Up/Average/
 * Paeth), and re-deflating whatever comes out the other side.
 *
 *  - Color types 4/6 (grayscale/truecolor with a baked-in alpha channel):
 *    PDF has no single image stream that carries both color and
 *    transparency, so the alpha channel has to come out as a separate
 *    /SMask image (ISO 32000-2 §11.6.5.3) -- each pixel's channels are
 *    split into a color stream (re-deflated as the main image, no
 *    predictor needed since it's emitted as plain row-major samples) and
 *    an alpha stream (re-deflated as a DeviceGray SMask image, where a
 *    PNG alpha sample maps directly onto PDF's SMask semantics of the
 *    same range). Building the SMask needs a second indirect object,
 *    which is why this class takes the document's registry rather than a
 *    single object id -- allocating and registering that second object is
 *    this class's own side effect to own (only when the source PNG
 *    actually has alpha), not something every call site should have to
 *    know to do.
 *  - Interlaced (Adam7, §8.2) PNGs: the IDAT stream is seven
 *    sub-images -- reduced-resolution passes over disjoint pixel grids --
 *    concatenated together, each independently filtered. PDF's Predictor
 *    15 has no way to express that layout, so there's no verbatim relay
 *    possible for *any* color type once a PNG is interlaced: all seven
 *    passes get inflated, un-filtered, and scattered back into a single
 *    full-resolution buffer before it can be handed to PDF at all (still
 *    re-deflated with no predictor, like the alpha case above -- and
 *    combined with the alpha-splitting step when a PNG is both
 *    interlaced and alpha-bearing).
 *
 * Scope (per project conventions -- explicitly unsupported rather than
 * silently wrong): only 8- or 16-bit-per-channel data for the alpha color
 * types and for interlaced PNGs of any color type. Sub-byte bit depths
 * (1/2/4, only reachable via grayscale or indexed PNGs) still work
 * through the verbatim-relay path as long as the PNG isn't interlaced --
 * combining a sub-byte bit depth with interlacing would need bit-level
 * (not byte-level) pass reassembly, which no current caller needs, and
 * raises a clear exception instead.
 */
final class PngImage
{
    private const string SIGNATURE = "\x89PNG\r\n\x1a\n";

    /**
     * Guards decodePixels()/unfilterPass() (which allocate buffers
     * proportional to width * height) against a crafted PNG whose IHDR
     * declares dimensions far larger than its actual (tiny) IDAT payload
     * could ever hold -- without this, such a file forces a multi-gigabyte
     * allocation before the truncated-data check in unfilterPass() ever
     * runs. 100 megapixels is far beyond any realistic embedded image.
     */
    private const int MAX_PIXELS = 100_000_000;

    /**
     * MAX_PIXELS alone is not a memory bound: the decoded buffer is
     * width * height * bytes-per-pixel, and bytes-per-pixel reaches 8 for
     * 16-bit RGBA -- so a 69-byte file declaring 9999x10000 asks for a
     * 762MB allocation before a single byte of IDAT is examined. This caps
     * what the decoding paths (alpha and interlaced) may allocate. The
     * verbatim-relay path is unaffected, since it never builds a pixel
     * buffer at all and so stays governed by MAX_PIXELS only.
     */
    private const int MAX_DECODED_BYTES = 128 * 1024 * 1024;

    /**
     * Adam7 interlacing (PNG spec §8.2): seven passes over the image,
     * each picking out a disjoint subset of pixels. Each entry is
     * [startX, startY, stepX, stepY] -- pass N contains the pixel at
     * (x, y) iff x >= startX, y >= startY, (x - startX) % stepX === 0,
     * and (y - startY) % stepY === 0.
     */
    private const array ADAM7_PASSES = [
        [0, 0, 8, 8],
        [4, 0, 8, 8],
        [0, 4, 4, 8],
        [2, 0, 4, 4],
        [0, 2, 2, 4],
        [1, 0, 2, 2],
        [0, 1, 1, 2],
    ];

    private function __construct()
    {
    }

    public static function fromFile(IndirectObjectRegistry $registry, string $path): Stream
    {
        $bytes = file_get_contents($path);
        if ($bytes === false) {
            throw new \RuntimeException("Unable to read PNG file: $path");
        }

        return self::fromBytes($registry, $bytes);
    }

    public static function fromBytes(IndirectObjectRegistry $registry, string $bytes): Stream
    {
        if (!str_starts_with($bytes, self::SIGNATURE)) {
            throw new \InvalidArgumentException('Not a PNG file (bad signature).');
        }

        $chunks = self::readChunks($bytes);

        if (!isset($chunks['IHDR'])) {
            throw new \InvalidArgumentException('PNG has no IHDR chunk.');
        }

        $ihdr = $chunks['IHDR'][0];
        if (strlen($ihdr) < 13) {
            throw new \InvalidArgumentException('PNG IHDR chunk is truncated.');
        }

        $width = self::readUint32($ihdr, 0);
        $height = self::readUint32($ihdr, 4);
        $bitDepth = ord($ihdr[8]);
        $colorType = ord($ihdr[9]);
        $interlace = ord($ihdr[12]);

        if (!in_array($interlace, [0, 1], true)) {
            throw new \RuntimeException("Unknown PNG interlace method: $interlace.");
        }

        if ($width <= 0 || $height <= 0 || $width * $height > self::MAX_PIXELS) {
            throw new \InvalidArgumentException("PNG dimensions out of range: {$width}x{$height}.");
        }

        $idat = implode('', $chunks['IDAT'] ?? []);
        if ($idat === '') {
            throw new \InvalidArgumentException('PNG has no IDAT data.');
        }

        if (in_array($colorType, [4, 6], true)) {
            return self::buildAlphaImage($registry, $idat, $width, $height, $bitDepth, $colorType, $interlace === 1);
        }

        if (!in_array($colorType, [0, 2, 3], true)) {
            throw new \RuntimeException("Unsupported PNG color type: $colorType.");
        }

        if ($interlace === 1) {
            return self::buildDeinterlacedImage($registry, $chunks, $idat, $width, $height, $bitDepth, $colorType);
        }

        $colors = match ($colorType) {
            0 => 1, // grayscale
            2 => 3, // truecolor
            3 => 1, // indexed
        };

        // Resolved before allocating, because an indexed PNG with no PLTE
        // chunk throws here -- and an id consumed by a rejected image is
        // never registered, leaving a gap that fails the whole document at
        // save() time.
        $colorSpace = self::colorSpaceFor($colorType, $chunks);

        $stream = new Stream($registry->allocate(), $idat, compress: false);
        $stream->set('Type', new PdfName('XObject'));
        $stream->set('Subtype', new PdfName('Image'));
        $stream->set('Width', new PdfInteger($width));
        $stream->set('Height', new PdfInteger($height));
        $stream->set('BitsPerComponent', new PdfInteger($bitDepth));
        $stream->set('Filter', new PdfName('FlateDecode'));

        $decodeParms = new Dictionary();
        $decodeParms->set('Predictor', new PdfInteger(15));
        $decodeParms->set('Colors', new PdfInteger($colors));
        $decodeParms->set('BitsPerComponent', new PdfInteger($bitDepth));
        $decodeParms->set('Columns', new PdfInteger($width));
        $stream->set('DecodeParms', $decodeParms);

        $stream->set('ColorSpace', $colorSpace);

        return $stream;
    }

    /**
     * Color types 4 (grayscale+alpha) and 6 (truecolor+alpha): inflate,
     * un-filter (and de-interlace, if needed), and split into a color
     * stream plus a DeviceGray SMask stream registered as a second
     * indirect object.
     */
    private static function buildAlphaImage(
        IndirectObjectRegistry $registry,
        string $idat,
        int $width,
        int $height,
        int $bitDepth,
        int $colorType,
        bool $interlaced,
    ): Stream {
        if (!in_array($bitDepth, [8, 16], true)) {
            throw new \RuntimeException(
                "PNGs with an alpha channel are only supported at 8 or 16 bits per channel (got $bitDepth); re-save at one of those depths.",
            );
        }

        $bytesPerSample = intdiv($bitDepth, 8);
        $channels = $colorType === 6 ? 4 : 2; // truecolor+alpha vs grayscale+alpha
        $colorChannels = $channels - 1;
        $bpp = $channels * $bytesPerSample;

        self::guardDecodedSize($width, $height, $bpp);
        $raw = self::inflate($idat, $width, $height, $bpp, $interlaced);

        $decoded = self::decodePixels($raw, $width, $height, $bpp, $interlaced);
        [$colorBytes, $alphaBytes] = self::splitColorAndAlpha($decoded, $width, $height, $colorChannels, $bytesPerSample);

        $objectId = $registry->allocate();
        $smaskObjectId = $registry->allocate();

        $mask = new Stream($smaskObjectId, $alphaBytes, compress: true);
        $mask->set('Type', new PdfName('XObject'));
        $mask->set('Subtype', new PdfName('Image'));
        $mask->set('Width', new PdfInteger($width));
        $mask->set('Height', new PdfInteger($height));
        $mask->set('BitsPerComponent', new PdfInteger($bitDepth));
        $mask->set('ColorSpace', new PdfName('DeviceGray'));
        $registry->register($mask);

        $stream = new Stream($objectId, $colorBytes, compress: true);
        $stream->set('Type', new PdfName('XObject'));
        $stream->set('Subtype', new PdfName('Image'));
        $stream->set('Width', new PdfInteger($width));
        $stream->set('Height', new PdfInteger($height));
        $stream->set('BitsPerComponent', new PdfInteger($bitDepth));
        $stream->set('ColorSpace', new PdfName($colorType === 6 ? 'DeviceRGB' : 'DeviceGray'));
        $stream->set('SMask', new PdfReference($smaskObjectId));

        return $stream;
    }

    /**
     * Interlaced color types 0/2/3 (no alpha channel, so no splitting
     * needed): inflate, un-filter, de-interlace, and re-deflate as a
     * plain (unpredicted) image stream. Non-interlaced PNGs of these
     * color types never reach here -- they take the verbatim-relay path
     * in fromBytes() instead, which is both simpler and cheaper.
     */
    private static function buildDeinterlacedImage(
        IndirectObjectRegistry $registry,
        array $chunks,
        string $idat,
        int $width,
        int $height,
        int $bitDepth,
        int $colorType,
    ): Stream {
        if (!in_array($bitDepth, [8, 16], true)) {
            throw new \RuntimeException(
                "Interlaced PNGs are only supported at 8 or 16 bits per channel (got $bitDepth); re-save without interlacing or at one of those depths.",
            );
        }

        $bytesPerSample = intdiv($bitDepth, 8);
        $colors = match ($colorType) {
            0 => 1, // grayscale
            2 => 3, // truecolor
            3 => 1, // indexed
        };
        $bpp = $colors * $bytesPerSample;

        self::guardDecodedSize($width, $height, $bpp);
        $raw = self::inflate($idat, $width, $height, $bpp, true);

        $decoded = self::decodePixels($raw, $width, $height, $bpp, true);
        $colorSpace = self::colorSpaceFor($colorType, $chunks);

        $stream = new Stream($registry->allocate(), $decoded, compress: true);
        $stream->set('Type', new PdfName('XObject'));
        $stream->set('Subtype', new PdfName('Image'));
        $stream->set('Width', new PdfInteger($width));
        $stream->set('Height', new PdfInteger($height));
        $stream->set('BitsPerComponent', new PdfInteger($bitDepth));
        $stream->set('ColorSpace', $colorSpace);

        return $stream;
    }

    /**
     * Rejects images whose decoded pixel buffer would exceed
     * MAX_DECODED_BYTES *before* anything allocates it -- decodePixels()
     * otherwise str_repeat()s the full buffer up front, so oversized
     * declared dimensions exhaust memory (an uncatchable fatal) rather
     * than raising the truncated-data exception further down.
     */
    private static function guardDecodedSize(int $width, int $height, int $bpp): void
    {
        if ($width * $height * $bpp > self::MAX_DECODED_BYTES) {
            throw new \InvalidArgumentException(sprintf(
                'PNG decodes to %d bytes of pixel data, over the %d-byte limit.',
                $width * $height * $bpp,
                self::MAX_DECODED_BYTES,
            ));
        }
    }

    /**
     * Inflates IDAT with an explicit ceiling. gzuncompress() with no
     * max_length happily expands a small crafted IDAT to gigabytes --
     * declared dimensions say nothing about how much data the stream
     * actually holds, so the ceiling comes from how many bytes a
     * well-formed image of these dimensions *should* inflate to
     * (scanlines plus their leading filter byte, per Adam7 pass when
     * interlaced).
     */
    private static function inflate(string $idat, int $width, int $height, int $bpp, bool $interlaced): string
    {
        $expected = 0;
        if (!$interlaced) {
            $expected = $height * (1 + $width * $bpp);
        } else {
            foreach (self::ADAM7_PASSES as [$startX, $startY, $stepX, $stepY]) {
                [$passWidth, $passHeight] = self::adam7PassDimensions($width, $height, $startX, $startY, $stepX, $stepY);
                if ($passWidth === 0 || $passHeight === 0) {
                    continue;
                }
                $expected += $passHeight * (1 + $passWidth * $bpp);
            }
        }

        $raw = @gzuncompress($idat, $expected);
        if ($raw === false) {
            throw new \RuntimeException('Failed to inflate PNG IDAT data.');
        }

        return $raw;
    }

    private static function colorSpaceFor(int $colorType, array $chunks): PdfValue
    {
        if ($colorType === 3) {
            $palette = $chunks['PLTE'][0] ?? throw new \InvalidArgumentException('Indexed PNG has no PLTE chunk.');
            $hival = intdiv(strlen($palette), 3) - 1;

            return new PdfArray(
                new PdfName('Indexed'),
                new PdfName('DeviceRGB'),
                new PdfInteger($hival),
                new PdfHexString($palette),
            );
        }

        return new PdfName($colorType === 0 ? 'DeviceGray' : 'DeviceRGB');
    }

    /**
     * Un-filters (and, if interlaced, de-interlaces) inflated IDAT bytes
     * into a flat buffer of $width * $height pixels, $bpp bytes each, in
     * ordinary row-major order -- i.e. what a non-interlaced PNG's IDAT
     * would inflate to after removing the per-scanline filter bytes.
     */
    private static function decodePixels(string $raw, int $width, int $height, int $bpp, bool $interlaced): string
    {
        if (!$interlaced) {
            $offset = 0;

            return self::unfilterPass($raw, $offset, $width, $height, $bpp);
        }

        $full = str_repeat("\x00", $width * $height * $bpp);
        $offset = 0;

        foreach (self::ADAM7_PASSES as [$startX, $startY, $stepX, $stepY]) {
            [$passWidth, $passHeight] = self::adam7PassDimensions($width, $height, $startX, $startY, $stepX, $stepY);

            if ($passWidth === 0 || $passHeight === 0) {
                continue;
            }

            $passData = self::unfilterPass($raw, $offset, $passWidth, $passHeight, $bpp);

            for ($py = 0; $py < $passHeight; $py++) {
                $fullY = $startY + $py * $stepY;
                $srcRowStart = $py * $passWidth * $bpp;
                $dstRowStart = ($fullY * $width + $startX) * $bpp;

                for ($px = 0; $px < $passWidth; $px++) {
                    $src = $srcRowStart + $px * $bpp;
                    $dst = $dstRowStart + $px * $stepX * $bpp;

                    for ($b = 0; $b < $bpp; $b++) {
                        $full[$dst + $b] = $passData[$src + $b];
                    }
                }
            }
        }

        return $full;
    }

    /** @return array{0: int, 1: int} pass width, pass height */
    private static function adam7PassDimensions(int $width, int $height, int $startX, int $startY, int $stepX, int $stepY): array
    {
        $passWidth = $width > $startX ? intdiv($width - $startX + $stepX - 1, $stepX) : 0;
        $passHeight = $height > $startY ? intdiv($height - $startY + $stepY - 1, $stepY) : 0;

        return [$passWidth, $passHeight];
    }

    /**
     * Un-filters one contiguous run of scanlines (a whole non-interlaced
     * image, or a single Adam7 pass) starting at $raw[$offset], advancing
     * $offset past what it consumed so callers can chain passes over one
     * shared buffer. Returns the scanlines concatenated with their
     * leading filter-type bytes stripped.
     *
     * Undoing each row's filter is ScanlineFilter's job; what stays here
     * is the framing. A row is $passWidth whole pixels of $bpp bytes --
     * this path only ever runs at 8 or 16 bits per channel, so rows land
     * on byte boundaries -- and a run that ends before its declared
     * number of rows means a truncated IDAT, which is a corrupt image
     * rather than something to pad over.
     */
    private static function unfilterPass(string $raw, int &$offset, int $passWidth, int $passHeight, int $bpp): string
    {
        $rowBytes = $passWidth * $bpp;
        $out = '';
        $prevRow = str_repeat("\x00", $rowBytes);

        for ($y = 0; $y < $passHeight; $y++) {
            if ($offset + 1 + $rowBytes > strlen($raw)) {
                throw new \RuntimeException('PNG IDAT data is shorter than its declared dimensions.');
            }

            $filterType = ord($raw[$offset]);
            $row = substr($raw, $offset + 1, $rowBytes);
            $offset += 1 + $rowBytes;

            $recon = ScanlineFilter::reconstructRow($filterType, $row, $prevRow, $bpp)
                ?? throw new \RuntimeException("Unknown PNG filter type: $filterType.");

            $out .= $recon;
            $prevRow = $recon;
        }

        return $out;
    }

    /**
     * Splits a decodePixels() buffer's interleaved color+alpha samples
     * into separate color and alpha byte strings, one $bytesPerSample-wide
     * sample at a time (1 byte for 8-bit PNGs, 2 for 16-bit).
     *
     * @return array{0: string, 1: string} color channel bytes, alpha bytes
     */
    private static function splitColorAndAlpha(string $decoded, int $width, int $height, int $colorChannels, int $bytesPerSample): array
    {
        $colorSampleBytes = $colorChannels * $bytesPerSample;
        $bpp = $colorSampleBytes + $bytesPerSample;

        $colorOut = '';
        $alphaOut = '';
        $pixelCount = $width * $height;

        for ($p = 0; $p < $pixelCount; $p++) {
            $pixel = substr($decoded, $p * $bpp, $bpp);
            $colorOut .= substr($pixel, 0, $colorSampleBytes);
            $alphaOut .= substr($pixel, $colorSampleBytes, $bytesPerSample);
        }

        return [$colorOut, $alphaOut];
    }

    /** @return array<string, list<string>> chunk type => list of chunk data (in file order) */
    private static function readChunks(string $bytes): array
    {
        $pos = strlen(self::SIGNATURE);
        $length = strlen($bytes);
        $chunks = [];

        while ($pos + 8 <= $length) {
            $chunkLength = self::readUint32($bytes, $pos);
            if ($chunkLength > $length - $pos - 8) {
                throw new \InvalidArgumentException('PNG chunk length exceeds the remaining file data.');
            }

            $type = substr($bytes, $pos + 4, 4);
            $data = substr($bytes, $pos + 8, $chunkLength);

            $chunks[$type][] = $data;

            if ($type === 'IEND') {
                break;
            }

            $pos += 8 + $chunkLength + 4; // length field + type + data + CRC
        }

        return $chunks;
    }

    private static function readUint32(string $bytes, int $offset): int
    {
        return (ord($bytes[$offset]) << 24)
            | (ord($bytes[$offset + 1]) << 16)
            | (ord($bytes[$offset + 2]) << 8)
            | ord($bytes[$offset + 3]);
    }
}
