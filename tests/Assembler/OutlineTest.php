<?php

declare(strict_types=1);

namespace MightyPDF\Tests\Assembler;

use MightyPDF\Assembler\Destination;
use MightyPDF\Assembler\Dictionary;
use MightyPDF\Assembler\Document;
use MightyPDF\Editor\PdfEditor;
use MightyPDF\Tests\Support\SavedDocument;
use PHPUnit\Framework\TestCase;

/**
 * The bookmark tree, checked by reading the finished document back --
 * every link in it (/First, /Last, /Next, /Prev, /Parent, /Count)
 * describes a relationship that is only true once the whole tree exists,
 * so the only place it can be judged is the file.
 */
final class OutlineTest extends TestCase
{
    public function testADocumentWithNoBookmarksHasNoOutline(): void
    {
        $document = new Document();
        $document->newPage();

        $saved = SavedDocument::of($document);

        self::assertNull($saved->at('Outlines'));
        self::assertNull($saved->at('PageMode'));
    }

    /**
     * An outline nobody can see is the same as no outline for most of the
     * people who open the file, so asking for one asks readers to show
     * their bookmark panel.
     */
    public function testAnOutlineAsksReadersToShowIt(): void
    {
        $document = new Document();
        $document->outline()->add('Start', Destination::of($document->newPage()));

        $saved = SavedDocument::of($document);

        self::assertSame('UseOutlines', $saved->value('PageMode'));
        self::assertSame('Outlines', $saved->value('Outlines', 'Type'));
    }

    public function testItemsAreLinkedToTheirSiblingsAndParent(): void
    {
        [$editor, $root] = self::outlineOf(self::threeChapters());

        $first = $editor->resolveDictionary($root->get('First'));
        $second = $editor->resolveDictionary($first?->get('Next'));

        self::assertSame('Contents', self::title($first));
        self::assertSame('One', self::title($second));
        self::assertSame($first?->objectId(), self::referencedId($second?->get('Prev')));
        self::assertSame($root->objectId(), self::referencedId($second?->get('Parent')));
        self::assertSame('Two', self::title($editor->resolveDictionary($root->get('Last'))));
    }

    /**
     * /Count is how a reader lays the panel out before it has read the
     * items: positive for an open item, negative for a closed one, and in
     * both cases the number of rows the item is responsible for.
     */
    public function testCountSaysHowManyRowsAnItemIsResponsibleFor(): void
    {
        [$editor, $root] = self::outlineOf(self::threeChapters());

        $one = $editor->resolveDictionary($editor->resolveDictionary($root->get('First'))?->get('Next'));
        $two = $editor->resolveDictionary($root->get('Last'));

        self::assertSame(2, $one?->get('Count')?->value(), 'open, with two children');
        self::assertSame(-1, $two?->get('Count')?->value(), 'closed, hiding one');

        // Three chapters, plus the two sections under the open one. The
        // closed chapter's section is not a row until it is opened.
        self::assertSame(5, $root->get('Count')?->value());
    }

    /** A leaf has no /Count at all -- zero would say "no descendants" twice. */
    public function testALeafHasNoCount(): void
    {
        [$editor, $root] = self::outlineOf(self::threeChapters());

        self::assertNull($editor->resolveDictionary($root->get('First'))?->get('Count'));
    }

    /**
     * An item with no destination is a heading that groups the items
     * under it -- what a document's own structure sometimes wants and
     * PDF allows.
     */
    public function testAnItemNeedNotGoAnywhere(): void
    {
        $document = new Document();
        $page = $document->newPage();

        $heading = $document->outline()->add('Appendices');
        $heading->add('Appendix A', Destination::of($page));

        [$editor, $root] = self::outlineOf($document->save());
        $item = $editor->resolveDictionary($root->get('First'));

        self::assertNull($item?->get('Dest'));
        self::assertSame(1, $item?->get('Count')?->value());
    }

    /** Titles are text strings, so an outline can be read in any language. */
    public function testTitlesKeepCharactersOutsideLatin1(): void
    {
        $document = new Document();
        $document->outline()->add('Κεφάλαιο', Destination::of($document->newPage()));

        [$editor, $root] = self::outlineOf($document->save());

        self::assertSame('Κεφάλαιο', self::title($editor->resolveDictionary($root->get('First'))));
    }

    public function testSavingTwiceProducesTheSameBytes(): void
    {
        $document = new Document();
        $page = $document->newPage();
        $document->outline()->add('One', Destination::of($page))->add('One point one', Destination::of($page));

        self::assertSame($document->save(), $document->save());
    }

    private static function threeChapters(): string
    {
        $document = new Document();
        $contents = $document->newPage();
        $chapter = $document->newPage();

        $outline = $document->outline();
        $outline->add('Contents', Destination::of($contents));

        $one = $outline->add('One', Destination::of($chapter));
        $one->add('One point one', Destination::of($chapter, top: 600));
        $one->add('One point two', Destination::of($chapter, top: 400));

        $outline->add('Two', Destination::fitPage($chapter), open: false)
            ->add('Two point one', Destination::of($chapter));

        return $document->save();
    }

    /** @return array{0: PdfEditor, 1: Dictionary} */
    private static function outlineOf(string $pdf): array
    {
        $editor = PdfEditor::fromBytes($pdf);
        $root = $editor->resolveDictionary($editor->catalog()->get('Outlines'));

        self::assertNotNull($root);

        return [$editor, $root];
    }

    private static function title(?Dictionary $item): ?string
    {
        return $item?->get('Title')?->toUtf8();
    }

    private static function referencedId(mixed $reference): ?int
    {
        return $reference?->objectId();
    }
}
