<?php

declare(strict_types=1);

namespace MightyPDF\Tests\Layout;

use MightyPDF\Assembler\Dictionary;
use MightyPDF\Assembler\Document;
use MightyPDF\Assembler\Stream;
use MightyPDF\Assembler\Structure\StructureRole;
use MightyPDF\Assembler\Types\PdfArray;
use MightyPDF\Content\Color;
use MightyPDF\Content\Font\StandardFont;
use MightyPDF\Editor\PageTree;
use MightyPDF\Editor\PdfEditor;
use MightyPDF\Layout\Border;
use MightyPDF\Layout\Flow;
use MightyPDF\Layout\Style;
use MightyPDF\Tests\Support\SavedDocument;
use PHPUnit\Framework\TestCase;

/**
 * The point of tagging in the layout layer: a Flow already knows a
 * paragraph is a paragraph and a table cell is a table cell, so turning
 * tagging on tags the document, and the caller only says the things the
 * layout cannot infer.
 */
final class FlowTaggingTest extends TestCase
{
    public function testAFlowTagsNothingUnlessAskedTo(): void
    {
        $document = new Document();
        $flow = new Flow($document);

        $flow->paragraph(100.0, 'Plain text');
        $flow->finish();

        self::assertNull(SavedDocument::of($document)->at('StructTreeRoot'));
        self::assertNull($flow->currentElement());
    }

    public function testAParagraphTagsItselfAsOne(): void
    {
        $document = new Document();
        $flow = new Flow($document);
        $flow->tagged();

        $flow->paragraph(100.0, 'Revenue rose twelve per cent.');
        $flow->finish();

        self::assertSame(['Document' => ['P']], self::structureOf($document->save()));
    }

    public function testTheDocumentLanguageComesFromTagged(): void
    {
        $document = new Document();
        (new Flow($document))->tagged('en-GB');

        self::assertSame('en-GB', SavedDocument::of($document)->value('Lang'));
    }

    public function testTagNamesWhatTheLayoutCannotInfer(): void
    {
        $document = new Document();
        $flow = new Flow($document);
        $flow->tagged();

        $flow->tag(StructureRole::Heading1, fn (Flow $f) => $f->paragraph(100.0, 'Results'));
        $flow->paragraph(100.0, 'Body text.');
        $flow->finish();

        self::assertSame(['Document' => ['H1', 'P']], self::structureOf($document->save()));
    }

    public function testEverythingDrawnInsideOneTagBelongsToOneElement(): void
    {
        $document = new Document();
        $flow = new Flow($document);
        $flow->tagged();

        // A heading that takes two calls is still one heading.
        $flow->tag(StructureRole::Heading1, function (Flow $f): void {
            $f->paragraph(100.0, 'Results');
            $f->paragraph(100.0, 'for the year');
        });

        $flow->finish();

        self::assertSame(['Document' => ['H1']], self::structureOf($document->save()));
    }

    public function testInsideGroupsWhatIsDrawnWithinIt(): void
    {
        $document = new Document();
        $flow = new Flow($document);
        $flow->tagged();

        $flow->inside(StructureRole::Section, function (Flow $f): void {
            $f->tag(StructureRole::Heading1, fn (Flow $g) => $g->paragraph(100.0, 'One'));
            $f->paragraph(100.0, 'Body.');
        });

        $flow->paragraph(100.0, 'After the section.');
        $flow->finish();

        self::assertSame(
            ['Document' => [['Sect' => ['H1', 'P']], 'P']],
            self::structureOf($document->save()),
        );
    }

    public function testSectionsNest(): void
    {
        $document = new Document();
        $flow = new Flow($document);
        $flow->tagged();

        $flow->inside(StructureRole::Section, function (Flow $f): void {
            $f->tag(StructureRole::Heading1, fn (Flow $g) => $g->paragraph(100.0, 'One'));

            $f->inside(StructureRole::Section, function (Flow $g): void {
                $g->tag(StructureRole::Heading2, fn (Flow $h) => $h->paragraph(100.0, 'One point one'));
            });
        });

        $flow->finish();

        self::assertSame(
            ['Document' => [['Sect' => ['H1', ['Sect' => ['H2']]]]]],
            self::structureOf($document->save()),
        );
    }

    public function testAnAbandonedSectionDoesNotSwallowTheRestOfTheDocument(): void
    {
        $document = new Document();
        $flow = new Flow($document);
        $flow->tagged();

        try {
            $flow->inside(StructureRole::Section, static function (Flow $f): void {
                $f->paragraph(100.0, 'Before the trouble.');

                throw new \RuntimeException('while laying out');
            });
        } catch (\RuntimeException) {
            // Expected; the point is where the next paragraph lands.
        }

        $flow->paragraph(100.0, 'After it.');
        $flow->finish();

        self::assertSame(
            ['Document' => [['Sect' => ['P']], 'P']],
            self::structureOf($document->save()),
        );
    }

