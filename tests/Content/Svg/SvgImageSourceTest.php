<?php

declare(strict_types=1);

namespace MightyPDF\Tests\Content\Svg;

use MightyPDF\Content\Svg\SvgImageSource;
use PHPUnit\Framework\TestCase;

final class SvgImageSourceTest extends TestCase
{
    public function testReadsABase64DataUri(): void
    {
        $uri = 'data:image/png;base64,' . base64_encode('image bytes');

        self::assertSame('image bytes', SvgImageSource::bytes($uri));
    }

    public function testReadsADataUriThatIsNotBase64(): void
    {
        self::assertSame('a b', SvgImageSource::bytes('data:image/svg+xml,a%20b'));
    }

    public function testTheMediaTypeIsNotRequiredToBeThere(): void
    {
        self::assertSame('bytes', SvgImageSource::bytes('data:;base64,' . base64_encode('bytes')));
    }

    /**
     * An SVG may have come from anywhere, and following a path in one
     * would let a document it never wrote name a file this library then
     * reads and embeds. Skipped, like any other reference it cannot
     * safely resolve.
     */
    public function testDoesNotReadFilesFromDisk(): void
    {
        self::assertNull(SvgImageSource::bytes('/etc/passwd'));
        self::assertNull(SvgImageSource::bytes('file:///etc/passwd'));
        self::assertNull(SvgImageSource::bytes('../secrets/logo.png'));
        self::assertNull(SvgImageSource::bytes('logo.png'));
    }

    public function testDoesNotFetchFromTheNetwork(): void
    {
        self::assertNull(SvgImageSource::bytes('https://example.com/logo.png'));
        self::assertNull(SvgImageSource::bytes('//example.com/logo.png'));
    }

    /** Loose decoding would hand the image decoders bytes nobody meant. */
    public function testRejectsBase64ThatIsNotValid(): void
    {
        self::assertNull(SvgImageSource::bytes('data:image/png;base64,not valid base64!!'));
    }

    public function testRejectsADataUriWithNoPayloadAtAll(): void
    {
        self::assertNull(SvgImageSource::bytes('data:image/png;base64'));
    }

    public function testRejectsAPayloadTooLargeToBeADrawing(): void
    {
        $enormous = 'data:image/png;base64,' . str_repeat('A', 65 * 1024 * 1024);

        self::assertNull(SvgImageSource::bytes($enormous));
    }
}
