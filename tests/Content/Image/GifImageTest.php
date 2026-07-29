<?php

declare(strict_types=1);

namespace MightyPDF\Tests\Content\Image;

use MightyPDF\Content\Image\GifImage;
use PHPUnit\Framework\TestCase;

final class GifImageTest extends TestCase
{
    private const string FIXTURES = __DIR__ . '/../../fixtures/images';
    private const string EXPECTED_PALETTE_HEX = 'ffffffffff0000ffff00ff00009600c864328080805a5a5a404040ff00ffff00002c00000a141e0000ff000096000000';
    private const array EXPECTED_INDICES = [10, 3, 13, 1, 2, 9, 6, 0, 15, 8, 5, 12, 11, 4, 14, 7];

    public function testNonInterlacedGifDecodesToTheExpectedIndicesAndPalette(): void
    {
        $rendered = GifImage::fromFile(1, self::FIXTURES . '/sample.gif')->render(true);

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
        $rendered = GifImage::fromFile(1, self::FIXTURES . '/sample-interlaced.gif')->render(true);

        self::assertSame(self::EXPECTED_INDICES, $this->decodedIndices($rendered));
    }

    public function testTransparentColorIndexBecomesAColorKeyMask(): void
    {
        $rendered = GifImage::fromFile(1, self::FIXTURES . '/sample-transparent.gif')->render(true);

        self::assertStringContainsString('/Mask [0 0]', $rendered);
        self::assertSame(self::EXPECTED_INDICES, $this->decodedIndices($rendered));
    }

    public function testNonTransparentGifHasNoMaskEntry(): void
    {
        $rendered = GifImage::fromFile(1, self::FIXTURES . '/sample.gif')->render(true);

        self::assertStringNotContainsString('/Mask', $rendered);
    }

    public function testRejectsNonGifData(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        GifImage::fromBytes(1, 'not a gif');
    }

    /** @return list<int> */
    private function decodedIndices(string $rendered): array
    {
        preg_match('/stream\n(.*?)\nendstream/s', $rendered, $matches);
        $inflated = gzuncompress($matches[1]);

        return array_values(array_map(ord(...), str_split($inflated)));
    }
}
