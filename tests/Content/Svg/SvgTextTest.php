<?php

declare(strict_types=1);

namespace MightyPDF\Tests\Content\Svg;

use MightyPDF\Assembler\Document;
use MightyPDF\Content\ContentStream;
use MightyPDF\Content\Font\StandardFont;
use MightyPDF\Content\PageBuilder;
use MightyPDF\Content\Svg\SvgDocument;
use MightyPDF\Content\Svg\SvgStyle;
use MightyPDF\Content\Svg\SvgTextFont;
use MightyPDF\Tests\Support\FakeSvgResources;
use PHPUnit\Framework\TestCase;

/**
 * Text in an SVG, at the renderer's level: what operators come out, and
 * which font each piece of text asked for.
 */
final class SvgTextTest extends TestCase
{
    public function testDrawsTextAtItsBaseline(): void
    {
        $bytes = self::render('<text x="10" y="20" font-size="12">Hi</text>');

        self::assertStringContainsString('/F1 12 Tf', $bytes);
        self::assertStringContainsString('(Hi) Tj', $bytes);
    }

    /**
     * The drawing is placed under a matrix that inverts the y axis, so
     * text needs a matrix that inverts it back -- otherwise every
     * letter comes out mirrored, which is obvious on screen and easy to
     * get wrong in code.
     */
    public function testTextIsFlippedBackTheRightWayUp(): void
    {
        $bytes = self::render('<text x="10" y="20" font-size="12">Hi</text>');

        self::assertStringContainsString('1 0 0 -1 10 20 Tm', $bytes);
    }

    public function testAnchoringShiftsTheTextAgainstItsPoint(): void
    {
        $start = self::penX(self::render('<text x="100" y="20" font-size="10">Hello</text>'));
        $middle = self::penX(self::render('<text x="100" y="20" font-size="10" text-anchor="middle">Hello</text>'));
        $end = self::penX(self::render('<text x="100" y="20" font-size="10" text-anchor="end">Hello</text>'));

        self::assertSame(100.0, $start);
        self::assertLessThan($start, $middle);
        self::assertLessThan($middle, $end);

        // "middle" centres the text on the point, so it starts half its
        // width to the left; "end" starts a full width to the left.
        $width = StandardFont::Helvetica->widthOfPt('Hello', 10.0);
        self::assertEqualsWithDelta(100.0 - $width / 2, $middle, 0.01);
        self::assertEqualsWithDelta(100.0 - $width, $end, 0.01);
    }

    public function testTspansAreDrawnInOrderAndCanRestyleTheirText(): void
    {
        $bytes = self::render(
            '<text x="0" y="10" font-size="10">before <tspan fill="#ff0000">red</tspan> after</text>',
        );

        self::assertStringContainsString('(before ) Tj', $bytes);
        self::assertStringContainsString('1 0 0 rg', $bytes);
        self::assertStringContainsString('(red) Tj', $bytes);
        self::assertStringContainsString('( after) Tj', $bytes);
    }

    /**
     * The space between a text node and a tspan is content, not
     * indentation. Trimming each piece as it is read is the easy
     * mistake, and it runs every word into the next.
     */
    public function testSpacesBetweenRunsSurvive(): void
    {
        $bytes = self::render('<text x="0" y="10">Runs: <tspan>one</tspan> and <tspan>two</tspan></text>');

        self::assertStringContainsString('(Runs: ) Tj', $bytes);
        self::assertStringContainsString('( and ) Tj', $bytes);
    }

    /** Indentation in pretty-printed markup is not text. */
    public function testWhitespaceAroundTheTextIsCollapsedAndTrimmed(): void
    {
        $bytes = self::render("<text x=\"0\" y=\"10\">\n      Hello    world\n    </text>");

        self::assertStringContainsString('(Hello world) Tj', $bytes);
    }

    public function testATspanCanMoveThePen(): void
    {
        $bytes = self::render('<text x="0" y="10">a<tspan x="50" dy="5">b</tspan></text>');

        self::assertStringContainsString('1 0 0 -1 50 15 Tm', $bytes);
    }

