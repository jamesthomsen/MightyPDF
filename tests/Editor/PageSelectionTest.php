<?php

declare(strict_types=1);

namespace MightyPDF\Tests\Editor;

use MightyPDF\Assembler\Document;
use MightyPDF\Assembler\Stream;
use MightyPDF\Assembler\Types\PdfArray;
use MightyPDF\Content\PageBuilder;
use MightyPDF\Content\Font\StandardFont;
use MightyPDF\Editor\PageSelection;
use MightyPDF\Editor\PageTree;
use MightyPDF\Editor\PdfEditor;
use PHPUnit\Framework\TestCase;

final class PageSelectionTest extends TestCase
{
    public function testSelectsEveryPageByDefault(): void
    {
        $selection = PageSelection::fromBytes(self::numberedPages(4));

        self::assertSame(4, $selection->sourceCount());
        self::assertSame(4, $selection->count());
        self::assertSame([0, 1, 2, 3], $selection->indexes());
    }

    public function testExtractsARangeOfPages(): void
    {
        $pdf = PageSelection::fromBytes(self::numberedPages(5))->range(1, 3)->toBytes();

        self::assertSame(['PAGE-1', 'PAGE-2', 'PAGE-3'], self::pageTexts($pdf));
    }

    public function testARangeWithNoEndRunsToTheLastPage(): void
    {
        $pdf = PageSelection::fromBytes(self::numberedPages(4))->range(2)->toBytes();

        self::assertSame(['PAGE-2', 'PAGE-3'], self::pageTexts($pdf));
    }

    public function testTakesPagesInTheOrderAsked(): void
    {
        $pdf = PageSelection::fromBytes(self::numberedPages(4))->pages(3, 0, 2)->toBytes();

        self::assertSame(['PAGE-3', 'PAGE-0', 'PAGE-2'], self::pageTexts($pdf));
    }

    public function testDeletesPages(): void
    {
        $pdf = PageSelection::fromBytes(self::numberedPages(5))->except(0, 3)->toBytes();

        self::assertSame(['PAGE-1', 'PAGE-2', 'PAGE-4'], self::pageTexts($pdf));
    }

    public function testReversesADocument(): void
    {
        $pdf = PageSelection::fromBytes(self::numberedPages(3))->reversed()->toBytes();

        self::assertSame(['PAGE-2', 'PAGE-1', 'PAGE-0'], self::pageTexts($pdf));
    }

    public function testADescendingRangeIsTakenBackwards(): void
    {
        $pdf = PageSelection::fromBytes(self::numberedPages(4))->range(3, 1)->toBytes();

        self::assertSame(['PAGE-3', 'PAGE-2', 'PAGE-1'], self::pageTexts($pdf));
    }

    public function testASelectionIsAValueAndDoesNotChangeInPlace(): void
    {
        $all = PageSelection::fromBytes(self::numberedPages(4));
        $some = $all->range(0, 1);

        // Narrowing produced a new selection; the one it came from is
        // still every page, so it can be narrowed differently.
        self::assertSame([0, 1], $some->indexes());
        self::assertSame([0, 1, 2, 3], $all->indexes());
        self::assertSame([2, 3], $all->range(2, 3)->indexes());
    }

    public function testSplitsIntoOneDocumentPerPage(): void
    {
        $parts = PageSelection::fromBytes(self::numberedPages(3))->split();

        self::assertCount(3, $parts);
        self::assertSame(['PAGE-0'], self::pageTexts($parts[0]->save()));
        self::assertSame(['PAGE-1'], self::pageTexts($parts[1]->save()));
        self::assertSame(['PAGE-2'], self::pageTexts($parts[2]->save()));
    }

    public function testSplitsIntoChunksWithARemainder(): void
    {
        $parts = PageSelection::fromBytes(self::numberedPages(5))->chunks(2);

        self::assertCount(3, $parts);
        self::assertSame(['PAGE-0', 'PAGE-1'], self::pageTexts($parts[0]->save()));
        self::assertSame(['PAGE-2', 'PAGE-3'], self::pageTexts($parts[1]->save()));
        self::assertSame(['PAGE-4'], self::pageTexts($parts[2]->save()));
    }

    public function testChunksHonourTheSelectionRatherThanTheWholeSource(): void
    {
        $parts = PageSelection::fromBytes(self::numberedPages(6))->except(0)->chunks(2);

        self::assertCount(3, $parts);
        self::assertSame(['PAGE-1', 'PAGE-2'], self::pageTexts($parts[0]->save()));
    }

