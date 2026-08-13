<?php

declare(strict_types=1);

namespace MightyPDF\Content\Image;

use MightyPDF\Assembler\ObjectHost;
use MightyPDF\Assembler\Stream;
use MightyPDF\Assembler\Types\PdfArray;
use MightyPDF\Assembler\Types\PdfHexString;
use MightyPDF\Assembler\Types\PdfInteger;
use MightyPDF\Assembler\Types\PdfName;
use MightyPDF\Exception\InvalidArgumentException;
use MightyPDF\Exception\RuntimeException;

/**
 * Builds an Image XObject (ISO 32000-2 §8.9.5) from a GIF file.
 *
 * Unlike JPEG/PNG, GIF's own LZW compression isn't byte-compatible with
 * any PDF stream filter, so the pixel data genuinely has to be decoded
 * (via GifLzwDecoder) before it can be re-embedded -- as raw indices,
 * re-compressed with ordinary FlateDecode. The palette maps directly to
 * PDF's native /Indexed color space, so no color conversion is needed.
 *
 * Scope: only the first frame is used if the file is animated (a static
 * PDF image can't represent animation), matching how most non-animation-
 * aware embedders behave. Interlaced frames are supported (de-interlaced
 * during decode). A single transparent-color index (from a preceding
 * Graphic Control Extension) is supported via color-key /Mask; more than
 * one transparent color isn't representable in GIF anyway.
 */
final class GifImage
{
    private function __construct()
    {
    }

    public static function fromFile(ObjectHost $registry, string $path): Stream
    {
        $bytes = file_get_contents($path);
        if ($bytes === false) {
            throw new RuntimeException("Unable to read GIF file: $path");
        }

        return self::fromBytes($registry, $bytes);
    }

    /**
     * Takes the registry rather than a pre-allocated object id so that an
     * id is only consumed once the file is known to be parseable: a
     * rejected image must not strand an allocated-but-unregistered id,
     * which would later surface as an unrelated "xref has a gap" failure
     * when the whole document is saved.
     */
    public static function fromBytes(ObjectHost $registry, string $bytes): Stream
    {
        $length = strlen($bytes);
        if ($length < 13 || !(str_starts_with($bytes, 'GIF87a') || str_starts_with($bytes, 'GIF89a'))) {
            throw new InvalidArgumentException('Not a GIF file (bad header).');
        }

        $packed = ord($bytes[10]);
        $hasGlobalColorTable = ($packed & 0x80) !== 0;
        $globalColorTableSize = 2 << ($packed & 0x07);

        $pos = 13;
        $globalColorTable = null;
        if ($hasGlobalColorTable) {
            self::requireBytes($bytes, $pos, $globalColorTableSize * 3);
            $globalColorTable = substr($bytes, $pos, $globalColorTableSize * 3);
            $pos += $globalColorTableSize * 3;
        }

        $transparentIndex = null;

        while ($pos < $length) {
            $introducer = ord($bytes[$pos]);

            if ($introducer === 0x3B) {
                break; // Trailer, no image found
            }

            if ($introducer === 0x21) {
                self::requireBytes($bytes, $pos, 2);
                $label = ord($bytes[$pos + 1]);
                $pos += 2;

                if ($label === 0xF9) {
                    self::requireBytes($bytes, $pos, 2);
                    $blockSize = ord($bytes[$pos]);
                    $gcePacked = ord($bytes[$pos + 1]);
                    if (($gcePacked & 0x01) !== 0) {
                        self::requireBytes($bytes, $pos, 5);
                        $transparentIndex = ord($bytes[$pos + 4]);
                    }
                    $pos += 1 + $blockSize + 1; // block-size byte + data + terminator
                } else {
                    $pos = self::skipSubBlocks($bytes, $pos);
                }
                continue;
            }

            if ($introducer === 0x2C) {
                return self::readFirstImage($bytes, $pos, $globalColorTable, $transparentIndex, $registry);
            }

            throw new InvalidArgumentException(sprintf('Unrecognized GIF block introducer: 0x%02X', $introducer));
        }

        throw new InvalidArgumentException('GIF has no image data (no Image Descriptor block found).');
    }

