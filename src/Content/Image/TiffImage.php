<?php

declare(strict_types=1);

namespace MightyPDF\Content\Image;

use MightyPDF\Assembler\ObjectHost;
use MightyPDF\Assembler\Stream;
use MightyPDF\Assembler\Types\PdfArray;
use MightyPDF\Assembler\Types\PdfBoolean;
use MightyPDF\Assembler\Types\PdfHexString;
use MightyPDF\Assembler\Types\PdfInteger;
use MightyPDF\Assembler\Types\PdfName;
use MightyPDF\Exception\InvalidArgumentException;
use MightyPDF\Reader\Filter\DecodeParms;
use MightyPDF\Reader\Filter\LzwDecode;
use MightyPDF\Reader\Filter\Predictor;
use MightyPDF\Reader\Filter\RunLengthDecode;

/**
 * Builds an Image XObject (ISO 32000-2 §8.9.5) from a TIFF file.
 *
 * TIFF is the format of scanning, and scanning is why this is worth
 * having: an incoming fax, a scanned contract, a batch of forms off a
 * document feeder all arrive as TIFF, and the job is nearly always to put
 * them into a PDF unchanged.
 *
 * **CCITT Group 3 and Group 4 are relayed, not decoded.** That is the
 * whole point rather than an optimisation. A G4 strip is already exactly
 * what PDF's /CCITTFaxDecode filter expects, so a scanned page goes into
 * the document as the same bytes it arrived as: no decoding, no
 * re-encoding, no generation loss, and a file that stays the size the
 * scanner made it rather than swelling to a bitmap. A 30 MB batch of
 * scans embeds in about 30 MB, where decoding and re-deflating it would
 * produce hundreds.
 *
 * Everything else is decoded and re-emitted as Flate: uncompressed, LZW,
 * PackBits and Deflate, in grayscale, RGB, palette or bilevel, with the
 * horizontal predictor undone where one was used.
 *
 * **Not supported**, and refused rather than mis-rendered: JPEG-in-TIFF
 * (compression 6 and 7), tiled images, separated planes
 * (/PlanarConfiguration 2), CMYK and YCbCr, and floating-point samples.
 * Each is a real format that a scanner essentially never emits, and each
 * would be a decoder of its own.
 */
final class TiffImage
{
    /** Guards against a directory claiming an image far larger than the file. */
    private const int MAX_PIXELS = 100_000_000;

    /**
     * MAX_PIXELS is not a memory bound, for the same reason PngImage says
     * so: the buffer is pixels times *samples* times bits, and only the
     * first of those three is bounded above. A 1x1 image declaring
     * /SamplesPerPixel of 2^32 asks for four gigabytes out of a hundred
     * bytes of file. Checked before anything allocates it.
     */
    private const int MAX_DECODED_BYTES = 128 * 1024 * 1024;

    /**
     * A pixel has at most four samples, that being as many components as
     * any PDF colour space this library writes: CMYK has four and is
     * refused, so in practice it is RGB's three plus an alpha channel
     * that is dropped. A file claiming more is not describing an image.
     */
    private const int MAX_SAMPLES_PER_PIXEL = 4;

    private function __construct()
    {
    }

    /**
     * @param int $page which image in the file, zero-based -- a TIFF may
     *        hold many, which is how a multi-page fax arrives
     */
    public static function fromBytes(ObjectHost $document, string $bytes, int $page = 0): Stream
    {
        $directories = TiffDirectory::all($bytes);

        if (!isset($directories[$page])) {
            throw new InvalidArgumentException(sprintf(
                'This TIFF holds %d image%s, numbered 0 to %d; there is no image %d.',
                count($directories),
                count($directories) === 1 ? '' : 's',
                count($directories) - 1,
                $page,
            ));
        }

        return self::build($document, $directories[$page]);
    }

    /** How many images the file holds. */
    public static function pageCount(string $bytes): int
    {
        return count(TiffDirectory::all($bytes));
    }

    private static function build(ObjectHost $document, TiffDirectory $tiff): Stream
    {
        $width = $tiff->value(TiffTag::ImageWidth->value, 0);
        $height = $tiff->value(TiffTag::ImageLength->value, 0);

        if ($width < 1 || $height < 1) {
            throw new InvalidArgumentException("This TIFF image declares a size of {$width}x{$height}.");
        }

        if ($width * $height > self::MAX_PIXELS) {
            throw new InvalidArgumentException(sprintf(
                'This TIFF declares %dx%d pixels, which is more than this library will allocate for one '
                . 'image. A file can claim any size it likes; decoding this one is how a small file asks '
                . 'for a great deal of memory.',
                $width,
                $height,
            ));
        }

        self::refuseWhatIsNotSupported($tiff);

        $compression = $tiff->value(TiffTag::Compression->value, 1);

        return match ($compression) {
            2, 3, 4 => self::relayFax($document, $tiff, $width, $height, $compression),
            default => self::decodeAndFlate($document, $tiff, $width, $height, $compression),
        };
    }

