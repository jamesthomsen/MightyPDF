<?php

declare(strict_types=1);

namespace MightyPDF\Tests\Content\Barcode;

use MightyPDF\Content\Barcode\QrCode;
use MightyPDF\Content\Barcode\QrEccLevel;
use MightyPDF\Content\Barcode\QrMatrix;
use PHPUnit\Framework\TestCase;

/**
 * Reads the finished symbol back and checks the original string comes out
 * of it.
 *
 * This is the end-to-end check the rest of QrCodeTest cannot be: the
 * zigzag placement, the mask, the block interleaving and the bit packing
 * are each individually plausible and only jointly correct, and an error
 * in any of them produces a symbol that looks exactly like a QR code and
 * decodes to nothing.
 *
 * The reader here is deliberately naive -- it does no error correction at
 * all, so it only succeeds if every data module is exactly where a
 * decoder expects it. A real scanner would recover from what this cannot.
 */
final class QrCodeRoundTripTest extends TestCase
{
    private const string ALPHANUMERIC = '0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZ $%*+-./:';

    public static function values(): iterable
    {
        yield 'a digit' => ['7', QrEccLevel::Medium];
        yield 'numeric mode' => ['01234567', QrEccLevel::Medium];
        yield 'a long number' => [str_repeat('9876543210', 12), QrEccLevel::Low];
        yield 'alphanumeric mode' => ['HELLO WORLD', QrEccLevel::Quartile];
        yield 'alphanumeric, odd length' => ['MIGHTYPDF 2.0', QrEccLevel::High];
        yield 'byte mode' => ['https://example.com/invoice/2026-0417', QrEccLevel::Medium];
        yield 'utf-8 byte mode' => ['Rapport financier — 2026 · €248,50', QrEccLevel::Medium];
        yield 'a newline-separated payment string' => ["BCD\n002\n1\nSCT\nEUR248.50", QrEccLevel::Medium];

        // Sizes that cross both character-count-indicator boundaries and
        // land in multi-block versions, where the interleaving matters.
        yield 'version 10 or so' => [str_repeat('data payload ', 20), QrEccLevel::Medium];
        yield 'version 27 or so' => [str_repeat('data payload ', 90), QrEccLevel::Medium];
        yield 'near the maximum' => [str_repeat('x', 1200), QrEccLevel::Low];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('values')]
    public function testASymbolDecodesBackToWhatWasEncoded(string $value, QrEccLevel $level): void
    {
        $code = QrCode::encode($value, $level);

        self::assertSame($value, $this->decode($code, $level));
    }

    /**
     * Every mask, forced, so that a placement error masked into
     * invisibility by the one the encoder happened to pick cannot hide.
     */
    public function testEveryMaskDecodes(): void
    {
        foreach (range(0, 7) as $mask) {
            $code = $this->encodeWithMask('MIGHTYPDF 2.0', QrEccLevel::Quartile, $mask);

            self::assertSame('MIGHTYPDF 2.0', $this->decode($code, QrEccLevel::Quartile), "mask $mask");
        }
    }

    /**
     * The versions where the character count indicator widens (10 and
     * 27), on both sides of each boundary. Getting the width wrong shifts
     * the entire payload by two or four bits.
     */
    public function testTheVersionsWhereTheCountIndicatorWidens(): void
    {
        foreach ([9, 10, 26, 27] as $version) {
            $code = QrCode::encode('BOUNDARY', QrEccLevel::Medium, minVersion: $version);

            self::assertSame($version, $code->version);
            self::assertSame('BOUNDARY', $this->decode($code, QrEccLevel::Medium), "version $version");
        }
    }

    /** Builds a symbol under a chosen mask rather than the best-scoring one. */
    private function encodeWithMask(string $value, QrEccLevel $level, int $mask): QrCode
    {
        $natural = QrCode::encode($value, $level);
        $version = $natural->version;

        $mode = (new \ReflectionMethod(QrCode::class, 'modeFor'))->invoke(null, $value);
        $codewords = (new \ReflectionMethod(QrCode::class, 'codewords'))
            ->invoke(null, $value, $mode, $version, $level);
        $interleaved = (new \ReflectionMethod(QrCode::class, 'interleave'))
            ->invoke(null, $codewords, $version, $level);

        $matrix = new QrMatrix($version);
        $matrix->drawFunctionPatterns($level, $mask);
        $matrix->drawCodewords($interleaved);
        $matrix->applyMask($mask);

        $code = (new \ReflectionClass(QrCode::class))->newInstanceWithoutConstructor();
        (new \ReflectionMethod(QrCode::class, '__construct'))
            ->invoke($code, $version, $level, $mask, $matrix->modules());

        return $code;
    }

    /**
     * The whole decode: unmask, read the zigzag, un-interleave the
     * blocks, and parse the segment header and payload.
     */
    private function decode(QrCode $code, QrEccLevel $level): string
    {
        $codewords = $this->readCodewords($code, $level);
        $data = $this->deinterleave($codewords, $code->version, $level);

        return $this->parse($data, $code->version);
    }

