<?php

declare(strict_types=1);

namespace MightyPDF\Tests\Assembler;

use MightyPDF\Assembler\Document;
use MightyPDF\Assembler\PageLabels;
use MightyPDF\Assembler\PageLabelStyle;
use MightyPDF\Assembler\Types\PdfArray;
use MightyPDF\Editor\PdfEditor;
use PHPUnit\Framework\Attributes\DataProvider;
use MightyPDF\Tests\Support\SavedDocument;
use PHPUnit\Framework\TestCase;

final class PageLabelsTest extends TestCase
{
    public function testADocumentWithoutLabelsCarriesNone(): void
    {
        $document = new Document();
        $document->newPage();

        self::assertNull(SavedDocument::of($document)->at('PageLabels'));
    }

    public function testWritesARunPerDeclaredStart(): void
    {
        $document = self::documentWithPages(8);

        $document->pageLabels()
            ->from(0, PageLabelStyle::LowercaseRoman)
            ->from(4, PageLabelStyle::Decimal);

        $labels = self::labelsOf($document->save());

        self::assertSame(
            ['0', '<< /S /r >>', '4', '<< /S /D >>'],
            array_map(static fn ($item): string => $item->format(), $labels->items()),
        );
    }

    public function testRunsAreSortedWhateverOrderTheyWereDeclaredIn(): void
    {
        $document = self::documentWithPages(40);

        // Declared back to front on purpose: §12.4.2's number tree needs
        // ascending keys, and a reader handed them unsorted does not
        // search it -- it silently gets the wrong entry.
        $document->pageLabels()
            ->from(30, PageLabelStyle::Decimal, prefix: 'A-')
            ->from(4, PageLabelStyle::Decimal)
            ->from(0, PageLabelStyle::LowercaseRoman);

        $keys = [];

        foreach (self::labelsOf($document->save())->items() as $index => $item) {
            if ($index % 2 === 0) {
                $keys[] = (int) $item->format();
            }
        }

        self::assertSame([0, 4, 30], $keys);
    }

    public function testAPrefixAndAStartingNumberAreWritten(): void
    {
        $document = self::documentWithPages(3);
        $document->pageLabels()->from(0, PageLabelStyle::Decimal, prefix: 'A-', startAt: 7);

        self::assertSame(
            '<< /S /D /P (A-) /St 7 >>',
            self::labelsOf($document->save())->items()[1]->format(),
        );
    }

    public function testAStartOfOneIsLeftOutBeingTheDefault(): void
    {
        $document = self::documentWithPages(3);
        $document->pageLabels()->from(0, PageLabelStyle::Decimal, startAt: 1);

        self::assertSame('<< /S /D >>', self::labelsOf($document->save())->items()[1]->format());
    }

    public function testAPrefixOnlyRunHasNoStyleAtAll(): void
    {
        $document = self::documentWithPages(2);
        $document->pageLabels()->from(0, PageLabelStyle::None, prefix: 'Cover');

        // /S with an empty name would be a style called "", which is not
        // one -- prefix-only is the absence of /S.
        self::assertSame('<< /P (Cover) >>', self::labelsOf($document->save())->items()[1]->format());
    }

    public function testRedeclaringARunReplacesIt(): void
    {
        $document = self::documentWithPages(4);
        $document->pageLabels()
            ->from(0, PageLabelStyle::Decimal)
            ->from(0, PageLabelStyle::UppercaseRoman);

        $items = self::labelsOf($document->save())->items();

        self::assertCount(2, $items);
        self::assertSame('<< /S /R >>', $items[1]->format());
    }

    public function testRefusesToSaveWithoutSayingWhatPageZeroIsCalled(): void
    {
        $document = self::documentWithPages(6);
        $document->pageLabels()->from(2, PageLabelStyle::Decimal);

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('the earliest run here starts at 2');

        $document->save();
    }

    public function testRefusesANegativePageIndex(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        (new PageLabels(1))->from(-1);
    }

    public function testRefusesToStartNumberingBelowOne(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('numbered from 1 upwards');

        (new PageLabels(1))->from(0, PageLabelStyle::Decimal, startAt: 0);
    }

    /** @return list<array{int, string}> */
    public static function labelCases(): array
    {
        return [
            [0, 'i'],
            [1, 'ii'],
            [3, 'iv'],
            [4, '1'],
            [8, '5'],
            [30, 'A-1'],
            [32, 'A-3'],
        ];
    }

    #[DataProvider('labelCases')]
    public function testReportsTheLabelAReaderWillShow(int $pageIndex, string $expected): void
    {
        $labels = (new PageLabels(1))
            ->from(0, PageLabelStyle::LowercaseRoman)
            ->from(4, PageLabelStyle::Decimal)
            ->from(30, PageLabelStyle::Decimal, prefix: 'A-');

        self::assertSame($expected, $labels->labelFor($pageIndex));
    }

    public function testPagesBeforeTheFirstRunAreCountedAsAReaderWould(): void
    {
        $labels = (new PageLabels(1))->from(5, PageLabelStyle::Decimal);

        self::assertSame('3', $labels->labelFor(2));
    }

    /** @return list<array{int, string}> */
    public static function romanCases(): array
    {
        return [[1, 'I'], [4, 'IV'], [9, 'IX'], [14, 'XIV'], [40, 'XL'], [1990, 'MCMXC'], [3999, 'MMMCMXCIX']];
    }

    #[DataProvider('romanCases')]
    public function testRomanNumerals(int $ordinal, string $expected): void
    {
        self::assertSame($expected, PageLabelStyle::UppercaseRoman->format($ordinal));
    }

    /** @return list<array{int, string}> */
    public static function letterCases(): array
    {
        // Table 159's letters are doubled, not base-26: the 27th label is
        // AA and the 28th is BB, where a spreadsheet would say AB.
        return [[1, 'A'], [26, 'Z'], [27, 'AA'], [28, 'BB'], [52, 'ZZ'], [53, 'AAA']];
    }

    #[DataProvider('letterCases')]
    public function testLettersAreDoubledRatherThanBaseTwentySix(int $ordinal, string $expected): void
    {
        self::assertSame($expected, PageLabelStyle::UppercaseLetters->format($ordinal));
    }

    public function testLowercaseStylesMirrorTheUppercaseOnes(): void
    {
        self::assertSame('xiv', PageLabelStyle::LowercaseRoman->format(14));
        self::assertSame('aa', PageLabelStyle::LowercaseLetters->format(27));
    }

    public function testTheLabelsSurviveASaveAndReopen(): void
    {
        $document = self::documentWithPages(6);
        $document->pageLabels()
            ->from(0, PageLabelStyle::LowercaseRoman)
            ->from(2, PageLabelStyle::Decimal);

        $editor = PdfEditor::fromBytes($document->save());
        $labels = $editor->resolveDictionary($editor->catalog()->get('PageLabels'));

        self::assertNotNull($labels);
        self::assertInstanceOf(PdfArray::class, $editor->resolve($labels->get('Nums')));
    }

    private static function labelsOf(string $pdf): PdfArray
    {
        $editor = PdfEditor::fromBytes($pdf);
        $labels = $editor->resolveDictionary($editor->catalog()->get('PageLabels'));
        $nums = $editor->resolve($labels?->get('Nums'));

        self::assertInstanceOf(PdfArray::class, $nums);

        return $nums;
    }

    private static function documentWithPages(int $count): Document
    {
        $document = new Document();

        for ($n = 0; $n < $count; ++$n) {
            $document->newPage();
        }

        return $document;
    }
}