    private static function refuseWhatIsNotSupported(TiffDirectory $tiff): void
    {
        $compression = $tiff->value(TiffTag::Compression->value, 1);

        $unsupported = match ($compression) {
            6, 7 => 'JPEG-compressed',
            34_712 => 'JPEG 2000',
            default => null,
        };

        if ($unsupported !== null) {
            throw new InvalidArgumentException(
                "This TIFF is $unsupported, which this library does not read. Convert it, or -- if the "
                . 'payload really is JPEG -- extract it and place it with drawJpeg().',
            );
        }

        if ($tiff->value(TiffTag::PlanarConfiguration->value, 1) !== 1) {
            throw new InvalidArgumentException(
                'This TIFF stores its colour planes separately (/PlanarConfiguration 2) rather than '
                . 'interleaved. PDF images are interleaved, so the samples would have to be rewoven, '
                . 'which this library does not do.',
            );
        }

        // Tiled images use /TileOffsets in place of /StripOffsets.
        if ($tiff->stripCount() === 0) {
            throw new InvalidArgumentException(
                'This TIFF has no strips, which means it is tiled. Tiled TIFFs are a different layout '
                . 'that this library does not reassemble.',
            );
        }

        $photometric = $tiff->value(TiffTag::PhotometricInterpretation->value, 1);

        if ($photometric > 3) {
            throw new InvalidArgumentException(sprintf(
                'This TIFF is %s, which this library does not convert.',
                match ($photometric) {
                    5 => 'CMYK (separated)',
                    6 => 'YCbCr',
                    8, 9, 10 => 'CIE Lab',
                    default => "photometric interpretation $photometric",
                },
            ));
        }
    }

    /**
     * A fax-coded image, relayed into a /CCITTFaxDecode stream without
     * being decoded.
     *
     * The one real constraint is that it has to be a single strip. G3 and
     * G4 code each strip independently, so several strips are several
     * bitstreams; PDF has one filter over one stream and no way to say
     * "restart here". Concatenating them produces a file that looks right
     * and decodes to garbage after the first strip, which is exactly the
     * failure worth refusing rather than shipping.
     */
    private static function relayFax(
        ObjectHost $document,
        TiffDirectory $tiff,
        int $width,
        int $height,
        int $compression,
    ): Stream {
        if ($tiff->stripCount() > 1) {
            throw new InvalidArgumentException(sprintf(
                'This fax-coded TIFF is stored in %d strips, and each is coded independently, so they '
                . 'cannot be relayed into PDF as one stream. Re-save it with every row in one strip '
                . '(most tools call this "single strip" or a rows-per-strip of the image height).',
                $tiff->stripCount(),
            ));
        }

        $stream = new Stream($document->allocate(), $tiff->strip(0), compress: false);

        $parms = (new \MightyPDF\Assembler\Dictionary())
            ->set('Columns', new PdfInteger($width))
            ->set('Rows', new PdfInteger($height));

        // /K selects the coding: negative is pure two-dimensional (G4),
        // zero is one-dimensional (G3 one-dimensional and the older
        // modified Huffman), positive is mixed (G3 two-dimensional).
        $parms->set('K', new PdfInteger(match (true) {
            $compression === 4 => -1,
            $compression === 3 && (($tiff->value(TiffTag::T4Options->value, 0) & 1) !== 0) => 1,
            default => 0,
        }));

        // Compression 2 is modified Huffman, whose rows are padded to a
        // byte boundary; G3 says so in T4Options bit 2.
        if ($compression === 2 || ($tiff->value(TiffTag::T4Options->value, 0) & 4) !== 0) {
            $parms->set('EncodedByteAlign', new PdfBoolean(true));
        }

        // Polarity, and the one thing here that is worth getting right
        // first time: a fax embedded inverted is a page of white text on
        // black, which is unmistakable but only if somebody looks.
        //
        // A fax encoder codes runs as "white" and "black" relative to the
        // photometric interpretation it was given. The near-universal
        // /Photometric 0 (WhiteIsZero) is what CCITT assumes, and PDF's
        // default -- /BlackIs1 false, a black pixel decoding to 0, which
        // is black in DeviceGray -- matches it exactly. A file that
        // declares /Photometric 1 has had that convention flipped
        // underneath the coder, and so needs the flag flipped back.
        if ($tiff->value(TiffTag::PhotometricInterpretation->value, 0) === 1) {
            $parms->set('BlackIs1', new PdfBoolean(true));
        }

        if ($tiff->value(TiffTag::FillOrder->value, 1) === 2) {
            // Bits filled from the least significant end. PDF has no
            // equivalent flag, so the bytes are reversed on the way in.
            $stream->replaceBytes(self::reverseBits($stream->rawBytes()));
        }

        $stream->set('Filter', new PdfName('CCITTFaxDecode'));
        $stream->set('DecodeParms', $parms);

        return self::finish($document, $stream, $width, $height, new PdfName('DeviceGray'), 1);
    }

