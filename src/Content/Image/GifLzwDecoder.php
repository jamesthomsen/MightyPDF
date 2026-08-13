<?php

declare(strict_types=1);

namespace MightyPDF\Content\Image;

use MightyPDF\Exception\RuntimeException;

/**
 * The GIF-specific variant of LZW decompression (GIF89a spec, Appendix
 * F). Not byte-compatible with either PDF/TIFF's LZWDecode filter or
 * general-purpose zip/gzip LZW -- GIF has its own code-size-growth timing
 * ("early change") and initial dictionary layout, so this is a small,
 * separate, purpose-built decoder rather than reusing anything else.
 */
final class GifLzwDecoder
{
    /**
     * Codes are at most 12 bits wide, so 4095 is the last one a decoder
     * can ever read -- entries added beyond it are unreachable by
     * construction. A well-formed encoder emits a clear code before
     * reaching this point; one that never does would otherwise grow the
     * dictionary (and every string in it) without bound.
     */
    private const int MAX_CODE = 4095;

    private function __construct()
    {
    }

    /**
     * $maxOutputBytes bounds the decoded output, and is required rather
     * than defaulting to "unbounded" so no call site can accidentally opt
     * out: callers always know the expected size from the image
     * descriptor (width * height indices). LZW expands by ~2400x on
     * crafted input, and unlike a malformed-input exception, memory
     * exhaustion is a fatal error the caller cannot catch.
     */
    public static function decode(string $data, int $minCodeSize, int $maxOutputBytes): string
    {
        $clearCode = 1 << $minCodeSize;
        $endCode = $clearCode + 1;

        $codeSize = $minCodeSize + 1;
        $dictionary = self::initialDictionary($clearCode);
        $nextCode = $endCode + 1;

        $output = '';
        $bitBuffer = 0;
        $bitCount = 0;
        $bytePos = 0;
        $dataLength = strlen($data);
        $prevEntry = null;

        $readCode = function () use (&$bitBuffer, &$bitCount, &$bytePos, $data, $dataLength, &$codeSize): ?int {
            while ($bitCount < $codeSize) {
                if ($bytePos >= $dataLength) {
                    return null;
                }
                $bitBuffer |= ord($data[$bytePos]) << $bitCount;
                $bitCount += 8;
                ++$bytePos;
            }

            $code = $bitBuffer & ((1 << $codeSize) - 1);
            $bitBuffer >>= $codeSize;
            $bitCount -= $codeSize;

            return $code;
        };

        while (true) {
            $code = $readCode();
            if ($code === null || $code === $endCode) {
                break;
            }

            if ($code === $clearCode) {
                $dictionary = self::initialDictionary($clearCode);
                $codeSize = $minCodeSize + 1;
                $nextCode = $endCode + 1;
                $prevEntry = null;
                continue;
            }

            if ($prevEntry === null) {
                $entry = $dictionary[$code] ?? throw new RuntimeException("Invalid GIF LZW code: $code");
                $output .= $entry;
                $prevEntry = $entry;
                continue;
            }

            if (isset($dictionary[$code])) {
                $entry = $dictionary[$code];
            } elseif ($code === $nextCode) {
                $entry = $prevEntry . $prevEntry[0];
            } else {
                throw new RuntimeException("Invalid GIF LZW code: $code");
            }

            $output .= $entry;
            if (strlen($output) > $maxOutputBytes) {
                throw new RuntimeException('GIF LZW data decodes to more pixels than the image declares.');
            }

            // Codes above MAX_CODE can never be read back, so once the
            // dictionary is full it simply stops growing (the spec's
            // behaviour) rather than accumulating dead entries forever.
            if ($nextCode <= self::MAX_CODE) {
                $dictionary[$nextCode] = $prevEntry . $entry[0];
                ++$nextCode;

                if ($nextCode === (1 << $codeSize) && $codeSize < 12) {
                    ++$codeSize;
                }
            }

            $prevEntry = $entry;
        }

        return $output;
    }

    /** @return array<int, string> code => decoded byte string */
    private static function initialDictionary(int $clearCode): array
    {
        $dictionary = [];
        for ($i = 0; $i < $clearCode; ++$i) {
            $dictionary[$i] = chr($i);
        }

        return $dictionary;
    }
}
