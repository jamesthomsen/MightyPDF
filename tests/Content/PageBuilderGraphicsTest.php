<?php

declare(strict_types=1);

namespace MightyPDF\Tests\Content;

use MightyPDF\Assembler\Dictionary;
use MightyPDF\Assembler\Document;
use MightyPDF\Assembler\Page;
use MightyPDF\Content\CmykColor;
use MightyPDF\Content\Color;
use MightyPDF\Content\Dash;
use MightyPDF\Content\Font\StandardFont;
use MightyPDF\Content\LineCap;
use MightyPDF\Content\LineJoin;
use MightyPDF\Content\PageBuilder;
use MightyPDF\Content\PathSink;
use MightyPDF\Content\SpotColor;
use MightyPDF\Content\Stroke;
use PHPUnit\Framework\TestCase;

final class PageBuilderGraphicsTest extends TestCase
{
    public function testFillAndStrokeTogetherUseTheCombinedOperator(): void
    {
        [$page, $builder] = $this->page();

        $builder->drawRectangle(10, 10, 50, 20, Color::black(), new Stroke());

        self::assertStringContainsString("\nB\n", $this->operators($page));
    }

    public function testFillAloneFillsAndStrokeAloneStrokes(): void
    {
        [$fillPage, $fillBuilder] = $this->page();
        $fillBuilder->drawRectangle(10, 10, 50, 20, fill: Color::black());

        [$strokePage, $strokeBuilder] = $this->page();
        $strokeBuilder->drawRectangle(10, 10, 50, 20, stroke: new Stroke());

        self::assertStringContainsString("\nf\n", $this->operators($fillPage));
        self::assertStringNotContainsString("\nS\n", $this->operators($fillPage));

        self::assertStringContainsString("\nS\n", $this->operators($strokePage));
        self::assertStringNotContainsString("\nf\n", $this->operators($strokePage));
    }

    public function testEvenOddPicksTheStarredOperator(): void
    {
        [$page, $builder] = $this->page();

        $builder->drawPolygon([[0.0, 0.0], [10.0, 0.0], [10.0, 10.0]], Color::black(), evenOdd: true);

        self::assertStringContainsString("f*\n", $this->operators($page));
    }

    /**
     * A shape asked to be neither filled nor stroked draws nothing at
     * all, rather than raising -- which is what lets Layout\Flow hand a
     * Style straight through without first asking whether there is
     * anything to paint.
     */
    public function testAShapeWithNeitherFillNorStrokeDrawsNothing(): void
    {
        [$page, $builder] = $this->page();

        $builder->drawRectangle(10, 10, 50, 20);

        self::assertSame([], $page->contentStreams(), 'no content stream should even be allocated');
    }

    /**
     * Everything a paint or a stroke sets is graphics state, and a dash
     * pattern or a separation colour space left in effect would show up
     * on whatever is drawn next. Confining each shape to a q/Q is what
     * stops that.
     */
    public function testEveryShapeIsConfinedToItsOwnGraphicsState(): void
    {
        [$page, $builder] = $this->page();

        $builder->drawCircle(50, 50, 20, Color::black(), Stroke::dashed())
            ->drawRectangle(10, 10, 5, 5, fill: Color::white());

        $operators = $this->operators($page);

        self::assertSame(2, substr_count($operators, "q\n"));
        self::assertSame(2, substr_count($operators, "Q\n"));
        self::assertSame(1, substr_count($operators, ' d'. "\n"), 'the dash belongs to one shape only');
    }

    public function testStrokeWritesItsDashCapAndJoin(): void
    {
        [$page, $builder] = $this->page();

        $builder->drawPolyline(
            [[0.0, 0.0], [10.0, 10.0]],
            new Stroke(Color::black(), 2.0, new Dash([4.0, 2.0], 1.0), LineCap::Round, LineJoin::Bevel),
        );

        $operators = $this->operators($page);

        self::assertStringContainsString("[4 2] 1 d\n", $operators);
        self::assertStringContainsString("1 J\n", $operators);
        self::assertStringContainsString("2 j\n", $operators);
        self::assertStringContainsString("2 w\n", $operators);
        self::assertStringNotContainsString(' M', $operators, 'no mitre limit unless the join is a mitre');
    }

    public function testSolidIsAnEmptyDashArrayRatherThanAnAbsentOne(): void
    {
        [$page, $builder] = $this->page();

        $builder->drawPolyline([[0.0, 0.0], [10.0, 10.0]]);

        // Emitted explicitly so a stroke means the same thing wherever it
        // lands in a page's shared content stream.
        self::assertStringContainsString("[] 0 d\n", $this->operators($page));
    }