    public function testRefusesAChunkSizeOfZero(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('at least one page');

        PageSelection::fromBytes(self::numberedPages(2))->chunks(0);
    }

    public function testCombinesSelectionsFromSeveralSources(): void
    {
        $document = PageSelection::combine(
            PageSelection::fromBytes(self::numberedPages(3, 'A'))->pages(2),
            PageSelection::fromBytes(self::numberedPages(3, 'B'))->range(0, 1),
        );

        self::assertSame(['A-2', 'B-0', 'B-1'], self::pageTexts($document->save()));
    }

    public function testAnOutOfRangeIndexSaysHowThePagesAreNumbered(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('This document has 3 pages, and page indexes are zero-based, so they run 0 to 2. You asked for 3');

        PageSelection::fromBytes(self::numberedPages(3))->pages(3);
    }

    public function testTheOffByOneMistakeIsNamed(): void
    {
        try {
            PageSelection::fromBytes(self::numberedPages(3))->pages(3);
            self::fail('Expected an InvalidArgumentException.');
        } catch (\InvalidArgumentException $e) {
            // Asking for exactly $count is overwhelmingly a reader's page
            // number rather than an index, so it is worth saying so.
            self::assertStringContainsString('if you meant page 3 as a reader numbers it, that is 2 here', $e->getMessage());
        }
    }

    public function testANegativeIndexIsRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        PageSelection::fromBytes(self::numberedPages(3))->pages(-1);
    }

    public function testADuplicatedPageIsCopiedTwice(): void
    {
        $pdf = PageSelection::fromBytes(self::numberedPages(2))->pages(0, 1, 0)->toBytes();

        self::assertSame(['PAGE-0', 'PAGE-1', 'PAGE-0'], self::pageTexts($pdf));
    }

    public function testRefusesToDuplicateAPageCarryingFormFields(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('selected more than once and carries form fields');

        PageSelection::fromBytes(self::formPage())->pages(0, 0)->toDocument();
    }

    public function testKeepsTheGeometryOfEachPageItTakes(): void
    {
        $document = new Document();
        $document->newPage(new \MightyPDF\Assembler\Types\PdfRectangle(0, 0, 200, 400));
        $document->newPage(new \MightyPDF\Assembler\Types\PdfRectangle(0, 0, 800, 100));

        $pdf = PageSelection::fromBytes($document->save())->pages(1)->toBytes();

        $tree = new PageTree(PdfEditor::fromBytes($pdf));
        $page = $tree->page(0);

        self::assertNotNull($page);
        self::assertSame(800.0, $tree->mediaBox($page)->width());
        self::assertSame(100.0, $tree->mediaBox($page)->height());
    }

    public function testASelectionOfAnEmptyDocumentSelectsNothing(): void
    {
        $selection = PageSelection::fromBytes((new Document())->save());

        self::assertSame(0, $selection->count());
        self::assertSame([], $selection->indexes());
    }

    /**
     * The text each page draws, one entry per page, so that reordering is
     * checked by what is on the pages rather than by object numbers.
     *
     * @return list<string>
     */
    private static function pageTexts(string $pdf): array
    {
        $editor = PdfEditor::fromBytes($pdf);
        $out = [];

        foreach ((new PageTree($editor))->pages() as $page) {
            $text = '';
            $contents = $editor->resolve($page->get('Contents'));
            $items = $contents instanceof PdfArray ? $contents->items() : [$page->get('Contents')];

            foreach ($items as $item) {
                $stream = $editor->resolve($item);

                if ($stream instanceof Stream && $editor->store()->canDecode($stream)) {
                    $text .= $editor->store()->decodedStream($stream);
                }
            }

            $out[] = preg_match('/\((.*?)\)\s*Tj/', $text, $m) === 1 ? $m[1] : '';
        }

        return $out;
    }

    private static function numberedPages(int $count, string $prefix = 'PAGE'): string
    {
        $document = new Document();

        for ($n = 0; $n < $count; ++$n) {
            $page = $document->newPage();
            (new PageBuilder($document, $page))
                ->drawText(StandardFont::Helvetica, 24.0, 72.0, 700.0, "$prefix-$n");
        }

        return $document->save();
    }

    private static function formPage(): string
    {
        $document = new Document();
        $page = $document->newPage();

        (new PageBuilder($document, $page))
            ->addTextField('name', x: 100, y: 700, width: 200, height: 20);

        return $document->save();
    }
}