    public function testLetterSpacingIsAppliedAndMeasured(): void
    {
        $bytes = self::render('<text x="0" y="10" font-size="10" letter-spacing="4" text-anchor="end">ab</text>');

        self::assertStringContainsString('4 Tc', $bytes);

        // Two characters, so two lots of spacing are part of the width
        // the anchor measures.
        $width = StandardFont::Helvetica->widthOfPt('ab', 10.0) + 8.0;
        self::assertEqualsWithDelta(-$width, self::penX($bytes), 0.01);
    }

    /**
     * An SVG names a font family the way CSS does, as a list of
     * preferences ending in a generic name, and there is no font
     * catalogue here to look them up in. They are mapped onto the
     * standard 14 -- a caller who wants the drawing's own typeface
     * passes a resolver to drawSvg().
     */
    public function testFontFamilyChoosesAmongTheStandardFonts(): void
    {
        self::assertSame('Times-Roman', self::baseFontFor('font-family="Georgia, serif"'));
        self::assertSame('Courier', self::baseFontFor('font-family="monospace"'));
        self::assertSame('Helvetica', self::baseFontFor('font-family="Inter, sans-serif"'));
        self::assertSame('Helvetica', self::baseFontFor(''));
    }

    public function testWeightAndStyleChooseTheCutOfTheFont(): void
    {
        self::assertSame('Helvetica-Bold', self::baseFontFor('font-weight="bold"'));
        self::assertSame('Helvetica-Bold', self::baseFontFor('font-weight="700"'));
        self::assertSame('Helvetica', self::baseFontFor('font-weight="400"'));
        self::assertSame('Helvetica-Oblique', self::baseFontFor('font-style="italic"'));
        self::assertSame(
            'Times-BoldItalic',
            self::baseFontFor('font-family="serif" font-weight="bold" font-style="italic"'),
        );
    }

    /** A caller may choose the font itself, and be told what the text asked for. */
    public function testACallerSuppliedResolverChoosesTheFont(): void
    {
        $asked = null;
        $document = new Document();
        $font = StandardFont::TimesBold;

        (new PageBuilder($document, $document->newPage()))->drawSvg(
            self::svgFile('<text x="0" y="10" font-family="Brand Sans" font-weight="bold">Hi</text>'),
            0,
            0,
            100,
            100,
            function (string $family, bool $bold, bool $italic) use (&$asked, $font): StandardFont {
                $asked = [$family, $bold, $italic];

                return $font;
            },
        );

        self::assertSame(['Brand Sans', true, false], $asked);
        self::assertStringContainsString('/BaseFont /Times-Bold', $document->save());
    }

    public function testFontSizeIsInheritedThroughGroupsAndTspans(): void
    {
        $sizes = [];

        self::render(
            '<g font-size="20"><text x="0" y="0">outer<tspan font-size="50%">inner</tspan></text></g>',
            static function (SvgStyle $style) use (&$sizes): SvgTextFont {
                $sizes[] = $style->fontSizePt;

                return self::helvetica();
            },
        );

        // Collected once per run while measuring, and again while
        // drawing; both runs are what matters, not how often each is
        // asked about.
        self::assertContains(20.0, $sizes);
        self::assertContains(10.0, $sizes);
    }

    public function testTextIsSkippedWhenTheCallerSuppliesNoFont(): void
    {
        $svg = SvgDocument::fromString('<svg viewBox="0 0 10 10"><text x="0" y="0">Hi</text></svg>');

        $stream = new ContentStream();
        $svg->render($stream, new FakeSvgResources());

        self::assertSame('', $stream->bytes());
    }

    /** The /BaseFont a piece of text ends up drawn with, through the whole PageBuilder path. */
    private static function baseFontFor(string $attributes): string
    {
        $document = new Document();

        (new PageBuilder($document, $document->newPage()))->drawSvg(
            self::svgFile("<text x=\"0\" y=\"10\" $attributes>Hi</text>"),
            0,
            0,
            100,
            100,
        );

        self::assertSame(1, preg_match('#/BaseFont /([A-Za-z-]+)#', $document->save(), $matches));

        return $matches[1];
    }

    /** drawSvg() reads from a file, so the markup has to become one. */
    private static function svgFile(string $body): string
    {
        $path = tempnam(sys_get_temp_dir(), 'mightypdf-text') . '.svg';
        file_put_contents(
            $path,
            '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 100 100">' . $body . '</svg>',
        );

        register_shutdown_function(static fn () => @unlink($path));

        return $path;
    }

