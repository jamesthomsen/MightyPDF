<?php

declare(strict_types=1);

namespace MightyPDF\Tests\Editor;

use MightyPDF\Assembler\Destination;
use MightyPDF\Assembler\Dictionary;
use MightyPDF\Assembler\Document;
use MightyPDF\Assembler\Types\PdfArray;
use MightyPDF\Assembler\Types\PdfInteger;
use MightyPDF\Assembler\Types\PdfName;
use MightyPDF\Assembler\Types\PdfString;
use MightyPDF\Content\Font\StandardFont;
use MightyPDF\Content\PageBuilder;
use MightyPDF\Editor\OutlineImporter;
use MightyPDF\Editor\PageImporter;
use MightyPDF\Editor\PdfEditor;
use MightyPDF\Editor\PdfMerger;
use PHPUnit\Framework\TestCase;

/**
 * What becomes of a document's bookmarks when its pages are copied into
 * another document.
 */
final class MergedOutlineTest extends TestCase
{
    /** @var list<string> */
    private array $paths = [];

    protected function tearDown(): void
    {
        foreach ($this->paths as $path) {
            @unlink($path);
        }
    }

    /**
     * Each source's top-level items are appended in the order the files
     * were merged. Wrapping each file's bookmarks under a heading named
     * after it would be adding structure the documents never had.
     */
    public function testEachSourcesBookmarksArriveInOrder(): void
    {
        $merged = self::outlineOf(PdfMerger::merge($this->book('A'), $this->book('B'))->save());

        self::assertSame(
            ['A: chapter one', 'A: chapter two', 'B: chapter one', 'B: chapter two'],
            array_column($merged, 'title'),
        );
        self::assertSame(['A: section 1.1', 'A: section 1.2'], array_column($merged[0]['children'], 'title'));
    }

    public function testBookmarksPointAtThePagesTheyCameWith(): void
    {
        $pdf = PdfMerger::merge($this->book('A'), $this->book('B'))->save();
        $editor = PdfEditor::fromBytes($pdf);
        $pages = self::pageIds($editor);
        $merged = self::outlineOf($pdf);

        // Book A's second chapter is on its second page, which is the
        // merged document's second; book B's is on the fourth.
        self::assertSame($pages[1], $merged[1]['page']);
        self::assertSame($pages[3], $merged[3]['page']);
    }

    /** A negative /Count is how a document says an item was written folded away. */
    public function testAnItemKeepsWhetherItWasOpenOrClosed(): void
    {
        $merged = self::outlineOf(PdfMerger::merge($this->book('A'))->save());

        self::assertSame(2, $merged[0]['count'], 'open, with two sections');
        self::assertSame(-1, $merged[1]['count'], 'closed, hiding one');
    }

    /** The view its author chose comes across, including ones nothing here writes. */
    public function testTheViewSurvives(): void
    {
        $merged = self::outlineOf(PdfMerger::merge($this->book('A'))->save());

        self::assertSame('/Fit', $merged[1]['fit']);
        self::assertSame('/XYZ', $merged[0]['fit']);
    }

    /**
     * A bookmark whose page was left behind is a line that goes nowhere,
     * and a subtree of them is a table of contents for a document that
     * is not here.
     */
    public function testBookmarksForPagesLeftBehindAreDropped(): void
    {
        $merged = self::outlineOf($this->importFirstPageOf($this->book('A')));

        self::assertSame(['A: chapter one'], array_column($merged, 'title'));
        self::assertSame(['A: section 1.1'], array_column($merged[0]['children'], 'title'));
    }

