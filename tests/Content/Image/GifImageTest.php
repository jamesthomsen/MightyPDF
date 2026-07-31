<?php

declare(strict_types=1);

namespace MightyPDF\Tests\Content\Image;

use MightyPDF\Assembler\IndirectObjectRegistry;
use MightyPDF\Content\Image\GifImage;
use PHPUnit\Framework\TestCase;

final class GifImageTest extends TestCase
{
    private const string FIXTURES = __DIR__ . '/../../fixtures/images';
    private const string EXPECTED_PALETTE_HEX = 'ffffffffff0000ffff00ff00009600c864328080805a5a5a404040ff00ffff00002c00000a141e0000ff000096000000';
    private const array EXPECTED_INDICES = [10, 3, 13, 1, 2, 9, 6, 0, 15, 8, 5, 12, 11, 4, 14, 7];

    public function testNonInterlacedGifDecodesToTheExpectedIndicesAndPalette(): void
    {
        $rendered = GifImage::fromFile(new IndirectObjectRegistry(), self::FIXTURES . '/sample.gif')->render(true);

        self::assertStringContainsString('/Width 4', $rendered);
        self::assertStringContainsString('/Height 4', $rendered);
        self::assertStringContainsString('[/Indexed /DeviceRGB 15 <' . self::EXPECTED_PALETTE_HEX . '>]', $rendered);
        self::assertSame(self::EXPECTED_INDICES, $this->decodedIndices($rendered));
    }

    public function testInterlacedGifIsDeinterlacedToMatchNormalRowOrder(): void
    {
        // This fixture was hand-built (Pillow can only read interlaced
        // GIFs, not write them) with rows physically stored in GIF's
        // 4-pass interlace order. Decoding it and getting back the same
        // normal top-to-bottom indices as the non-interlaced fixture is
        // the real proof the deinterlace step is correct.
        $rendered = GifImage::fromFile(new IndirectObjectRegistry(), self::FIXTURES . '/sample-interlaced.gif')->render(true);

        self::assertSame(self::EXPECTED_INDICES, $this->decodedIndices($rendered));
    }

    public function testTransparentColorIndexBecomesAColorKeyMask(): void
    {
        $rendered = GifImage::fromFile(new IndirectObjectRegistry(), self::FIXTURES . '/sample-transparent.gif')->render(true);

        self::assertStringContainsString('/Mask [0 0]', $rendered);
        self::assertSame(self::EXPECTED_INDICES, $this->decodedIndices($rendered));
    }

    public function testNonTransparentGifHasNoMaskEntry(): void
    {
        $rendered = GifImage::fromFile(new IndirectObjectRegistry(), self::FIXTURES . '/sample.gif')->render(true);

        self::assertStringNotContainsString('/Mask', $rendered);
    }

    public function testRejectsNonGifData(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        GifImage::fromBytes(new IndirectObjectRegistry(), 'not a gif');
    }

    /**
     * A sub-block chain that runs off the end of the file used to read
     * past the buffer -- PHP warns about the uninitialized offset, treats
     * it as NUL, and parsing "succeeds" with garbage.
     */
    public function testRejectsTruncatedFile(): void
    {
        $gif = 'GIF89a' . pack('vv', 4, 4) . chr(0x80) . chr(0) . chr(0)
            . str_repeat("\xFF", 6)
            . chr(0x2C) . pack('vvvv', 0, 0, 4, 4) . chr(0)
            . chr(2)
            . chr(200); // sub-block claims 200 bytes; the file ends here

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Truncated GIF');
        GifImage::fromBytes(new IndirectObjectRegistry(), $gif);
    }

    /**
     * GIF LZW expands by ~2400x on crafted input (each dictionary entry
     * one byte longer than the last), so a 44KB file declaring itself 4x4
     * decoded to ~100MB before the image descriptor's own dimensions were
     * used to bound it.
     */
    public function testRejectsLzwDataThatDecodesToMorePixelsThanDeclared(): void
    {
        $gif = 'GIF89a' . pack('vv', 4, 4) . chr(0x80) . chr(0) . chr(0)
            . str_repeat("\xFF", 6)
            . chr(0x2C) . pack('vvvv', 0, 0, 4, 4) . chr(0)
            . chr(8) . self::subBlocks(self::quadraticLzwPayload());

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('more pixels than the image declares');
        GifImage::fromBytes(new IndirectObjectRegistry(), $gif);
    }

    /**
     * LZW codes that always reference the entry just added, so each entry
     * is one byte longer than the one before it.
     */
    private static function quadraticLzwPayload(): string
    {
        $codeSize = 9;
        $nextCode = 258;
        $bits = '';
        $buffer = 0;
        $bitCount = 0;

        $emit = function (int $code) use (&$bits, &$buffer, &$bitCount, &$codeSize): void {
            $buffer |= $code << $bitCount;
            $bitCount += $codeSize;
            while ($bitCount >= 8) {
                $bits .= chr($buffer & 0xFF);
                $buffer >>= 8;
                $bitCount -= 8;
            }
        };

        $emit(0);
        for ($i = 0; $i < 30000; $i++) {
            $emit(min($nextCode, 4095));
            ++$nextCode;
            if ($nextCode === (1 << $codeSize) && $codeSize < 12) {
                ++$codeSize;
            }
        }
        if ($bitCount > 0) {
            $bits .= chr($buffer & 0xFF);
        }

        return $bits;
    }

    private static function subBlocks(string $data): string
    {
        $out = '';
        foreach (str_split($data, 255) as $block) {
            $out .= chr(strlen($block)) . $block;
        }

        return $out . chr(0);
    }

    /** @return list<int> */
    private function decodedIndices(string $rendered): array
    {
        preg_match('/stream\n(.*?)\nendstream/s', $rendered, $matches);
        $inflated = gzuncompress($matches[1]);

        return array_values(array_map(ord(...), str_split($inflated)));
    }
}