    public function testDottedNeedsARoundCapToShowAtAll(): void
    {
        // A zero-length "on" segment under the default butt cap has no
        // area and draws nothing, so the named constructor sets both.
        self::assertSame(LineCap::Round, Stroke::dotted()->cap);
        self::assertSame([0.0, 2.0], Stroke::dotted()->dash->pattern);
    }

    public function testAnAllZeroDashPatternIsRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('pass an empty array for a solid line');

        new Dash([0.0, 0.0]);
    }

    // -- Scoped state ---------------------------------------------------

    public function testTransformedWrapsTheClosureInAMatrixAndPutsItBack(): void
    {
        [$page, $builder] = $this->page();

        $builder->translated(100.0, 50.0, static function (PageBuilder $content): void {
            $content->drawRectangle(0, 0, 10, 10, fill: Color::black());
        });

        $operators = $this->operators($page);

        self::assertStringContainsString("1 0 0 1 100 50 cm\n", $operators);
        self::assertSame(2, substr_count($operators, "q\n"), 'one for the transform, one for the shape');
        self::assertSame(2, substr_count($operators, "Q\n"));
        self::assertStringEndsWith("Q\n", $operators);
    }

    /**
     * An unbalanced "q" would corrupt everything drawn after it, on a page
     * a caller may well go on using after catching -- so the state is
     * restored even when the closure throws.
     */
    public function testAClosureThatThrowsStillRestoresTheGraphicsState(): void
    {
        [$page, $builder] = $this->page();

        try {
            $builder->rotated(30.0, 0.0, 0.0, static function (): void {
                throw new \RuntimeException('drawing failed');
            });
            self::fail('the exception should propagate');
        } catch (\RuntimeException) {
        }

        $operators = $this->operators($page);

        self::assertSame(substr_count($operators, "q\n"), substr_count($operators, "Q\n"));
        self::assertStringEndsWith("Q\n", $operators);
    }

    public function testTransformsNest(): void
    {
        [$page, $builder] = $this->page();

        $builder->translated(10.0, 0.0, static function (PageBuilder $content): void {
            $content->scaled(2.0, 2.0, 0.0, 0.0, static function (PageBuilder $inner): void {
                $inner->drawRectangle(0, 0, 1, 1, fill: Color::black());
            });
        });

        $operators = $this->operators($page);

        self::assertSame(3, substr_count($operators, "q\n"));
        self::assertSame(3, substr_count($operators, "Q\n"));
        self::assertStringContainsString("2 0 0 2 0 0 cm\n", $operators);
    }

    public function testClippingEmitsThePathAsAClipWithoutPaintingIt(): void
    {
        [$page, $builder] = $this->page();

        $builder->clippedToRectangle(0, 0, 100, 100, static function (PageBuilder $content): void {
            $content->drawCircle(50, 50, 80, fill: Color::black());
        });

        $operators = $this->operators($page);

        self::assertStringContainsString("W\nn\n", $operators);
        self::assertSame(1, substr_count($operators, "\nf\n"), 'only the circle is painted');
    }

    public function testClippingToAnArbitraryPath(): void
    {
        [$page, $builder] = $this->page();

        $builder->clippedToPath(
            static fn (PathSink $path) => $path->moveTo(0, 0)->lineTo(50, 0)->lineTo(25, 50)->closePath(),
            static function (PageBuilder $content): void {
                $content->drawRectangle(0, 0, 50, 50, fill: Color::black());
            },
            evenOdd: true,
        );

        self::assertStringContainsString("W*\nn\n", $this->operators($page));
    }

    public function testFadedRegistersAnExtGStateAndReusesItForTheSameAlpha(): void
    {
        [$page, $builder] = $this->page();

        $builder->faded(0.3, static function (PageBuilder $content): void {
            $content->drawRectangle(0, 0, 10, 10, fill: Color::black());
        })->faded(0.3, static function (PageBuilder $content): void {
            $content->drawRectangle(20, 0, 10, 10, fill: Color::black());
        });

        $operators = $this->operators($page);

        self::assertSame(2, substr_count($operators, "/GS1 gs\n"));
        self::assertStringNotContainsString('/GS2', $operators, 'the same alpha should not allocate twice');

        $extGState = $page->resources()->get('ExtGState');
        self::assertInstanceOf(Dictionary::class, $extGState);
        self::assertCount(1, $extGState->entries());
    }

    public function testFadedTakesSeparateFillAndStrokeAlphas(): void
    {
        [, $builder, $document] = $this->page();

        $builder->faded(0.5, static function (PageBuilder $content): void {
            $content->drawRectangle(0, 0, 10, 10, fill: Color::black());
        }, strokeAlpha: 1.0);

        $output = $document->save();

        self::assertStringContainsString('/ca 0.5', $output);
        self::assertStringContainsString('/CA 1', $output);
    }

    public function testAlphaOutsideZeroToOneIsRefused(): void
    {
        [, $builder] = $this->page();

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('fill alpha must be between 0.0 and 1.0');

        $builder->faded(1.5, static fn () => null);
    }

    // -- Paints on the page ---------------------------------------------

    public function testACmykFillGoesIntoTheFileAsFourNumbers(): void
    {
        [$page, $builder] = $this->page();

        $builder->drawRectangle(0, 0, 10, 10, fill: CmykColor::richBlack());

        self::assertStringContainsString("0.6 0.4 0.4 1 k\n", $this->operators($page));
    }

    /**
     * One ink is one plate, so two tints of it share a single
     * /Separation colour space in the page's resources.
     */
    public function testTwoTintsOfOneInkShareOneSeparationResource(): void
    {
        [$page, $builder] = $this->page();

        $brand = SpotColor::named('PANTONE 300 C', CmykColor::fromPercentages(100, 44, 0, 0));

        $builder->drawRectangle(0, 0, 10, 10, fill: $brand)
            ->drawRectangle(20, 0, 10, 10, fill: $brand->withTint(0.15));

        $operators = $this->operators($page);

        self::assertSame(2, substr_count($operators, "/CS1 cs\n"));
        self::assertStringContainsString("1 scn\n", $operators);
        self::assertStringContainsString("0.15 scn\n", $operators);
        self::assertStringNotContainsString('/CS2', $operators);

        $colorSpaces = $page->resources()->get('ColorSpace');
        self::assertInstanceOf(Dictionary::class, $colorSpaces);
        self::assertCount(1, $colorSpaces->entries());
    }

    /**
     * The separation carries its alternate as a linear tint transform, so
     * a reader with no such ink still shows the right colour and a press
     * still gets its own plate.
     */
    public function testASeparationDeclaresItsNameAlternateAndTintTransform(): void
    {
        [, $builder, $document] = $this->page();

        $builder->drawRectangle(0, 0, 10, 10, fill: SpotColor::named(
            'PANTONE 300 C',
            CmykColor::fromPercentages(100, 44, 0, 0),
        ));

        $output = $document->save();

        self::assertStringContainsString('/Separation /PANTONE#20300#20C /DeviceCMYK', $output);
        self::assertStringContainsString('/FunctionType 2', $output);
        self::assertStringContainsString('/C0 [0 0 0 0]', $output);
        self::assertStringContainsString('/C1 [1 0.44 0 0]', $output);
        self::assertStringContainsString('/N 1', $output);
    }

    public function testTextCanBeSetInASpotColor(): void
    {
        [$page, $builder] = $this->page();

        $builder->drawText(
            StandardFont::Helvetica,
            12.0,
            72.0,
            720.0,
            'Brand',
            paint: SpotColor::named('Varnish', CmykColor::black()),
        );

        $operators = $this->operators($page);

        self::assertStringContainsString("/CS1 cs\n", $operators);
        self::assertStringNotContainsString('0 0 0 rg', $operators, 'the paint should win over the triple');
    }

    public function testRotatedTextTurnsAboutItsOwnBaselineOrigin(): void
    {
        [$page, $builder] = $this->page();

        $builder->drawTextRotated(StandardFont::Helvetica, 10.0, 40.0, 300.0, 90.0, 'Revenue');

        $operators = $this->operators($page);

        // Turning about (40, 300) leaves that point fixed, so the text
        // matrix still places the baseline there and the caller does not
        // have to work out where the rotation moved it to.
        self::assertStringContainsString("0 1 -1 0 340 260 cm\n", $operators);
        self::assertStringContainsString("1 0 0 1 40 300 Tm\n", $operators);
    }

    /**
     * The document is kept alongside the page because some assertions
     * are about indirect objects (an ExtGState, a font) which are not
     * part of the page's own bytes.
     *
     * @return array{Page, PageBuilder, Document}
     */
    private function page(): array
    {
        $document = new Document();
        $page = $document->newPage();

        return [$page, new PageBuilder($document, $page), $document];
    }

    private function operators(Page $page): string
    {
        $rendered = $page->contentStreams()[0]->render(true);
        preg_match('/stream\n(.*)\nendstream/s', $rendered, $matches);

        return gzuncompress($matches[1]);
    }


}
