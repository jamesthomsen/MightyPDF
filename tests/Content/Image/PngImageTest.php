<?php

declare(strict_types=1);

namespace MightyPDF\Tests\Content\Image;

use MightyPDF\Content\Image\PngImage;
use PHPUnit\Framework\TestCase;

final class PngImageTest extends TestCase
{
    private const string FIXTURES = __DIR__ . '/../../fixtures/images';

    public function testTruecolorPngProducesCorrectDictionaryEntries(): void
    {
        $rendered = PngImage::fromFile(1, self::FIXTURES . '/sample.png')->render(true);

        self::assertStringContainsString('/Width 4', $rendered);
        self::assertStringContainsString('/Height 4', $rendered);
        self::assertStringContainsString('/BitsPerComponent 8', $rendered);
        self::assertStringContainsString('/ColorSpace /DeviceRGB', $rendered);
        self::assertStringContainsString('/Filter /FlateDecode', $rendered);
        self::assertStringContainsString('/Predictor 15', $rendered);
        self::assertStringContainsString('/Colors 3', $rendered);
        self::assertStringContainsString('/Columns 4', $rendered);
    }

    public function testTruecolorPngIdatInflatesToOneFilterBytePlusPixelsPerRow(): void
    {
        $rendered = PngImage::fromFile(1, self::FIXTURES . '/sample.png')->render(true);

        preg_match('/stream\n(.*?)\nendstream/s', $rendered, $matches);
        $inflated = gzuncompress($matches[1]);

        // Every PNG scanline is exactly [1 filter-type byte][width * 3
        // RGB bytes], regardless of which of the 5 predictor algorithms
        // was used for that row -- true for all filter types, so this
        // length check doesn't depend on knowing which one PIL picked.
        self::assertSame(4 * (1 + 4 * 3), strlen($inflated));
    }

    public function testIndexedPngIncludesThePaletteAsAnIndexedColorSpace(): void
    {
        $rendered = PngImage::fromFile(1, self::FIXTURES . '/sample-indexed.png')->render(true);

        self::assertStringContainsString('/BitsPerComponent 4', $rendered);
        self::assertStringContainsString('/Colors 1', $rendered);
        self::assertStringContainsString(
            '/ColorSpace [/Indexed /DeviceRGB 15 '
                . '<ffffffffff0000ffff00ff00009600c864328080805a5a5a404040ff00ffff00002c00000a141e0000ff000096000000>]',
            $rendered,
        );
    }

    public function testRejectsPngWithAlphaChannel(): void
    {
        $this->expectException(\RuntimeException::class);
        PngImage::fromFile(1, self::FIXTURES . '/sample-alpha.png');
    }

    public function testRejectsNonPngData(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        PngImage::fromBytes(1, 'not a png');
    }
}
