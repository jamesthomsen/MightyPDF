<?php

declare(strict_types=1);

namespace MightyPDF\Content\Image;

use MightyPDF\Assembler\Dictionary;
use MightyPDF\Assembler\Stream;
use MightyPDF\Assembler\Types\PdfArray;
use MightyPDF\Assembler\Types\PdfHexString;
use MightyPDF\Assembler\Types\PdfInteger;
use MightyPDF\Assembler\Types\PdfName;

/**
 * Builds an Image XObject (ISO 32000-2 §8.9.5) from a PNG file.
 *
 * PNG's IDAT data is a zlib (deflate) stream of scanlines, each prefixed
 * with a filter-type byte (the PNG predictor algorithm) -- which is
 * exactly what PDF's /Filter /FlateDecode with
 * /DecodeParms << /Predictor 15 ... >> expects. So the IDAT bytes are
 * relayed verbatim, with no decompression or re-filtering, as long as
 * /Colors, /BitsPerComponent and /Columns in DecodeParms match the PNG's
 * own IHDR values.
 *
 * Scope (per project conventions -- explicitly unsupported rather than
 * silently wrong): only non-interlaced grayscale/truecolor/indexed PNGs
 * (color types 0, 2, 3) without a baked-in alpha channel. Interlaced
 * images and color types 4/6 (alpha channels) require real pixel-level
 * decode/re-encode work and raise a clear exception instead.
 */
final class PngImage
{
    private const string SIGNATURE = "\x89PNG\r\n\x1a\n";

    private function __construct()
    {
    }

    public static function fromFile(int $objectId, string $path): Stream
    {
        $bytes = file_get_contents($path);
        if ($bytes === false) {
            throw new \RuntimeException("Unable to read PNG file: $path");
        }

        return self::fromBytes($objectId, $bytes);
    }

    public static function fromBytes(int $objectId, string $bytes): Stream
    {
        if (!str_starts_with($bytes, self::SIGNATURE)) {
            throw new \InvalidArgumentException('Not a PNG file (bad signature).');
        }

        $chunks = self::readChunks($bytes);

        if (!isset($chunks['IHDR'])) {
            throw new \InvalidArgumentException('PNG has no IHDR chunk.');
        }

        $ihdr = $chunks['IHDR'][0];
        $width = self::readUint32($ihdr, 0);
        $height = self::readUint32($ihdr, 4);
        $bitDepth = ord($ihdr[8]);
        $colorType = ord($ihdr[9]);
        $interlace = ord($ihdr[12]);

        if ($interlace !== 0) {
            throw new \RuntimeException('Interlaced PNGs are not supported; re-save without interlacing.');
        }
        if (!in_array($colorType, [0, 2, 3], true)) {
            throw new \RuntimeException(
                'PNGs with an alpha channel (color type 4 or 6) are not supported in this version; use a flattened PNG.',
            );
        }

        $idat = implode('', $chunks['IDAT'] ?? []);
        if ($idat === '') {
            throw new \InvalidArgumentException('PNG has no IDAT data.');
        }

        $colors = match ($colorType) {
            0 => 1, // grayscale
            2 => 3, // truecolor
            3 => 1, // indexed
        };

        $stream = new Stream($objectId, $idat, compress: false);
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

        if ($colorType === 3) {
            $palette = $chunks['PLTE'][0] ?? throw new \InvalidArgumentException('Indexed PNG has no PLTE chunk.');
            $hival = intdiv(strlen($palette), 3) - 1;
            $stream->set('ColorSpace', new PdfArray(
                new PdfName('Indexed'),
                new PdfName('DeviceRGB'),
                new PdfInteger($hival),
                new PdfHexString($palette),
            ));
        } else {
            $stream->set('ColorSpace', new PdfName($colorType === 0 ? 'DeviceGray' : 'DeviceRGB'));
        }

        return $stream;
    }

    /** @return array<string, list<string>> chunk type => list of chunk data (in file order) */
    private static function readChunks(string $bytes): array
    {
        $pos = strlen(self::SIGNATURE);
        $length = strlen($bytes);
        $chunks = [];

        while ($pos + 8 <= $length) {
            $chunkLength = self::readUint32($bytes, $pos);
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
