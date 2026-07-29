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

    public function testLineDrawingSequence(): void
    {
        $stream = (new ContentStream())
            ->setLineWidth(2.0)
            ->setStrokeColorRgb(1.0, 0.0, 0.0)
            ->moveTo(10, 20)
            ->lineTo(30, 40)
            ->stroke();

        self::assertSame(
            "2 w\n1 0 0 RG\n10 20 m\n30 40 l\nS\n",
            $stream->bytes(),
        );
    }

    public function testRectangleFillSequence(): void
    {
        $stream = (new ContentStream())
            ->setFillColorRgb(0.0, 0.0, 1.0)
            ->rect(0, 0, 100, 50)
            ->fill();

        self::assertSame("0 0 1 rg\n0 0 100 50 re\nf\n", $stream->bytes());
    }

    public function testRectangleStrokeSequence(): void
    {
        $stream = (new ContentStream())->rect(0, 0, 10, 10)->stroke();

        self::assertSame("0 0 10 10 re\nS\n", $stream->bytes());
    }

    public function testFillAndStrokeOperator(): void
    {
        self::assertSame("B\n", (new ContentStream())->fillAndStroke()->bytes());
    }

    public function testClosePathOperator(): void
    {
        self::assertSame("h\n", (new ContentStream())->closePath()->bytes());
    }

    public function testCurveToOperator(): void
    {
        $stream = (new ContentStream())->curveTo(1, 2, 3, 4, 5, 6);

        self::assertSame("1 2 3 4 5 6 c\n", $stream->bytes());
    }
}
