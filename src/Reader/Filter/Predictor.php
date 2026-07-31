<?php

declare(strict_types=1);

namespace MightyPDF\Reader\Filter;

use MightyPDF\Png\ScanlineFilter;
use MightyPDF\Reader\ParseException;

/**
 * Undoes the /Predictor transform that Flate- and LZW-encoded streams may
 * apply before compression (ISO 32000-2 §7.4.4.4).
 *
 * Predicting stores each byte as its difference from a neighbour, which
 * costs nothing and makes the result far more compressible. It is not an
 * optional nicety to support: cross-reference streams are conventionally
 * written with PNG "Up" prediction, so a reader without this cannot read
 * the cross-reference table of a modern PDF at all, and therefore cannot
 * open one.
 *
 * The per-row arithmetic is PNG's own, and is shared with the PNG image
 * decoder as ScanlineFilter. What is specific to /Predictor is the
 * framing: the row geometry comes from /Colors, /BitsPerComponent and
 * /Columns (so a row's pixels need not land on byte boundaries), and a
 * short final row is padded rather than rejected.
 */
final class Predictor
{
    public static function undo(string $data, DecodeParms $parms): string
    {
        // 1 means "no prediction"; 0 and negatives are not legal values,
        // but treating them as "none" beats failing over a parameter that
        // was never going to change the bytes.
        if ($parms->predictor <= 1) {
            return $data;
        }

        return $parms->predictor === 2
            ? self::undoTiff($data, $parms)
            : self::undoPng($data, $parms);
    }

    private static function undoPng(string $data, DecodeParms $parms): string
    {
        $bytesPerPixel = self::bytesPerPixel($parms);
        $rowLength = self::rowLength($parms);

        $out = '';
        $previous = str_repeat("\x00", $rowLength);
        $offset = 0;
        $length = strlen($data);

        // Each row is preceded by a filter-type byte, which is why the
        // predicted data is one byte per row longer than the image.
        while ($offset < $length) {
            $filterType = ord($data[$offset++]);

            // A short final row means the stream was truncated. Padding
            // it degrades gracefully -- in a cross-reference stream the
            // zeroes read back as a free entry, which is ignored --
            // whereas throwing would lose the whole document over damage
            // at the very end of it.
            $row = str_pad(substr($data, $offset, $rowLength), $rowLength, "\x00");
            $offset += $rowLength;

            $reconstructed = ScanlineFilter::reconstructRow($filterType, $row, $previous, $bytesPerPixel)
                ?? throw new ParseException("Unknown PNG predictor filter type $filterType.");

            $out .= $reconstructed;
            $previous = $reconstructed;
        }

        return $out;
    }

    /**
     * TIFF predictor 2: every component is stored as its difference from
     * the same component of the pixel to its left.
     */
    private static function undoTiff(string $data, DecodeParms $parms): string
    {
        if ($parms->bitsPerComponent !== 8) {
            throw new ParseException(
                "TIFF prediction at {$parms->bitsPerComponent} bits per component is not supported (only 8).",
            );
        }

        $bytesPerPixel = $parms->colors;
        $rowLength = self::rowLength($parms);

        if ($rowLength === 0) {
            return $data;
        }

        $out = '';

        foreach (str_split($data, $rowLength) as $row) {
            for ($i = $bytesPerPixel; $i < strlen($row); ++$i) {
                $row[$i] = chr((ord($row[$i]) + ord($row[$i - $bytesPerPixel])) & 0xFF);
            }

            $out .= $row;
        }

        return $out;
    }

    /**
     * The distance between a byte and the one predicting it. Rounded up
     * and floored at 1, because for sub-byte depths (a 1-bit mask, say)
     * consecutive pixels share a byte and the predictor works on the
     * previous *byte*.
     */
    private static function bytesPerPixel(DecodeParms $parms): int
    {
        return max(1, intdiv($parms->colors * $parms->bitsPerComponent + 7, 8));
    }

    private static function rowLength(DecodeParms $parms): int
    {
        return intdiv($parms->colors * $parms->bitsPerComponent * $parms->columns + 7, 8);
    }
}
