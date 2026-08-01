<?php

declare(strict_types=1);

namespace MightyPDF\Content\Image;

use MightyPDF\Assembler\ObjectHost;
use MightyPDF\Assembler\Stream;
use MightyPDF\Assembler\Types\PdfInteger;
use MightyPDF\Assembler\Types\PdfName;

/**
 * Builds an Image XObject (ISO 32000-2 §8.9.5) from a JPEG file.
 *
 * JPEG's own compressed scan data is already exactly what PDF's
 * /Filter /DCTDecode expects, so the entire original file's bytes are
 * embedded verbatim -- no decoding or re-encoding, just a small marker
 * scan to find the SOF segment and read width/height/component count.
 */
final class JpegImage
{
    private function __construct()
    {
    }

    public static function fromFile(ObjectHost $registry, string $path): Stream
    {
        $bytes = file_get_contents($path);
        if ($bytes === false) {
            throw new \RuntimeException("Unable to read JPEG file: $path");
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
        [$width, $height, $components] = self::readHeader($bytes);

        $stream = new Stream($registry->allocate(), $bytes, compress: false);
        $stream->set('Type', new PdfName('XObject'));
        $stream->set('Subtype', new PdfName('Image'));
        $stream->set('Width', new PdfInteger($width));
        $stream->set('Height', new PdfInteger($height));
        $stream->set('BitsPerComponent', new PdfInteger(8));
        $stream->set('ColorSpace', new PdfName($components === 1 ? 'DeviceGray' : 'DeviceRGB'));
        $stream->set('Filter', new PdfName('DCTDecode'));

        return $stream;
    }

    /** @return array{0: int, 1: int, 2: int} width, height, component count */
    private static function readHeader(string $bytes): array
    {
        $length = strlen($bytes);
        if ($length < 4 || ord($bytes[0]) !== 0xFF || ord($bytes[1]) !== 0xD8) {
            throw new \InvalidArgumentException('Not a JPEG file (missing SOI marker).');
        }

        $pos = 2;
        while ($pos < $length - 1) {
            if (ord($bytes[$pos]) !== 0xFF) {
                throw new \InvalidArgumentException('Malformed JPEG: expected a marker.');
            }

            while ($pos < $length && ord($bytes[$pos]) === 0xFF) {
                ++$pos;
            }
            if ($pos >= $length) {
                break;
            }

            $marker = ord($bytes[$pos]);
            ++$pos;

            // Markers with no length/payload: TEM (0x01) and RSTn (0xD0-0xD7).
            if ($marker === 0x01 || ($marker >= 0xD0 && $marker <= 0xD7)) {
                continue;
            }
            if ($marker === 0xD9) {
                break; // EOI
            }
            if ($pos + 1 >= $length) {
                break;
            }

            $segmentLength = (ord($bytes[$pos]) << 8) | ord($bytes[$pos + 1]);

            // SOF0-SOF3, SOF5-SOF7, SOF9-SOF11, SOF13-SOF15 -- excludes
            // 0xC4 (DHT), 0xC8 (JPG extension, unused), 0xCC (DAC).
            $isSof = $marker >= 0xC0 && $marker <= 0xCF && $marker !== 0xC4 && $marker !== 0xC8 && $marker !== 0xCC;

            if ($isSof) {
                $payload = $pos + 2;
                if ($payload + 5 >= $length) {
                    throw new \InvalidArgumentException('Malformed JPEG: truncated SOF segment.');
                }

                $height = (ord($bytes[$payload + 1]) << 8) | ord($bytes[$payload + 2]);
                $width = (ord($bytes[$payload + 3]) << 8) | ord($bytes[$payload + 4]);
                $components = ord($bytes[$payload + 5]);

                return [$width, $height, $components];
            }

            $pos += $segmentLength;
        }

        throw new \InvalidArgumentException('Malformed JPEG: no SOF (start-of-frame) marker found.');
    }
}