    /**
     * Everything that is not fax-coded: decoded to plain samples and
     * re-emitted with Flate.
     */
    private static function decodeAndFlate(
        ObjectHost $document,
        TiffDirectory $tiff,
        int $width,
        int $height,
        int $compression,
    ): Stream {
        $samplesPerPixel = $tiff->value(TiffTag::SamplesPerPixel->value, 1);
        $bits = $tiff->values(TiffTag::BitsPerSample->value)[0] ?? 1;

        if ($samplesPerPixel < 1 || $samplesPerPixel > self::MAX_SAMPLES_PER_PIXEL) {
            throw new InvalidArgumentException(sprintf(
                'This TIFF declares %d samples per pixel, and a pixel has at most %d. '
                . 'A file can claim any number it likes; this one is how a hundred bytes ask for a '
                . 'buffer of gigabytes.',
                $samplesPerPixel,
                self::MAX_SAMPLES_PER_PIXEL,
            ));
        }

        if (!in_array($bits, [1, 2, 4, 8, 16], true)) {
            throw new InvalidArgumentException("This TIFF has $bits bits per sample, which PDF has no image for.");
        }

        $pixels = self::decompress($tiff, $compression, $width, $height, $samplesPerPixel, $bits);

        $predictor = $tiff->value(TiffTag::Predictor->value, 1);

        if ($predictor === 2) {
            // TIFF's horizontal differencing is PDF predictor 2, and the
            // reader already has it.
            $pixels = Predictor::undo($pixels, new DecodeParms(
                predictor: 2,
                colors: $samplesPerPixel,
                bitsPerComponent: $bits,
                columns: $width,
            ));
        }

        $stream = new Stream($document->allocate(), $pixels);

        [$colorSpace, $components] = self::colorSpace($tiff, $samplesPerPixel);

        $photometric = $tiff->value(TiffTag::PhotometricInterpretation->value, 1);

        // WhiteIsZero: the samples run the other way from PDF's
        // DeviceGray, and /Decode inverts them without touching a pixel.
        if ($photometric === 0) {
            $stream->set('Decode', new PdfArray(new PdfInteger(1), new PdfInteger(0)));
        }

        return self::finish($document, $stream, $width, $height, $colorSpace, $bits, $components);
    }

    private static function decompress(
        TiffDirectory $tiff,
        int $compression,
        int $width,
        int $height,
        int $samplesPerPixel,
        int $bits,
    ): string {
        $expected = intdiv($width * $samplesPerPixel * $bits + 7, 8) * $height;

        // Before the buffer exists rather than after -- see
        // MAX_DECODED_BYTES. The three numbers are each bounded now, but
        // their product still is not: a hundred million pixels of 16-bit
        // RGB is six hundred megabytes of entirely well-formed image.
        if ($expected > self::MAX_DECODED_BYTES) {
            throw new InvalidArgumentException(sprintf(
                'This TIFF decodes to %d bytes of samples, which is more than this library will '
                . 'allocate for one image (%d). Its %dx%d pixels at %d bits across %d sample%s are '
                . 'what add up to it.',
                $expected,
                self::MAX_DECODED_BYTES,
                $width,
                $height,
                $bits,
                $samplesPerPixel,
                $samplesPerPixel === 1 ? '' : 's',
            ));
        }

        $out = '';

        for ($index = 0; $index < $tiff->stripCount(); ++$index) {
            $strip = $tiff->strip($index);

            $out .= match ($compression) {
                1 => $strip,
                5 => self::lzw($strip),
                8, 32_946 => self::inflate($strip),
                32_773 => (new RunLengthDecode())->decode($strip, new DecodeParms()),
                default => throw new InvalidArgumentException(
                    "This TIFF uses compression $compression, which this library does not read.",
                ),
            };

            // Once there are enough samples for the image, the strips
            // after them cannot be part of it. Stopping *at* the count
            // rather than past it is what keeps a deflate-bombed strip
            // from being followed by another one.
            if (strlen($out) >= $expected) {
                break;
            }
        }

        // Short data is padded rather than refused: a truncated scan
        // should place as a partly blank page, which is visible, rather
        // than as an exception in the middle of a batch.
        return str_pad(substr($out, 0, $expected), $expected, "\0");
    }

