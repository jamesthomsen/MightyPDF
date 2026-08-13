<?php

declare(strict_types=1);

namespace MightyPDF\Tests\Content\Svg;

use MightyPDF\Content\ContentStream;
use MightyPDF\Content\Svg\SvgDocument;
use MightyPDF\Content\Svg\SvgPattern;
use MightyPDF\Content\Svg\SvgRasterImage;
use MightyPDF\Content\Svg\SvgTransform;
use PHPUnit\Framework\Attributes\DataProvider;
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

    /**
     * Every caller divides the space it was given by these two, so a zero
     * reaches the placement as a DivisionByZeroError -- an \Error rather
     * than an \Exception, which goes straight past a caller catching what
     * the rest of this library throws.
     */
    #[DataProvider('degenerateViewBoxes')]
    public function testRefusesAViewBoxWithNoExtent(string $svg): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('coordinate system');

        SvgDocument::fromString($svg);
    }

    /** @return iterable<string, array{string}> */
    public static function degenerateViewBoxes(): iterable
    {
        yield 'both zero' => ['<svg viewBox="0 0 0 0"></svg>'];
        yield 'zero width' => ['<svg viewBox="0 0 0 100"></svg>'];
        yield 'zero height' => ['<svg viewBox="0 0 100 0"></svg>'];
        yield 'zero width and height attributes' => ['<svg width="0" height="0"></svg>'];
        // §7.7: a negative width or height is an error. Its symptom is a
        // drawing silently mirrored, which is worse than a refusal.
        yield 'negative' => ['<svg viewBox="0 0 -100 -100"></svg>'];
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

    /**
     * PDF has no "back to opaque" operator, so a partly transparent
     * shape has to be drawn inside its own graphics state. Without that,
     * every shape drawn after it in the file inherits its transparency
     * -- and only the ones after it, which makes the drawing depend on
     * the order it happens to be written in.
     */
    public function testTransparencyDoesNotLeakIntoTheNextShape(): void
    {
        $svg = SvgDocument::fromString(
            '<svg viewBox="0 0 10 10">'
            . '<rect x="0" y="0" width="4" height="4" fill="red" opacity="0.5"/>'
            . '<rect x="5" y="0" width="4" height="4" fill="blue"/>'
            . '</svg>',
        );

        $stream = new ContentStream();
        $svg->render($stream, static fn (): string => 'GS1');

        $bytes = $stream->bytes();

        self::assertStringContainsString("q\n/GS1 gs", $bytes);
        self::assertStringContainsString("f\nQ\n", $bytes);
        self::assertStringEndsWith("0 0 1 rg\n5 0 4 4 re\nf\n", $bytes, 'the opaque rect is drawn outside that state');
    }

    /** A line's stroke-opacity is a graphics state like any other's. */
    public function testALineIsDrawnUnderItsOwnGraphicsState(): void
    {
        $svg = SvgDocument::fromString(
            '<svg viewBox="0 0 10 10"><line x1="0" y1="0" x2="9" y2="9" stroke="red" stroke-opacity="0.25"/></svg>',
        );

        $stream = new ContentStream();
        $svg->render($stream, static fn (): string => 'GS1');

        self::assertStringContainsString("q\n/GS1 gs", $stream->bytes());
        self::assertStringContainsString("S\nQ\n", $stream->bytes());
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

    public function testFillsAShapeThroughThePatternColourSpace(): void
    {
        $bytes = self::renderWithGradients(
            '<rect x="0" y="0" width="10" height="10" fill="url(#g)"/>',
        );

        self::assertStringContainsString("/Pattern cs\n/P1 scn", $bytes);
        self::assertStringContainsString("f\n", $bytes);
    }

    public function testStrokesThroughThePatternColourSpaceToo(): void
    {
        $bytes = self::renderWithGradients(
            '<rect x="0" y="0" width="10" height="10" fill="none" stroke="url(#g)" stroke-width="2"/>',
        );

        self::assertStringContainsString("/Pattern CS\n/P1 SCN", $bytes);
        self::assertStringContainsString("2 w\n", $bytes);
        self::assertStringContainsString("S\n", $bytes);
    }

    /**
     * The pattern is given the shape's own box, so two shapes sharing
     * one gradient get one pattern each -- a gradient in the default
     * units means "across this shape", not "across the drawing".
     */
    public function testEachShapeGetsItsOwnPattern(): void
    {
        $bytes = self::renderWithGradients(
            '<rect x="0" y="0" width="10" height="4" fill="url(#g)"/>'
            . '<rect x="0" y="5" width="10" height="4" fill="url(#g)"/>',
        );

        self::assertStringContainsString('/P1 scn', $bytes);
        self::assertStringContainsString('/P2 scn', $bytes);
    }

    /**
     * A reference to a paint server that is not there is a broken
     * decoration, not a broken document -- it paints nothing, and the
     * path ends with "n" rather than being filled with whatever colour
     * happened to be set last.
     */
    public function testAGradientReferenceThatLeadsNowherePaintsNothing(): void
    {
        $bytes = self::renderWithGradients('<rect x="0" y="0" width="10" height="10" fill="url(#absent)"/>');

        self::assertStringNotContainsString('scn', $bytes);
        self::assertStringContainsString("n\n", $bytes);
        self::assertStringNotContainsString("f\n", $bytes);
    }

    /** A gradient between one colour and the same colour is a flat fill. */
    public function testAGradientOfOneColourIsPaintedAsAFlatFill(): void
    {
        $svg = SvgDocument::fromString(
            '<svg viewBox="0 0 10 10"><defs><linearGradient id="flat">'
            . '<stop offset="0" stop-color="#ff0000"/><stop offset="1" stop-color="#ff0000"/>'
            . '</linearGradient></defs>'
            . '<rect x="0" y="0" width="10" height="10" fill="url(#flat)"/></svg>',
        );

        $stream = new ContentStream();
        $svg->render($stream, $this->noExtGState(), static fn (): string => 'P1');

        self::assertStringContainsString('1 0 0 rg', $stream->bytes());
        self::assertStringNotContainsString('scn', $stream->bytes());
    }

    /**
     * A caller with nowhere to put pattern resources gets the behaviour
     * from before gradients were supported: the fill is skipped rather
     * than the document failing.
     */
    public function testGradientsPaintNothingWhenTheCallerCannotSupplyPatterns(): void
    {
        $svg = SvgDocument::fromString(
            '<svg viewBox="0 0 10 10"><defs><linearGradient id="g">'
            . '<stop offset="0" stop-color="#ff0000"/><stop offset="1" stop-color="#0000ff"/>'
            . '</linearGradient></defs>'
            . '<rect x="0" y="0" width="10" height="10" fill="url(#g)"/></svg>',
        );

        $stream = new ContentStream();
        $svg->render($stream, $this->noExtGState());

        self::assertStringNotContainsString('scn', $stream->bytes());
        self::assertStringContainsString("n\n", $stream->bytes());
    }

    /**
     * The matrix handed to the pattern has to include every transform
     * the shape sits under, because a PDF pattern is positioned from
     * the page and not from the transform in effect where it is used.
     */
    public function testThePatternMatrixCarriesTheEnclosingTransforms(): void
    {
        $svg = SvgDocument::fromString(
            '<svg viewBox="0 0 10 10"><defs><linearGradient id="g" gradientUnits="userSpaceOnUse">'
            . '<stop offset="0" stop-color="#ff0000"/><stop offset="1" stop-color="#0000ff"/>'
            . '</linearGradient></defs>'
            . '<g transform="translate(3 4)"><rect x="0" y="0" width="10" height="10" fill="url(#g)"/></g></svg>',
        );

        $seen = null;
        $svg->render(
            new ContentStream(),
            $this->noExtGState(),
            static function (mixed $gradient, array $matrix) use (&$seen): string {
                $seen = $matrix;

                return 'P1';
            },
            [2.0, 0.0, 0.0, 2.0, 0.0, 0.0],
        );

        // The group's translation happens inside the placement, so it
        // is scaled by it: 3 and 4 become 6 and 8.
        self::assertSame([2.0, 0.0, 0.0, 2.0, 6.0, 8.0], $seen);
    }

    /**
     * The cascade: a presentation attribute is the weakest styling
     * there is, a rule in a style block beats it, and the inline style
     * attribute beats both.
     */
    public function testStyleBlockRulesBeatPresentationAttributes(): void
    {
        $svg = SvgDocument::fromString(
            '<svg viewBox="0 0 10 10"><style>.brand { fill: #ff0000; }</style>'
            . '<rect x="0" y="0" width="1" height="1" class="brand" fill="#00ff00"/></svg>',
        );

        $stream = new ContentStream();
        $svg->render($stream, $this->noExtGState());

        self::assertStringContainsString('1 0 0 rg', $stream->bytes());
        self::assertStringNotContainsString('0 1 0 rg', $stream->bytes());
    }

    /**
     * The combinators end to end, since where an element sits is
     * something only the walk knows -- the stylesheet is handed it, and
     * a walk that hands over the wrong surroundings matches the wrong
     * elements while every unit test of the matcher still passes.
     */
    public function testSiblingCombinatorsMatchAgainstTheWalksOwnOrder(): void
    {
        $svg = SvgDocument::fromString(
            '<svg viewBox="0 0 10 10">'
            . '<style>rect + circle { fill: #ff0000; } rect ~ ellipse { fill: #00ff00; }</style>'
            . '<g><rect x="0" y="0" width="1" height="1"/>'
            . '<circle cx="2" cy="2" r="1"/>'
            . '<ellipse cx="5" cy="5" rx="1" ry="2"/></g>'
            . '<circle cx="8" cy="8" r="1"/></svg>',
        );

        $stream = new ContentStream();
        $svg->render($stream, $this->noExtGState());
        $bytes = $stream->bytes();

        // The circle just after the rect is red; the ellipse two along
        // is green; the circle in another group is neither.
        self::assertStringContainsString('1 0 0 rg', $bytes);
        self::assertStringContainsString('0 1 0 rg', $bytes);
        self::assertSame(1, substr_count($bytes, '1 0 0 rg'), 'only the adjacent circle is red');
    }

    /**
     * Where an element sits is carried as a chain, not as a copy of the
     * siblings before it -- which cost an array per element of a length
     * that grew with every one of them. An 813 KB drawing of plain
     * rectangles took three gigabytes, and doubling the shapes
     * quadrupled it.
     */
    public function testAFlatDrawingOfManyShapesCostsMemoryInProportion(): void
    {
        $shapes = 4000;
        $svg = SvgDocument::fromString(
            '<svg viewBox="0 0 10 10">'
            . str_repeat('<rect x="0" y="0" width="1" height="1" fill="#ff0000"/>', $shapes)
            . '</svg>',
        );

        $before = memory_get_usage();
        $svg->render(new ContentStream(), $this->noExtGState());
        $used = memory_get_usage() - $before;

        self::assertLessThan(4 * 1024 * 1024, $used, 'the walk keeps no per-element list of its predecessors');
    }

    public function testTheInlineStyleAttributeBeatsStyleBlockRules(): void
    {
        $svg = SvgDocument::fromString(
            '<svg viewBox="0 0 10 10"><style>.brand { fill: #ff0000; }</style>'
            . '<rect x="0" y="0" width="1" height="1" class="brand" style="fill:#0000ff"/></svg>',
        );

        $stream = new ContentStream();
        $svg->render($stream, $this->noExtGState());

        self::assertStringContainsString('0 0 1 rg', $stream->bytes());
    }

    /** A rule may be written after the elements it styles, or inside a group. */
    public function testAStyleBlockAppliesToTheWholeDocumentWhereverItSits(): void
    {
        $svg = SvgDocument::fromString(
            '<svg viewBox="0 0 10 10"><rect x="0" y="0" width="1" height="1" class="brand"/>'
            . '<style>.brand { fill: #ff0000; }</style></svg>',
        );

        $stream = new ContentStream();
        $svg->render($stream, $this->noExtGState());

        self::assertStringContainsString('1 0 0 rg', $stream->bytes());
    }

    public function testStyleBlockRulesAreInheritedThroughGroups(): void
    {
        $svg = SvgDocument::fromString(
            '<svg viewBox="0 0 10 10"><style>.themed { fill: #ff0000; }</style>'
            . '<g class="themed"><rect x="0" y="0" width="1" height="1"/></g></svg>',
        );

        $stream = new ContentStream();
        $svg->render($stream, $this->noExtGState());

        self::assertStringContainsString('1 0 0 rg', $stream->bytes());
    }

    public function testDrawsARasterImageCarriedInsideTheDrawing(): void
    {
        $svg = SvgDocument::fromString(
            '<svg viewBox="0 0 100 100"><image x="10" y="20" width="40" height="40" '
            . 'href="data:image/png;base64,' . base64_encode('pretend png') . '"/></svg>',
        );

        $stream = new ContentStream();
        $seen = null;

        $svg->render(
            $stream,
            $this->noExtGState(),
            null,
            [1.0, 0.0, 0.0, 1.0, 0.0, 0.0],
            static function (string $bytes) use (&$seen): SvgRasterImage {
                $seen = $bytes;

                return new SvgRasterImage('Im1', 40, 40);
            },
        );

        self::assertSame('pretend png', $seen);
        self::assertStringContainsString('40 0 0 -40 10 60 cm', $stream->bytes());
        self::assertStringContainsString("/Im1 Do", $stream->bytes());
    }

    public function testAnImageReferenceToAFileIsNotFollowed(): void
    {
        $svg = SvgDocument::fromString(
            '<svg viewBox="0 0 100 100"><image x="0" y="0" width="10" height="10" href="/etc/passwd"/></svg>',
        );

        $stream = new ContentStream();
        $svg->render(
            $stream,
            $this->noExtGState(),
            null,
            [1.0, 0.0, 0.0, 1.0, 0.0, 0.0],
            static fn (): never => throw new \LogicException('nothing should have been read'),
        );

        self::assertSame('', $stream->bytes());
    }

    /** Bytes the caller cannot decode are skipped, like any element that cannot be drawn. */
    public function testAnImageTheCallerCannotDecodeIsSkipped(): void
    {
        $svg = SvgDocument::fromString(
            '<svg viewBox="0 0 100 100"><image x="0" y="0" width="10" height="10" '
            . 'href="data:image/png;base64,' . base64_encode('not an image') . '"/></svg>',
        );

        $stream = new ContentStream();
        $svg->render($stream, $this->noExtGState(), null, [1.0, 0.0, 0.0, 1.0, 0.0, 0.0], static fn (): null => null);

        self::assertStringNotContainsString('Do', $stream->bytes());
    }

    public function testImagesAreSkippedWhenTheCallerCannotEmbedThem(): void
    {
        $svg = SvgDocument::fromString(
            '<svg viewBox="0 0 100 100"><image x="0" y="0" width="10" height="10" '
            . 'href="data:image/png;base64,' . base64_encode('pretend png') . '"/></svg>',
        );

        $stream = new ContentStream();
        $svg->render($stream, $this->noExtGState());

        self::assertSame('', $stream->bytes());
    }

    /**
     * A pattern's content is drawn by the renderer itself, since drawing
     * it needs everything the renderer has: the caller is handed the
     * finished operators and only has to make a resource of them.
     */
    public function testAPatternsContentIsDrawnAndHandedToTheCaller(): void
    {
        $tile = null;

        $bytes = self::renderWithPattern(
            '<pattern id="p" x="0" y="0" width="4" height="4" patternUnits="userSpaceOnUse">'
            . '<circle cx="2" cy="2" r="1" fill="#ff0000"/></pattern>',
            '<rect x="0" y="0" width="10" height="10" fill="url(#p)"/>',
            $tile,
        );

        self::assertStringContainsString('/Pattern cs', $bytes);
        self::assertStringContainsString('/P1 scn', $bytes);

        self::assertIsString($tile);
        self::assertStringContainsString('1 0 0 rg', $tile, 'the tile carries its own drawing');
        self::assertStringContainsString("f\n", $tile);
    }

    /**
     * A pattern whose own content is painted with it would draw a tile
     * to draw a tile to draw a tile.
     */
    public function testAPatternPaintedWithItselfStopsRatherThanRecurring(): void
    {
        $tile = null;

        $bytes = self::renderWithPattern(
            '<pattern id="p" x="0" y="0" width="4" height="4" patternUnits="userSpaceOnUse">'
            . '<rect x="0" y="0" width="4" height="4" fill="url(#p)"/></pattern>',
            '<rect x="0" y="0" width="10" height="10" fill="url(#p)"/>',
            $tile,
        );

        self::assertStringContainsString('/P1 scn', $bytes);
        self::assertIsString($tile);
        self::assertStringNotContainsString('scn', $tile, 'the inner reference paints nothing');
    }

    /**
     * A chain of patterns, each tile painted with the next one along, is
     * not circular -- so the check above never fires -- and doubles the
     * work at every link. A drawing of a few kilobytes used to make a
     * document of a hundred megabytes out of most of a gigabyte of
     * memory; see SvgTileBudget.
     */
    public function testAChainOfPatternsStopsRatherThanDoublingForever(): void
    {
        $defs = '';

        for ($i = 0; $i < 16; ++$i) {
            $fill = $i < 15 ? 'url(#p' . ($i + 1) . ')' : '#ff0000';
            $defs .= '<pattern id="p' . $i . '" x="0" y="0" width="4" height="4" patternUnits="userSpaceOnUse">'
                . '<rect x="0" y="0" width="4" height="4" fill="' . $fill . '"/>'
                . '<rect x="0" y="0" width="2" height="2" fill="' . $fill . '"/></pattern>';
        }

        $svg = SvgDocument::fromString(
            '<svg viewBox="0 0 10 10"><defs>' . $defs . '</defs>'
            . '<rect x="0" y="0" width="10" height="10" fill="url(#p0)"/></svg>',
        );

        $tiles = 0;
        $svg->render(
            new ContentStream(),
            $this->noExtGState(),
            static fn (): string => 'S1',
            SvgTransform::IDENTITY,
            null,
            null,
            static function () use (&$tiles): string {
                return 'P' . ++$tiles;
            },
        );

        // Unbounded, sixteen links of two would be 65 535 tiles.
        self::assertGreaterThan(0, $tiles, 'the outermost pattern is still painted');
        self::assertLessThan(64, $tiles);
    }

    /**
     * A caller with nowhere to put pattern resources gets the behaviour
     * from before patterns were supported.
     */
    public function testPatternsPaintNothingWhenTheCallerCannotSupplyThem(): void
    {
        $svg = SvgDocument::fromString(
            '<svg viewBox="0 0 10 10"><defs>'
            . '<pattern id="p" width="0.5" height="0.5"><circle r="1"/></pattern></defs>'
            . '<rect x="0" y="0" width="10" height="10" fill="url(#p)"/></svg>',
        );

        $stream = new ContentStream();
        $svg->render($stream, $this->noExtGState());

        self::assertStringNotContainsString('scn', $stream->bytes());
        self::assertStringContainsString("n\n", $stream->bytes());
    }

    private static function renderWithPattern(string $defs, string $body, ?string &$tile): string
    {
        $svg = SvgDocument::fromString(
            '<svg viewBox="0 0 10 10"><defs>' . $defs . '</defs>' . $body . '</svg>',
        );

        $stream = new ContentStream();

        $svg->render(
            $stream,
            static fn (): never => throw new \LogicException('No opacity < 1 expected in this test.'),
            static fn (): string => 'S1',
            SvgTransform::IDENTITY,
            null,
            null,
            static function (SvgPattern $pattern, string $content) use (&$tile): string {
                $tile ??= $content;

                return 'P1';
            },
        );

        return $stream->bytes();
    }

    private static function renderWithGradients(string $body): string
    {
        $svg = SvgDocument::fromString(
            '<svg viewBox="0 0 10 10"><defs><linearGradient id="g">'
            . '<stop offset="0" stop-color="#ff0000"/><stop offset="1" stop-color="#0000ff"/>'
            . '</linearGradient></defs>'
            . $body
            . '</svg>',
        );

        $stream = new ContentStream();
        $patterns = 0;

        $svg->render(
            $stream,
            static fn (): never => throw new \LogicException('No opacity < 1 expected in this test.'),
            static function () use (&$patterns): string {
                return 'P' . ++$patterns;
            },
        );

        return $stream->bytes();
    }
}