    /**
     * Every glyph on a path gets its own matrix: on a curve each sits at
     * its own point, turned to face its own direction, so a run cannot
     * be one operator the way a straight line of text is.
     */
    public function testTextOnAPathIsPlacedGlyphByGlyph(): void
    {
        $bytes = self::render(
            '<defs><path id="road" d="M 0 100 L 200 100"/></defs>'
            . '<text font-size="10"><textPath href="#road">Hi</textPath></text>',
        );

        self::assertSame(2, substr_count($bytes, ' Tj'), 'one operator per glyph');
        self::assertStringContainsString('(H) Tj', $bytes);
        self::assertStringContainsString('(i) Tj', $bytes);
    }

    /**
     * On a straight horizontal path the matrix has to come out as the
     * ordinary text one -- the flip against the placement and nothing
     * else. That is the check that the rotation is the right way round
     * rather than its mirror.
     */
    public function testOnAStraightPathTheMatrixIsTheOrdinaryTextMatrix(): void
    {
        $bytes = self::render(
            '<defs><path id="road" d="M 0 100 L 200 100"/></defs>'
            . '<text font-size="10"><textPath href="#road">H</textPath></text>',
        );

        self::assertStringContainsString('1 0 0 -1 0 100 Tm', $bytes);
    }

    public function testStartOffsetMovesTheTextAlongThePath(): void
    {
        $bytes = self::render(
            '<defs><path id="road" d="M 0 100 L 200 100"/></defs>'
            . '<text font-size="10"><textPath href="#road" startOffset="25%">H</textPath></text>',
        );

        self::assertStringContainsString('1 0 0 -1 50 100 Tm', $bytes);
    }

    public function testTextAnchorMeasuresTheWholeStringAgainstTheOffset(): void
    {
        $bytes = self::render(
            '<defs><path id="road" d="M 0 100 L 200 100"/></defs>'
            . '<text font-size="10" text-anchor="middle"><textPath href="#road" startOffset="100">Hi</textPath></text>',
        );

        // "Hi" is 9.44pt wide in Helvetica at 10pt, so the string
        // straddles the offset rather than starting at it.
        self::assertStringContainsString('1 0 0 -1 95.28 100 Tm', $bytes);
    }

    /** A glyph turned by the path carries the turn in its matrix. */
    public function testAGlyphOnAVerticalPathIsTurnedToFaceIt(): void
    {
        $bytes = self::render(
            '<defs><path id="road" d="M 100 0 L 100 200"/></defs>'
            . '<text font-size="10"><textPath href="#road">H</textPath></text>',
        );

        // Heading straight down the page: the glyph's own x-axis points
        // that way, and its y-axis off to the left of it.
        self::assertMatchesRegularExpression('/0 1 1 -?0 100 /', $bytes);
    }

    public function testGlyphsPastTheEndOfThePathAreNotDrawn(): void
    {
        $bytes = self::render(
            '<defs><path id="road" d="M 0 100 L 12 100"/></defs>'
            . '<text font-size="10"><textPath href="#road">Hello world</textPath></text>',
        );

        self::assertSame(2, substr_count($bytes, ' Tj'), 'only what fits on the path');
    }

    public function testATextPathNamingNothingDrawsNothing(): void
    {
        $bytes = self::render('<text font-size="10"><textPath href="#absent">Hi</textPath></text>');

        self::assertStringNotContainsString('Tj', $bytes);
    }

    private static function render(string $body, ?\Closure $fontResolver = null): string
    {
        $svg = SvgDocument::fromString('<svg viewBox="0 0 200 200">' . $body . '</svg>');

        $stream = new ContentStream();
        $svg->render(
            $stream,
            new FakeSvgResources(extGState: static fn (): string => 'GS1'),
            [1.0, 0.0, 0.0, 1.0, 0.0, 0.0],
            $fontResolver ?? static fn (): SvgTextFont => self::helvetica(),
        );

        return $stream->bytes();
    }

    private static function helvetica(): SvgTextFont
    {
        $font = StandardFont::Helvetica;

        return new SvgTextFont('F1', $font, $font->writerFor(new Document()));
    }

    /** The x the text matrix put the pen at. */
    private static function penX(string $bytes): float
    {
        self::assertSame(1, preg_match('/1 0 0 -1 (-?[\d.]+) /', $bytes, $matches), $bytes);

        return (float) $matches[1];
    }
}