    public function testATableTagsItsRowsAndCells(): void
    {
        $document = new Document();
        $flow = new Flow($document);
        $flow->tagged();

        $flow->table([40.0, 40.0])
            ->header(['Region', 'Revenue'])
            ->row(['UK', '2.4m'])
            ->end();

        $flow->finish();

        // Header cells are /TH, which is what lets a screen reader say
        // which column a value is in.
        self::assertSame(
            ['Document' => [['Table' => [['TR' => ['TH', 'TH']], ['TR' => ['TD', 'TD']]]]]],
            self::structureOf($document->save()),
        );
    }

    public function testATableClosesSoLaterContentIsNotInsideIt(): void
    {
        $document = new Document();
        $flow = new Flow($document);
        $flow->tagged();

        $flow->table([40.0])->row(['One'])->end();
        $flow->paragraph(100.0, 'After the table.');
        $flow->finish();

        $structure = self::structureOf($document->save());

        self::assertSame(
            ['Document' => [['Table' => [['TR' => ['TD']]]], 'P']],
            $structure,
        );
    }

    public function testARunIsOneElementHoweverManyLinesItWraps(): void
    {
        $document = new Document();
        $flow = new Flow($document);
        $flow->tagged();

        // Long enough to wrap several times; still one phrase.
        $flow->write(str_repeat('a phrase that runs on and on ', 12));
        $flow->finish();

        self::assertSame(['Document' => ['Span']], self::structureOf($document->save()));
    }

    public function testPerPageFurnitureIsAnArtifactRatherThanContent(): void
    {
        $document = new Document();
        $flow = new Flow($document);
        $flow->tagged();

        $flow->onEachPage(static function (Flow $f, int $page, int $total): void {
            $f->textAt(15.0, 285.0, "Page $page of $total", new Style(sizePt: 8.0));
        });

        $flow->paragraph(100.0, 'The body.');
        $flow->finish();

        $pdf = $document->save();

        // The footer is furniture: outside the structure entirely, so a
        // reader does not announce it in the middle of a sentence...
        self::assertSame(['Document' => ['P']], self::structureOf($pdf));

        // ...and marked as an artifact rather than simply left untagged,
        // which is what a checker requires.
        $content = self::contentOf($pdf);

        self::assertStringContainsString('/Artifact', $content);
        self::assertSame(substr_count($content, 'BDC') + substr_count($content, 'BMC'), substr_count($content, 'EMC'));
    }

    public function testNoContentIsLeftUntagged(): void
    {
        $document = new Document();
        $flow = new Flow($document);
        $flow->tagged('en-GB');

        $flow->onEachPage(static fn (Flow $f, int $page) => $f->textAt(15.0, 285.0, "Page $page"));

        $flow->inside(StructureRole::Section, function (Flow $f): void {
            $f->tag(StructureRole::Heading1, fn (Flow $g) => $g->paragraph(150.0, 'Report'));
            $f->paragraph(150.0, 'Some prose about the year.');
            $f->table([40.0, 40.0])->header(['A', 'B'])->row(['1', '2'])->end();
            $f->write('And a run.')->newLine(6.0);
        });

        $flow->finish();

        // Every text-showing operator on the page must sit inside either a
        // tagged sequence or an artifact -- anything left over is what an
        // accessibility checker reports first.
        $depth = 0;
        $inside = 0;
        $outside = 0;

        foreach (preg_split('/\R/', self::contentOf($document->save())) ?: [] as $line) {
            if (preg_match('/\b(BDC|BMC)\b/', $line) === 1) {
                ++$depth;

                continue;
            }

            if (preg_match('/\bEMC\b/', $line) === 1) {
                --$depth;

                continue;
            }

            // Every *painting* operator, not just text. Decoration --
            // a cell's fill, a table's rules -- is content too, and
            // content that is neither tagged nor an artifact is the
            // first thing an accessibility checker reports.
            if (preg_match('/\b(Tj|TJ|re|f|f\*|S|s|B|B\*|b|sh)\b/', $line) === 1) {
                $depth > 0 ? ++$inside : ++$outside;
            }
        }

        self::assertGreaterThan(0, $inside);
        self::assertSame(0, $outside, 'every painting operator should be tagged content or an artifact');
        self::assertSame(0, $depth, 'every marked sequence should be closed');
    }