    /**
     * An ancestor kept only because something under it survived loses
     * its own destination and becomes what it already looked like: a
     * heading.
     */
    public function testAnAncestorKeptForItsChildBecomesAHeading(): void
    {
        $document = new Document();
        $first = $document->newPage();
        $second = $document->newPage();
        (new PageBuilder($document, $first))->drawText(StandardFont::Helvetica, 12.0, 72, 700, 'one');

        $document->outline()
            ->add('Points at the second page', Destination::of($second))
            ->add('Points at the first', Destination::of($first));

        $merged = self::outlineOf($this->importFirstPageOf($this->write($document->save())));

        self::assertSame('Points at the second page', $merged[0]['title']);
        self::assertNull($merged[0]['page'], 'its own destination went with the page');
        self::assertSame(['Points at the first'], array_column($merged[0]['children'], 'title'));
    }

    public function testMergingDocumentsWithoutBookmarksLeavesNoOutline(): void
    {
        $plain = new Document();
        (new PageBuilder($plain, $plain->newPage()))->drawText(StandardFont::Helvetica, 12.0, 72, 700, 'plain');

        $merged = PdfMerger::merge($this->write($plain->save()))->save();

        self::assertStringNotContainsString('/Outlines', $merged);
    }

    /** Colour and bold/italic flags are values, and dropping them restyles the document's contents. */
    public function testHowAnItemLooksComesAcross(): void
    {
        $source = $this->styledBookmark();
        $merged = self::outlineOf(PdfMerger::merge($source)->save());

        self::assertSame('[1 0 0]', $merged[0]['colour']);
        self::assertSame(2, $merged[0]['flags']);
    }

    /** A bookmark that opens a link is one, and the URI is a value like any other. */
    public function testABookmarkThatOpensALinkKeepsIt(): void
    {
        $merged = self::outlineOf(PdfMerger::merge($this->bookmarkWithAction(
            static function (Dictionary $action): void {
                $action->set('S', new PdfName('URI'));
                $action->set('URI', PdfString::latin1('https://example.com/'));
            },
        ))->save());

        self::assertSame('(https://example.com/)', $merged[0]['uri']);
    }

    /**
     * An action that reaches outside the document is not carried: a
     * merge is no place to decide that someone else's script should
     * still fire.
     */
    public function testABookmarkThatRunsJavaScriptLosesIt(): void
    {
        $source = $this->bookmarkWithAction(static function (Dictionary $action): void {
            $action->set('S', new PdfName('JavaScript'));
            $action->set('JS', PdfString::latin1('app.alert("hello");'));
        });

        $pdf = PdfMerger::merge($source)->save();
        $merged = self::outlineOf($pdf);

        self::assertStringNotContainsString('app.alert', $pdf, 'the script did not come with it');

        // The item is still there -- it has a page -- but not the action.
        self::assertSame('Chapter', $merged[0]['title']);
        self::assertNull($merged[0]['uri']);
    }

    /**
     * A /Next chain that comes back on itself describes an endless
     * outline. No reader survives one either, so this stops rather than
     * reading it.
     */
    public function testAnOutlineThatLoopsIsNotFollowedForever(): void
    {
        $editor = PdfEditor::fromBytes($this->readFile($this->book('A')));
        $root = $editor->resolveDictionary($editor->catalog()->get('Outlines'));
        $first = $editor->resolveDictionary($root?->get('First'));
        $second = $editor->resolveDictionary($first?->get('Next'));

        // The last item points back at the first.
        $second?->set('Next', $root?->get('First'));
        $editor->register($second);

        $merged = self::outlineOf(PdfMerger::merge($this->write($editor->save()))->save());

        self::assertSame(['A: chapter one', 'A: chapter two'], array_column($merged, 'title'));
    }

    /** A two-page book: two chapters, one folded away, sections under the first. */
    private function book(string $prefix): string
    {
        $document = new Document();
        $first = $document->newPage();
        $second = $document->newPage();

        foreach ([$first, $second] as $index => $page) {
            (new PageBuilder($document, $page))
                ->drawText(StandardFont::Helvetica, 12.0, 72, 700, "$prefix page " . ($index + 1));
        }

        $outline = $document->outline();

        $chapter = $outline->add("$prefix: chapter one", Destination::of($first));
        $chapter->add("$prefix: section 1.1", Destination::of($first, top: 500.0));
        $chapter->add("$prefix: section 1.2", Destination::of($second, top: 400.0));

        $outline->add("$prefix: chapter two", Destination::fitPage($second), open: false)
            ->add("$prefix: buried", Destination::of($second));

        return $this->write($document->save());
    }

