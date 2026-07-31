<?php

declare(strict_types=1);

namespace MightyPDF\Tests\Content\Image;

use MightyPDF\Assembler\IndirectObjectRegistry;
use MightyPDF\Content\Image\JpegImage;
use PHPUnit\Framework\TestCase;

final class JpegImageTest extends TestCase
{
    private const string FIXTURE = __DIR__ . '/../../fixtures/images/sample.jpg';

    public function testReadsCorrectWidthHeightAndColorSpace(): void
    {
        $rendered = JpegImage::fromFile(new IndirectObjectRegistry(), self::FIXTURE)->render(true);

        self::assertStringContainsString('/Width 4', $rendered);
        self::assertStringContainsString('/Height 4', $rendered);
        self::assertStringContainsString('/ColorSpace /DeviceRGB', $rendered);
        self::assertStringContainsString('/BitsPerComponent 8', $rendered);
        self::assertStringContainsString('/Filter /DCTDecode', $rendered);
        self::assertStringContainsString('/Type /XObject', $rendered);
        self::assertStringContainsString('/Subtype /Image', $rendered);
    }

    public function testEmbedsTheOriginalFileBytesVerbatim(): void
    {
        $originalBytes = file_get_contents(self::FIXTURE);
        self::assertIsString($originalBytes);

        $rendered = JpegImage::fromFile(new IndirectObjectRegistry(), self::FIXTURE)->render(true);

        preg_match('/stream\n(.*?)\nendstream/s', $rendered, $matches);
        self::assertSame($originalBytes, $matches[1]);
    }

    public function testRejectsDataWithNoSoiMarker(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        JpegImage::fromBytes(new IndirectObjectRegistry(), 'not a jpeg');
    }

    public function testRejectsDataWithNoSofMarker(): void
    {
        // Valid SOI + immediate EOI, but no frame header at all.
        $this->expectException(\InvalidArgumentException::class);
        JpegImage::fromBytes(new IndirectObjectRegistry(), "\xFF\xD8\xFF\xD9");
    }
}
