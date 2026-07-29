<?php

declare(strict_types=1);

namespace MightyPDF\Tests\Content\Svg;

use MightyPDF\Content\ContentStream;
use MightyPDF\Content\Svg\SvgDocument;
use PHPUnit\Framework\TestCase;

final class SvgDocumentTest extends TestCase
{
    private function noExtGState(): \Closure
    {
        return function (): never {
            throw new \LogicException('No opacity < 1 expected in this test.');
        };
    }

    public function testReadsViewBox(): void
    {
        $svg = SvgDocument::fromString('<svg viewBox="0 0 100 50"></svg>');

        self::assertSame(0.0, $svg->viewBoxX);
        self::assertSame(0.0, $svg->viewBoxY);
        self::assertSame(100.0, $svg->viewBoxWidth);
        self::assertSame(50.0, $svg->viewBoxHeight);
    }

    public function testFallsBackToWidthHeightWhenNoViewBox(): void
    {
        $svg = SvgDocument::fromString('<svg width="200" height="100"></svg>');

        self::assertSame(200.0, $svg->viewBoxWidth);
        self::assertSame(100.0, $svg->viewBoxHeight);
    }

    public function testWidthHeightWithUnitSuffixesAreStripped(): void
    {
        $svg = SvgDocument::fromString('<svg width="200px" height="100pt"></svg>');

        self::assertSame(200.0, $svg->viewBoxWidth);
        self::assertSame(100.0, $svg->viewBoxHeight);
    }

    public function testThrowsWithNoViewBoxOrWidthHeight(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        SvgDocument::fromString('<svg></svg>');
    }

    public function testRendersARectWithFill(): void
    {
        $svg = SvgDocument::fromString('<svg viewBox="0 0 10 10"><rect x="1" y="2" width="3" height="4" fill="#FF0000"/></svg>');

        $stream = new ContentStream();
        $svg->render($stream, $this->noExtGState());

        $bytes = $stream->bytes();
        self::assertStringContainsString('1 0 0 rg', $bytes);
        self::assertStringContainsString('1 2 3 4 re', $bytes);
        self::assertStringContainsString('f', $bytes);
    }

    public function testRendersACircleAsAClosedBezierPath(): void
    {
        $svg = SvgDocument::fromString('<svg viewBox="0 0 10 10"><circle cx="5" cy="5" r="5" fill="blue"/></svg>');

        $stream = new ContentStream();
        $svg->render($stream, $this->noExtGState());

        $bytes = $stream->bytes();
        self::assertStringContainsString('10 5 m', $bytes); // rightmost point: cx+r, cy
        self::assertStringContainsString('h', $bytes); // closed
    }

    public function testStrokeOnlyRectHasNoFillOperator(): void
    {
        $svg = SvgDocument::fromString(
            '<svg viewBox="0 0 10 10"><rect x="0" y="0" width="10" height="10" fill="none" stroke="black" stroke-width="2"/></svg>',
        );

        $stream = new ContentStream();
        $svg->render($stream, $this->noExtGState());

        $bytes = $stream->bytes();
        self::assertStringContainsString('2 w', $bytes);
        self::assertStringContainsString("S\n", $bytes);
        self::assertStringNotContainsString("f\n", $bytes);
    }

    public function testGroupFillIsInheritedByChildren(): void
    {
        $svg = SvgDocument::fromString(
            '<svg viewBox="0 0 10 10"><g fill="#00FF00"><rect x="0" y="0" width="1" height="1"/><circle cx="5" cy="5" r="1"/></g></svg>',
        );

        $stream = new ContentStream();
        $svg->render($stream, $this->noExtGState());

        self::assertSame(2, substr_count($stream->bytes(), '0 1 0 rg'));
    }