    private function styledBookmark(): string
    {
        $document = new Document();
        $page = $document->newPage();
        (new PageBuilder($document, $page))->drawText(StandardFont::Helvetica, 12.0, 72, 700, 'one');

        $item = $document->outline()->add('Chapter', Destination::of($page));
        $item->set('C', new PdfArray(new PdfInteger(1), new PdfInteger(0), new PdfInteger(0)));
        $item->set('F', new PdfInteger(2));

        return $this->write($document->save());
    }

    /** @param \Closure(Dictionary): void $describe */
    private function bookmarkWithAction(\Closure $describe): string
    {
        $document = new Document();
        $page = $document->newPage();
        (new PageBuilder($document, $page))->drawText(StandardFont::Helvetica, 12.0, 72, 700, 'one');

        $item = $document->outline()->add('Chapter', Destination::of($page));
        $action = new Dictionary();
        $describe($action);
        $item->set('A', $action);

        return $this->write($document->save());
    }

    /** Imports only the first page of $path, bookmarks and all. */
    private function importFirstPageOf(string $path): string
    {
        $source = PdfEditor::open($path);
        $target = new Document();
        $importer = new PageImporter($source, $target);

        $importer->import(iterator_to_array($importer->pages())[0]);
        (new OutlineImporter($target))->take($source, $importer->importedPages());

        return $target->save();
    }

    /**
     * The outline as a plain nested array, which is what the assertions
     * above are about -- the tree, not the objects it is spread over.
     *
     * @return list<array{title: string, page: ?int, fit: ?string, count: ?int, colour: ?string, flags: ?int, uri: ?string, children: list<array<string, mixed>>}>
     */
    private static function outlineOf(string $pdf): array
    {
        $editor = PdfEditor::fromBytes($pdf);
        $root = $editor->resolveDictionary($editor->catalog()->get('Outlines'));

        self::assertNotNull($root, 'the merged document has no outline');

        return self::readItems($editor, $editor->resolveDictionary($root->get('First')));
    }

    /** @return list<array<string, mixed>> */
    private static function readItems(PdfEditor $editor, ?Dictionary $item): array
    {
        $items = [];

        while ($item !== null) {
            $destination = $editor->resolve($item->get('Dest'));
            $destination = $destination instanceof PdfArray ? $destination->items() : [];
            $action = $editor->resolveDictionary($item->get('A'));

            $items[] = [
                'title' => $item->get('Title')?->toUtf8(),
                'page' => ($destination[0] ?? null)?->objectId(),
                'fit' => ($destination[1] ?? null)?->format(),
                'count' => $item->get('Count')?->value(),
                'colour' => $item->get('C')?->format(),
                'flags' => $item->get('F')?->value(),
                'uri' => $action?->get('URI')?->format(),
                'children' => self::readItems($editor, $editor->resolveDictionary($item->get('First'))),
            ];

            $item = $editor->resolveDictionary($item->get('Next'));
        }

        return $items;
    }

    /** @return list<int> */
    private static function pageIds(PdfEditor $editor): array
    {
        $kids = $editor->resolve($editor->resolveDictionary($editor->catalog()->get('Pages'))?->get('Kids'));

        return array_map(static fn ($kid): int => $kid->objectId(), $kids?->items() ?? []);
    }

    private function readFile(string $path): string
    {
        return (string) file_get_contents($path);
    }

    private function write(string $pdf): string
    {
        $path = tempnam(sys_get_temp_dir(), 'mightypdf-outline-') . '.pdf';
        file_put_contents($path, $pdf);
        $this->paths[] = $path;

        return $path;
    }
}
