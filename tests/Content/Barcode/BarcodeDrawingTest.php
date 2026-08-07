<?php

declare(strict_types=1);

namespace MightyPDF\Tests\Content\Barcode;

use MightyPDF\Assembler\Document;
use MightyPDF\Assembler\Page;
use MightyPDF\Content\Barcode\Code128;
use MightyPDF\Content\Barcode\Ean13;
use MightyPDF\Content\Barcode\QrCode;
use MightyPDF\Content\Barcode\QrEccLevel;
use MightyPDF\Content\Barcode\Symbology;
use MightyPDF\Content\CmykColor;
use MightyPDF\Content\PageBuilder;
use PHPUnit\Framework\TestCase;

/**
 * The step from module widths to rectangles on a page: scaling, the quiet
 * zone, and the fact that a QR code's rows run the other way up from
 * PDF's.
 */
final class BarcodeDrawingTest extends TestCase
{
    public function testTheSymbologyCanBeGivenAsACaseOrAsTheStringItAlwaysTook(): void
    {
        [$byCase, $byString] = [$this->page(), $this->page()];

        $byCase[1]->drawBarcode('12345678', 0, 0, 100, 20, Symbology::Code128);
        $byString[1]->drawBarcode('12345678', 0, 0, 100, 20, 'code128');

        self::assertSame($this->operators($byCase[0]), $this->operators($byString[0]));
    }

    public function testAnUnknownSymbologyNamesTheOnesThatExist(): void
    {
        [, $builder] = $this->page();

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('expected one of: code39, code128, ean13, upca');

        $builder->drawBarcode('x', 0, 0, 100, 20, 'pdf417');
    }

    public function testBarsScaleToFillTheBoxExactly(): void
    {
        [$page, $builder] = $this->page();

        $builder->drawBarcode('5901234123457', 10, 0, 190, 25, Symbology::Ean13);

        [$left, $right] = $this->extent($this->operators($page));

        self::assertEqualsWithDelta(10.0, $left, 1e-6);
        self::assertEqualsWithDelta(200.0, $right, 1e-6);
    }

    /**
     * The clear space comes out of the box rather than being added
     * outside it, so a barcode still occupies the space the layout gave
     * it. EAN-13's is asymmetric -- nine modules left, seven right --
     * which is why this checks both edges rather than assuming symmetry.
     */
    public function testTheQuietZoneIsReservedInsideTheBox(): void
    {
        [$page, $builder] = $this->page();

        $builder->drawBarcode('5901234123457', 0, 0, 111, 25, Symbology::Ean13, quietZone: true);

        [$left, $right] = $this->extent($this->operators($page));

        // 95 modules of symbol plus 9 + 7 of quiet zone in 111pt is one
        // point per module.
        self::assertEqualsWithDelta(9.0, $left, 1e-6);
        self::assertEqualsWithDelta(104.0, $right, 1e-6);
    }

    public function testWithoutTheQuietZoneTheBarsStartAtTheBoxEdge(): void
    {
        [$page, $builder] = $this->page();

        $builder->drawBarcode('5901234123457', 0, 0, 95, 25, Symbology::Ean13);

        [$left] = $this->extent($this->operators($page));

        self::assertEqualsWithDelta(0.0, $left, 1e-6);
    }

    public function testEveryBarIsDrawnAndNoSpaceIs(): void
    {
        [$page, $builder] = $this->page();

        $builder->drawBarcode('Wikipedia', 0, 0, 100, 20, Symbology::Code128);

        $bars = count(array_filter(Code128::elements('Wikipedia'), static fn (array $e): bool => $e['isBar']));

        self::assertSame($bars, substr_count($this->operators($page), ' re'));
    }

    public function testUpcADrawsTheSameSymbolAsItsEanEquivalent(): void
    {
        [$upcPage, $upc] = $this->page();
        [$eanPage, $ean] = $this->page();

        $upc->drawBarcode('03600029145', 0, 0, 95, 25, Symbology::UpcA);
        $ean->drawBarcode('0036000291452', 0, 0, 95, 25, Symbology::Ean13);

        self::assertSame($this->operators($eanPage), $this->operators($upcPage));
    }