    public function testChildFillOverridesInheritedGroupFill(): void
    {
        $svg = SvgDocument::fromString(
            '<svg viewBox="0 0 10 10"><g fill="#00FF00"><rect x="0" y="0" width="1" height="1" fill="#0000FF"/></g></svg>',
        );

        $stream = new ContentStream();
        $svg->render($stream, $this->noExtGState());

        self::assertStringContainsString('0 0 1 rg', $stream->bytes());
        self::assertStringNotContainsString('0 1 0 rg', $stream->bytes());
    }

    public function testGroupTransformWrapsChildrenInPushPopGraphicsState(): void
    {
        $svg = SvgDocument::fromString(
            '<svg viewBox="0 0 10 10"><g transform="translate(5,5)"><rect x="0" y="0" width="1" height="1"/></g></svg>',
        );

        $stream = new ContentStream();
        $svg->render($stream, $this->noExtGState());

        $bytes = $stream->bytes();
        self::assertStringContainsString("q\n", $bytes);
        self::assertStringContainsString('1 0 0 1 5 5 cm', $bytes);
        self::assertStringContainsString("Q\n", $bytes);
    }

    public function testOpacityBelowOneRequestsAnExtGStateResource(): void
    {
        $svg = SvgDocument::fromString(
            '<svg viewBox="0 0 10 10"><rect x="0" y="0" width="1" height="1" fill="red" opacity="0.5"/></svg>',
        );

        $requested = [];
        $stream = new ContentStream();
        $svg->render($stream, function (float $fillAlpha, float $strokeAlpha) use (&$requested): string {
            $requested[] = [$fillAlpha, $strokeAlpha];

            return 'GS1';
        });

        self::assertCount(1, $requested);
        self::assertEqualsWithDelta(0.5, $requested[0][0], 1e-9);
        self::assertStringContainsString('/GS1 gs', $stream->bytes());
    }

    public function testOpacityOfOneDoesNotRequestAnExtGStateResource(): void
    {
        $svg = SvgDocument::fromString(
            '<svg viewBox="0 0 10 10"><rect x="0" y="0" width="1" height="1" fill="red"/></svg>',
        );

        $stream = new ContentStream();
        $svg->render($stream, $this->noExtGState());

        self::assertStringNotContainsString('gs', $stream->bytes());
    }

    public function testDefsAndTextElementsAreSkipped(): void
    {
        $svg = SvgDocument::fromString(
            '<svg viewBox="0 0 10 10">'
                . '<defs><rect x="0" y="0" width="1" height="1" fill="red"/></defs>'
                . '<text x="0" y="0">hello</text>'
                . '</svg>',
        );

        $stream = new ContentStream();
        $svg->render($stream, $this->noExtGState());

        self::assertSame('', $stream->bytes());
    }

    public function testStyleAttributeIsParsedLikePresentationAttributes(): void
    {
        $svg = SvgDocument::fromString(
            '<svg viewBox="0 0 10 10"><rect x="0" y="0" width="1" height="1" style="fill:#FF0000;stroke:#0000FF"/></svg>',
        );

        $stream = new ContentStream();
        $svg->render($stream, $this->noExtGState());

        $bytes = $stream->bytes();
        self::assertStringContainsString('1 0 0 rg', $bytes);
        self::assertStringContainsString('0 0 1 RG', $bytes);
    }

    public function testPolygonClosesThePathAndPolylineDoesNot(): void
    {
        $polygon = SvgDocument::fromString(
            '<svg viewBox="0 0 10 10"><polygon points="0,0 5,0 5,5" fill="red"/></svg>',
        );
        $polygonStream = new ContentStream();
        $polygon->render($polygonStream, $this->noExtGState());
        self::assertStringContainsString("h\n", $polygonStream->bytes());

        $polyline = SvgDocument::fromString(
            '<svg viewBox="0 0 10 10"><polyline points="0,0 5,0 5,5" stroke="red"/></svg>',
        );
        $polylineStream = new ContentStream();
        $polyline->render($polylineStream, $this->noExtGState());
        self::assertStringNotContainsString("h\n", $polylineStream->bytes());
    }
}