    /**
     * TIFF's LZW grows its code width one code early, which is the same
     * behaviour PDF's /EarlyChange defaults to -- so the reader's default
     * is already right and this is only here to say so.
     *
     * Worth saying because the opposite is a natural guess and its
     * symptom is not obviously a compression fault: the image decodes
     * correctly for a while and then degenerates, which reads like a
     * damaged file rather than a decoder using the wrong rule.
     */
    private static function lzw(string $strip): string
    {
        return (new LzwDecode())->decode($strip, new DecodeParms(earlyChange: 1));
    }

    /**
     * Bounded, unlike a bare gzuncompress(): Deflate is the one filter
     * here with no cost to the file for a thousandfold expansion, and the
     * reader's own FlateDecode caps itself at the same figure for the
     * same reason. A strip that will not fit in a whole image's worth of
     * samples is not a strip of one.
     */
    private static function inflate(string $strip): string
    {
        $out = @gzuncompress($strip, self::MAX_DECODED_BYTES);

        if ($out === false) {
            $out = @gzinflate($strip, self::MAX_DECODED_BYTES);
        }

        if ($out === false) {
            throw new InvalidArgumentException(sprintf(
                'This TIFF says it is Deflate-compressed and does not inflate -- either it is damaged, '
                . 'or one strip of it expands past the %d bytes this library will hold.',
                self::MAX_DECODED_BYTES,
            ));
        }

        return $out;
    }

    /**
     * @return array{PdfName|PdfArray, int} the colour space and how many
     *         components a pixel has in it
     */
    private static function colorSpace(TiffDirectory $tiff, int $samplesPerPixel): array
    {
        $photometric = $tiff->value(TiffTag::PhotometricInterpretation->value, 1);

        if ($photometric === 3) {
            return [self::palette($tiff), 1];
        }

        if ($photometric === 2 || $samplesPerPixel >= 3) {
            return [new PdfName('DeviceRGB'), 3];
        }

        return [new PdfName('DeviceGray'), 1];
    }

    /**
     * A palette image's /Indexed colour space.
     *
     * TIFF's colour map is three runs -- all the reds, then all the
     * greens, then all the blues -- of 16-bit values, where PDF wants them
     * interleaved and eight-bit. Both differences catch people out, and
     * the run layout silently produces a red-tinted image if missed.
     */
    private static function palette(TiffDirectory $tiff): PdfArray
    {
        $map = $tiff->values(TiffTag::ColorMap->value);
        $entries = intdiv(count($map), 3);

        if ($entries < 1) {
            throw new InvalidArgumentException('This TIFF says it is a palette image and has no colour map.');
        }

        $table = '';

        for ($index = 0; $index < $entries; ++$index) {
            foreach ([0, 1, 2] as $channel) {
                $table .= chr(($map[$channel * $entries + $index] ?? 0) >> 8);
            }
        }

        return new PdfArray(
            new PdfName('Indexed'),
            new PdfName('DeviceRGB'),
            new PdfInteger($entries - 1),
            new PdfHexString($table),
        );
    }

    private static function finish(
        ObjectHost $document,
        Stream $stream,
        int $width,
        int $height,
        PdfName|PdfArray $colorSpace,
        int $bits,
        int $components = 1,
    ): Stream {
        $stream->set('Type', new PdfName('XObject'));
        $stream->set('Subtype', new PdfName('Image'));
        $stream->set('Width', new PdfInteger($width));
        $stream->set('Height', new PdfInteger($height));
        $stream->set('ColorSpace', $colorSpace);
        $stream->set('BitsPerComponent', new PdfInteger($bits));

        $document->register($stream);

        return $stream;
    }

    /** Reverses the bits of every byte, for /FillOrder 2. */
    private static function reverseBits(string $bytes): string
    {
        static $table = null;

        if ($table === null) {
            $table = [];

            for ($byte = 0; $byte < 256; ++$byte) {
                $reversed = 0;

                for ($bit = 0; $bit < 8; ++$bit) {
                    $reversed |= (($byte >> $bit) & 1) << (7 - $bit);
                }

                $table[chr($byte)] = chr($reversed);
            }
        }

        return strtr($bytes, $table);
    }
}