    public function testDrawingNothingLeavesNoElementBehind(): void
    {
        $document = new Document();
        $flow = new Flow($document);
        $flow->tagged();

        // An element wrapping a marked-content sequence with no marks in
        // it is a structure element pointing at nothing: invisible on the
        // page, and reported by every checker.
        $flow->paragraph(100.0, '');
        $flow->write('');
        $flow->paragraph(100.0, 'Something real.');
        $flow->finish();

        self::assertSame(['Document' => ['P']], self::structureOf($document->save()));
    }

    public function testDecorationIsAnArtifactRatherThanUntaggedContent(): void
    {
        $document = new Document();
        $flow = new Flow($document);
        $flow->tagged();

        $flow->cell(80.0, 10.0, 'Boxed', new Style(fill: new Color(0.9, 0.9, 0.95), border: Border::box(0.5)));
        $flow->line(15.0, 60.0, 100.0, 60.0);
        $flow->finish();

        // The fill, the border and the rule say nothing a reader should
        // announce, so they are artifacts -- and the structure is the
        // text alone.
        self::assertSame(['Document' => ['P']], self::structureOf($document->save()));
        self::assertStringContainsString('/Artifact', self::contentOf($document->save()));
    }

    public function testShapesDrawnInsideATagBelongToItRatherThanBeingDecoration(): void
    {
        $document = new Document();
        $flow = new Flow($document);
        $flow->tagged();

        // A chart drawn into a Figure *is* the figure; marking it
        // decoration would leave the figure empty.
        $flow->tag(StructureRole::Figure, static function (Flow $f): void {
            $f->currentElement()?->setAlternateText('A bar chart.');
            $f->rect(20.0, 20.0, 10.0, 30.0, new Color(0.2, 0.35, 0.7));
            $f->rect(35.0, 20.0, 10.0, 20.0, new Color(0.3, 0.5, 0.8));
        });

        $flow->finish();

        $editor = PdfEditor::fromBytes($document->save());
        $root = $editor->resolveDictionary($editor->catalog()->get('StructTreeRoot'));
        $figure = $editor->resolveDictionary(
            $editor->resolve($editor->resolveDictionary($editor->resolve($root?->get('K')))?->get('K')),
        );

        self::assertSame('/Figure', $editor->resolve($figure?->get('S'))?->format());
        self::assertNotNull($figure?->get('K'), 'the figure should own the marks the chart made');
        $saved = SavedDocument::of($document);
        $figure = $saved->dictionary('StructTreeRoot', 'K', 'K');

        self::assertSame('Figure', $figure->get('S')?->value());
        self::assertSame('A bar chart.', SavedDocument::scalar($figure->get('Alt')));
    }

    /**
     * The structure tree as nested arrays, so a test can state the shape
     * it expects rather than walk objects.
     *
     * @return array<string, list<mixed>>
     */
    private static function structureOf(string $pdf): array
    {
        $editor = PdfEditor::fromBytes($pdf);
        $root = $editor->resolveDictionary($editor->catalog()->get('StructTreeRoot'));

        self::assertNotNull($root, 'the document should have a structure tree');

        $children = self::childrenOf($editor, $root);

        self::assertCount(1, $children, 'the tree should have a single /Document at its root');

        return $children[0];
    }

    /** @return list<mixed> */
    private static function childrenOf(PdfEditor $editor, Dictionary $node): array
    {
        $kids = $editor->resolve($node->get('K'));
        $items = $kids instanceof PdfArray ? $kids->items() : ($kids === null ? [] : [$kids]);

        $out = [];

        foreach ($items as $kid) {
            $child = $editor->resolveDictionary($kid);

            // Marked-content ids are leaves, not structure.
            if ($child === null || $child->get('S') === null) {
                continue;
            }

            $role = $editor->resolve($child->get('S'))?->format() ?? '?';
            $role = ltrim($role, '/');

            $grandchildren = self::childrenOf($editor, $child);

            $out[] = $grandchildren === [] ? $role : [$role => $grandchildren];
        }

        return $out;
    }

    private static function contentOf(string $pdf): string
    {
        $editor = PdfEditor::fromBytes($pdf);
        $out = '';

        foreach ((new PageTree($editor))->pages() as $page) {
            $contents = $editor->resolve($page->get('Contents'));
            $items = $contents instanceof PdfArray ? $contents->items() : [$page->get('Contents')];

            foreach ($items as $item) {
                $stream = $editor->resolve($item);

                if ($stream instanceof Stream && $editor->store()->canDecode($stream)) {
                    $out .= $editor->store()->decodedStream($stream) . "\n";
                }
            }
        }

        return $out;
    }
}
