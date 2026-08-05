<?php

declare(strict_types=1);

namespace MightyPDF\Tests\Editor;

use MightyPDF\Assembler\Destination;
use MightyPDF\Assembler\Dictionary;
use MightyPDF\Assembler\Document;
use MightyPDF\Assembler\Types\PdfArray;
use MightyPDF\Assembler\Types\PdfName;
use MightyPDF\Content\Font\StandardFont;
use MightyPDF\Content\PageBuilder;
use MightyPDF\Editor\PageImporter;
use MightyPDF\Editor\PdfEditor;
use MightyPDF\Editor\PdfMerger;
use PHPUnit\Framework\TestCase;

/**
 * What happens to a link when the page it is on is copied into another
 * document -- which is where a destination stops being a reference and
 * becomes a question about what else made the journey.
 */
final class MergedLinkTest extends TestCase
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
     * A contents page links forwards, so the page it names has not been
     * imported when the link is copied. Resolving it then would deep-copy
     * the page instead -- a duplicate in no page tree, and a link into
     * it.
     */
    public function testALinkForwardsFindsThePageItPointsAt(): void
    {
        $merged = PdfEditor::fromBytes(PdfMerger::merge($this->threePagesLinkingForwards())->save());

        $pageIds = self::pageIds($merged);
        $destination = self::destinationOf(self::linksOn($merged, 0)[0]);

        self::assertSame($pageIds[2], $destination[0]->objectId(), 'the link points at the third page');
    }

    /** The duplicate that would otherwise be dragged in is the visible cost. */
    public function testNoGhostCopyOfTheTargetPageIsMade(): void
    {
        $source = $this->threePagesLinkingForwards();
        $merged = PdfMerger::merge($source)->save();

        // A whole page and its content stream copied twice would show up
        // as a document meaningfully larger than the one it came from.
        self::assertLessThan(filesize($source) * 1.1, strlen($merged));
        self::assertSame(3, count(self::pageIds(PdfEditor::fromBytes($merged))));
    }

    public function testTheViewSurvivesWithTheDestination(): void
    {
        $merged = PdfEditor::fromBytes(PdfMerger::merge($this->threePagesLinkingForwards())->save());
        $destination = self::destinationOf(self::linksOn($merged, 0)[0]);

        self::assertSame('/XYZ', $destination[1]->format());
        self::assertSame('792', $destination[3]->format());
    }

    /** A link out of the document has nothing to resolve and is copied as it stands. */
    public function testAUriLinkIsUnaffected(): void
    {
        $merged = PdfEditor::fromBytes(PdfMerger::merge($this->threePagesLinkingForwards())->save());
        $link = self::linksOn($merged, 0)[1];

        $action = $merged->resolveDictionary($link->get('A'));

        self::assertSame('(https://example.com/)', $action?->get('URI')?->format());
    }

    /**
     * The same destination written as a /GoTo action rather than a
     * /Dest -- both are current, and a document from another tool is as
     * likely to use one as the other.
     */
    public function testAGoToActionIsResolvedLikeADestination(): void
    {
        $merged = PdfEditor::fromBytes(PdfMerger::merge($this->linkingThroughAGoToAction())->save());

        $link = self::linksOn($merged, 0)[0];
        $action = $merged->resolveDictionary($link->get('A'));
        $destination = $merged->resolve($action?->get('D'));

        self::assertInstanceOf(PdfArray::class, $destination);
        self::assertSame(self::pageIds($merged)[2], $destination->items()[0]->objectId());
    }

    /**
     * A link whose page was left behind keeps its rectangle and does
     * nothing, rather than pointing at a page that is not in the
     * document -- and rather than dragging that page in to make itself
     * right.
     */
    public function testALinkToAPageThatWasNotImportedGoesNowhere(): void
    {
        $source = PdfEditor::open($this->threePagesLinkingForwards());
        $target = new Document();
        $importer = new PageImporter($source, $target);

        // The first page only: the page it links to stays behind.
        $importer->import(iterator_to_array($importer->pages())[0]);

        $merged = PdfEditor::fromBytes($target->save());
        $link = self::linksOn($merged, 0)[0];

        self::assertNull($link->get('Dest'));
        self::assertCount(1, self::pageIds($merged), 'the page it pointed at was not dragged in');
    }

    /**
     * Named destinations resolve through name trees that are not
     * imported, and a name meaning one thing in one source may mean
     * another in a document merged from several. Dropped rather than
     * carried across to point at whatever answers to it there.
     */
    public function testANamedDestinationIsDropped(): void
    {
        $editor = PdfEditor::open($this->threePagesLinkingForwards());
        $link = self::linksOn($editor, 0)[0];
        $link->set('Dest', new PdfName('chapter-one'));
        $editor->register($link);

        $path = $this->write($editor->save());
        $merged = PdfEditor::fromBytes(PdfMerger::merge($path)->save());

        self::assertNull(self::linksOn($merged, 0)[0]->get('Dest'));
    }

    private function threePagesLinkingForwards(): string
    {
        $document = new Document();
        $first = $document->newPage();
        $document->newPage();
        $third = $document->newPage();

        $content = new PageBuilder($document, $first);
        $content->drawText(StandardFont::Helvetica, 12.0, 72, 700, 'to the third page');
        $content->addInternalLink(72, 697, 120, 14, Destination::of($third, top: 792));
        $content->addLink(72, 670, 120, 14, 'https://example.com/');

        return $this->write($document->save());
    }

    /** The same, with the destination written as an action instead. */
    private function linkingThroughAGoToAction(): string
    {
        $editor = PdfEditor::open($this->threePagesLinkingForwards());
        $link = self::linksOn($editor, 0)[0];

        $action = new Dictionary();
        $action->set('S', new PdfName('GoTo'));
        $action->set('D', $link->get('Dest'));

        $link->set('Dest', null);
        $link->set('A', $action);
        $editor->register($link);

        return $this->write($editor->save());
    }

    /** @return list<Dictionary> */
    private static function linksOn(PdfEditor $editor, int $pageIndex): array
    {
        $kids = $editor->resolve($editor->resolveDictionary($editor->catalog()->get('Pages'))?->get('Kids'));
        $page = $editor->resolveDictionary($kids?->items()[$pageIndex] ?? null);
        $links = [];

        foreach ($editor->resolve($page?->get('Annots'))?->items() ?? [] as $annotation) {
            $links[] = $editor->resolveDictionary($annotation);
        }

        return $links;
    }

    /** @return list<int> */
    private static function pageIds(PdfEditor $editor): array
    {
        $kids = $editor->resolve($editor->resolveDictionary($editor->catalog()->get('Pages'))?->get('Kids'));

        return array_map(static fn ($kid): int => $kid->objectId(), $kids?->items() ?? []);
    }

    /** @return list<\MightyPDF\Assembler\Types\PdfValue> */
    private static function destinationOf(Dictionary $link): array
    {
        $destination = $link->get('Dest');

        self::assertInstanceOf(PdfArray::class, $destination);

        return $destination->items();
    }

    private function write(string $pdf): string
    {
        $path = tempnam(sys_get_temp_dir(), 'mightypdf-link-') . '.pdf';
        file_put_contents($path, $pdf);
        $this->paths[] = $path;

        return $path;
    }
}