    public function testABarcodeCanBePrintedInAProcessColour(): void
    {
        [$page, $builder] = $this->page();

        $builder->drawBarcode('A', 0, 0, 100, 20, paint: CmykColor::black());

        self::assertStringContainsString('0 0 0 1 k', $this->operators($page));
    }

    // -- QR ---------------------------------------------------------------

    public function testAQrCodeDrawsOneSquarePerDarkModule(): void
    {
        [$page, $builder] = $this->page();

        $code = QrCode::encode('MIGHTYPDF');
        $dark = 0;

        foreach ($code->modules() as $row) {
            $dark += count(array_filter($row));
        }

        $builder->drawQrCode('MIGHTYPDF', 0, 0, 100);

        self::assertSame($dark, substr_count($this->operators($page), ' re'));
    }

    /**
     * A QR code's rows are numbered from the top and PDF's y runs up, so
     * the matrix has to be laid out bottom-first. Getting this wrong
     * produces a symbol mirrored about its horizontal axis -- which still
     * looks like a QR code and does not scan.
     */
    public function testTheMatrixIsLaidOutTopRowFirstDespitePdfsAxes(): void
    {
        [$page, $builder] = $this->page();

        // Version 1 with no quiet zone in a 21pt box: one point per
        // module, so a module's coordinates are its indices.
        $builder->drawQrCode('MIGHTYPDF', 0, 0, 21, quietZone: false, minVersion: 1);

        $operators = $this->operators($page);
        $code = QrCode::encode('MIGHTYPDF', minVersion: 1);

        self::assertSame(21, $code->size());

        // The top-left finder pattern's corner module is row 0, column 0,
        // which lands at the *top* of the box: y = 20.
        self::assertStringContainsString('0 20 1 1 re', $operators);

        // And the bottom-left finder's outer corner is row 20, column 0.
        self::assertTrue($code->isDark(0, 20));
        self::assertStringContainsString('0 0 1 1 re', $operators);
    }

    public function testTheQuietZoneInsetsTheModulesOnEverySide(): void
    {
        [$page, $builder] = $this->page();

        // 21 modules plus 4 either side is 29; in a 29pt box that is one
        // point per module again.
        $builder->drawQrCode('MIGHTYPDF', 0, 0, 29, quietZone: true, minVersion: 1);

        $operators = $this->operators($page);

        self::assertStringContainsString('4 24 1 1 re', $operators, 'the top-left module, inset by four');
        self::assertStringNotContainsString('0 28 1 1 re', $operators, 'nothing in the border');
    }

    public function testAHigherErrorCorrectionLevelNeedsAHigherVersionForTheSameData(): void
    {
        $medium = QrCode::encode(str_repeat('MIGHTYPDF ', 12), QrEccLevel::Medium);
        $high = QrCode::encode(str_repeat('MIGHTYPDF ', 12), QrEccLevel::High);

        self::assertGreaterThan($medium->version, $high->version);
    }

    /** @return array{Page, PageBuilder} */
    private function page(): array
    {
        $document = new Document();
        $page = $document->newPage();

        return [$page, new PageBuilder($document, $page)];
    }

    /**
     * The leftmost and rightmost edge of anything drawn.
     *
     * @return array{float, float}
     */
    private function extent(string $operators): array
    {
        preg_match_all('/([\d.-]+) [\d.-]+ ([\d.-]+) [\d.-]+ re/', $operators, $matches);

        $lefts = array_map('floatval', $matches[1]);
        $rights = array_map(
            static fn (string $x, string $w): float => (float) $x + (float) $w,
            $matches[1],
            $matches[2],
        );

        return [min($lefts), max($rights)];
    }

    private function operators(Page $page): string
    {
        $streams = $page->contentStreams();

        if ($streams === []) {
            return '';
        }

        preg_match('/stream\n(.*)\nendstream/s', $streams[0]->render(true), $matches);

        return (string) gzuncompress($matches[1] ?? '');
    }
}