    /**
     * Walks the same zigzag the encoder wrote, skipping function
     * patterns and undoing the mask as it goes.
     *
     * @return list<int>
     */
    private function readCodewords(QrCode $code, QrEccLevel $level): array
    {
        $size = $code->size();

        // The function-pattern map, rebuilt from a matrix of the same
        // version -- there is no way to tell a dark function module from
        // a dark data module by looking at it.
        $reference = new QrMatrix($code->version);
        $reference->drawFunctionPatterns($level, $code->mask);

        $property = new \ReflectionProperty(QrMatrix::class, 'isFunction');
        $isFunction = $property->getValue($reference);

        $modules = $code->modules();
        $bits = [];

        for ($right = $size - 1; $right >= 1; $right -= 2) {
            if ($right === 6) {
                $right = 5;
            }

            for ($vertical = 0; $vertical < $size; ++$vertical) {
                for ($column = 0; $column < 2; ++$column) {
                    $x = $right - $column;
                    $upward = (($right + 1) & 2) === 0;
                    $y = $upward ? $size - 1 - $vertical : $vertical;

                    if ($isFunction[$y][$x]) {
                        continue;
                    }

                    $bits[] = $modules[$y][$x] !== $this->maskInverts($code->mask, $x, $y) ? 1 : 0;
                }
            }
        }

        $codewords = [];

        // Whole bytes only: a symbol has a few remainder bits at the end
        // that belong to no codeword.
        foreach (array_chunk($bits, 8) as $chunk) {
            if (count($chunk) < 8) {
                break;
            }

            $byte = 0;

            foreach ($chunk as $bit) {
                $byte = ($byte << 1) | $bit;
            }

            $codewords[] = $byte;
        }

        return $codewords;
    }

    /**
     * Undoes the block interleaving, discarding the error-correction
     * codewords -- this reader trusts the symbol rather than repairing
     * it.
     *
     * @param list<int> $codewords
     *
     * @return list<int>
     */
    private function deinterleave(array $codewords, int $version, QrEccLevel $level): array
    {
        $blockCount = $this->constant('ECC_BLOCKS')[$level->value][$version];
        $eccLength = $this->constant('ECC_CODEWORDS_PER_BLOCK')[$level->value][$version];

        $raw = count($codewords);
        $shortBlocks = $blockCount - $raw % $blockCount;
        $shortLength = intdiv($raw, $blockCount) - $eccLength;

        $blocks = array_fill(0, $blockCount, []);
        $position = 0;

        for ($i = 0; $i <= $shortLength; ++$i) {
            for ($block = 0; $block < $blockCount; ++$block) {
                if ($i < $shortLength || $block >= $shortBlocks) {
                    $blocks[$block][] = $codewords[$position++];
                }
            }
        }

        return array_merge(...$blocks);
    }

    /**
     * Reads the segment header and payload out of the data codewords.
     *
     * @param list<int> $data
     */
    private function parse(array $data, int $version): string
    {
        $bits = [];

        foreach ($data as $byte) {
            for ($i = 7; $i >= 0; --$i) {
                $bits[] = ($byte >> $i) & 1;
            }
        }

        $position = 0;
        $read = static function (int $width) use ($bits, &$position): int {
            $value = 0;

            for ($i = 0; $i < $width; ++$i) {
                $value = ($value << 1) | ($bits[$position++] ?? 0);
            }

            return $value;
        };

        $mode = $read(4);

        $group = match (true) {
            $version <= 9 => 0,
            $version <= 26 => 1,
            default => 2,
        };

        $countBits = match ($mode) {
            1 => [10, 12, 14][$group],
            2 => [9, 11, 13][$group],
            default => [8, 16, 16][$group],
        };

        $count = $read($countBits);
        $out = '';

        if ($mode === 4) {
            for ($i = 0; $i < $count; ++$i) {
                $out .= chr($read(8));
            }

            return $out;
        }

        if ($mode === 2) {
            for ($i = 0; $i + 1 < $count; $i += 2) {
                $pair = $read(11);
                $out .= self::ALPHANUMERIC[intdiv($pair, 45)] . self::ALPHANUMERIC[$pair % 45];
            }

            return $count % 2 === 1 ? $out . self::ALPHANUMERIC[$read(6)] : $out;
        }

        for ($i = 0; $i + 2 < $count; $i += 3) {
            $out .= str_pad((string) $read(10), 3, '0', STR_PAD_LEFT);
        }

        return match ($count % 3) {
            1 => $out . str_pad((string) $read(4), 1, '0', STR_PAD_LEFT),
            2 => $out . str_pad((string) $read(7), 2, '0', STR_PAD_LEFT),
            default => $out,
        };
    }

    private function maskInverts(int $mask, int $x, int $y): bool
    {
        return match ($mask) {
            0 => ($x + $y) % 2 === 0,
            1 => $y % 2 === 0,
            2 => $x % 3 === 0,
            3 => ($x + $y) % 3 === 0,
            4 => (intdiv($x, 3) + intdiv($y, 2)) % 2 === 0,
            5 => $x * $y % 2 + $x * $y % 3 === 0,
            6 => ($x * $y % 2 + $x * $y % 3) % 2 === 0,
            default => (($x + $y) % 2 + $x * $y % 3) % 2 === 0,
        };
    }

    /** @return array<int, list<int>> */
    private function constant(string $name): array
    {
        return (new \ReflectionClass(QrCode::class))->getConstant($name);
    }
}