    private static function readFirstImage(
        string $bytes,
        int $pos,
        ?string $globalColorTable,
        ?int $transparentIndex,
        ObjectHost $registry,
    ): Stream {
        ++$pos; // skip the 0x2C introducer
        self::requireBytes($bytes, $pos, 9);
        $width = self::readUint16LE($bytes, $pos + 4);
        $height = self::readUint16LE($bytes, $pos + 6);
        $imgPacked = ord($bytes[$pos + 8]);
        $pos += 9;

        if ($width <= 0 || $height <= 0) {
            throw new InvalidArgumentException("GIF image descriptor has invalid dimensions: {$width}x{$height}.");
        }

        $hasLocalColorTable = ($imgPacked & 0x80) !== 0;
        $interlaced = ($imgPacked & 0x40) !== 0;
        $localColorTableSize = 2 << ($imgPacked & 0x07);

        $colorTable = $globalColorTable;
        if ($hasLocalColorTable) {
            self::requireBytes($bytes, $pos, $localColorTableSize * 3);
            $colorTable = substr($bytes, $pos, $localColorTableSize * 3);
            $pos += $localColorTableSize * 3;
        }

        if ($colorTable === null) {
            throw new InvalidArgumentException('GIF has no color table (neither global nor local).');
        }

        self::requireBytes($bytes, $pos, 1);
        $minCodeSize = ord($bytes[$pos]);
        ++$pos;

        [$compressedData] = self::readSubBlocks($bytes, $pos);

        // The image descriptor's own dimensions bound how much pixel data
        // can legitimately come out of the LZW stream -- see the decoder's
        // docblock for why an unbounded decode is a denial-of-service risk.
        $indices = GifLzwDecoder::decode($compressedData, $minCodeSize, $width * $height);

        if ($interlaced) {
            $indices = self::deinterlace($indices, $width, $height);
        }

        return self::buildImageXObject($registry->allocate(), $width, $height, $indices, $colorTable, $transparentIndex);
    }

    private static function buildImageXObject(
        int $objectId,
        int $width,
        int $height,
        string $indices,
        string $colorTable,
        ?int $transparentIndex,
    ): Stream {
        $stream = new Stream($objectId, $indices, compress: true);
        $stream->set('Type', new PdfName('XObject'));
        $stream->set('Subtype', new PdfName('Image'));
        $stream->set('Width', new PdfInteger($width));
        $stream->set('Height', new PdfInteger($height));
        $stream->set('BitsPerComponent', new PdfInteger(8));

        $hival = intdiv(strlen($colorTable), 3) - 1;
        $stream->set('ColorSpace', new PdfArray(
            new PdfName('Indexed'),
            new PdfName('DeviceRGB'),
            new PdfInteger($hival),
            new PdfHexString($colorTable),
        ));

        if ($transparentIndex !== null) {
            $stream->set('Mask', new PdfArray(new PdfInteger($transparentIndex), new PdfInteger($transparentIndex)));
        }

        return $stream;
    }

    private static function skipSubBlocks(string $bytes, int $pos): int
    {
        while (true) {
            self::requireBytes($bytes, $pos, 1);
            $blockSize = ord($bytes[$pos]);
            ++$pos;
            if ($blockSize === 0) {
                break;
            }
            self::requireBytes($bytes, $pos, $blockSize);
            $pos += $blockSize;
        }

        return $pos;
    }

    /** @return array{0: string, 1: int} concatenated sub-block data, position after the terminator */
    private static function readSubBlocks(string $bytes, int $pos): array
    {
        $data = '';
        while (true) {
            self::requireBytes($bytes, $pos, 1);
            $blockSize = ord($bytes[$pos]);
            ++$pos;
            if ($blockSize === 0) {
                break;
            }
            self::requireBytes($bytes, $pos, $blockSize);
            $data .= substr($bytes, $pos, $blockSize);
            $pos += $blockSize;
        }

        return [$data, $pos];
    }

    /**
     * Every read of a length/offset taken from the file goes through here
     * first. Without it a truncated GIF walks off the end of the buffer,
     * which PHP reports as an "Uninitialized string offset" warning and
     * then quietly treats as a NUL byte -- so parsing "succeeds" with
     * garbage, or blows up inside an error handler that turns warnings
     * into exceptions.
     */
    private static function requireBytes(string $bytes, int $offset, int $count): void
    {
        if ($offset < 0 || $offset + $count > strlen($bytes)) {
            throw new InvalidArgumentException('Truncated GIF: the file ends mid-structure.');
        }
    }

    private static function deinterlace(string $indices, int $width, int $height): string
    {
        $rows = str_split($indices, $width);
        $result = array_fill(0, $height, '');

        // GIF89a Appendix E: four interlace passes.
        $passes = [[0, 8], [4, 8], [2, 4], [1, 2]];

        $sourceRow = 0;
        foreach ($passes as [$startRow, $step]) {
            for ($destRow = $startRow; $destRow < $height; $destRow += $step) {
                $result[$destRow] = $rows[$sourceRow] ?? str_repeat("\x00", $width);
                ++$sourceRow;
            }
        }

        return implode('', $result);
    }

    private static function readUint16LE(string $bytes, int $offset): int
    {
        return ord($bytes[$offset]) | (ord($bytes[$offset + 1]) << 8);
    }
}
