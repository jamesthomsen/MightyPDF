<?php

declare(strict_types=1);

namespace MightyPDF\Tests\Content;

use MightyPDF\Content\ContentStream;
use PHPUnit\Framework\TestCase;

final class ContentStreamTest extends TestCase
{
    public function testIsEmptyInitially(): void
    {
        self::assertTrue((new ContentStream())->isEmpty());
    }

    public function testTextShowingSequence(): void
    {
        $stream = (new ContentStream())
            ->beginText()
            ->setFont('F1', 12.0)
            ->showTextAt(72.0, 720.0, 'Hello')
            ->endText();

        self::assertSame(
            "BT\n/F1 12 Tf\n1 0 0 1 72 720 Tm\n(Hello) Tj\nET\n",
            $stream->bytes(),
        );
        self::assertFalse($stream->isEmpty());
    }

    public function testShowTextEscapesParensAndBackslashes(): void
    {
        $stream = (new ContentStream())->showTextAt(0, 0, 'a(b)c\\d');

        self::assertStringContainsString('(a\\(b\\)c\\\\d)', $stream->bytes());
    }

    public function testMethodsAreChainable(): void
    {
        $stream = new ContentStream();
        $result = $stream->beginText();

        self::assertSame($stream, $result);
    }
}
