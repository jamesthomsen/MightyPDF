<?php

declare(strict_types=1);

namespace MightyPDF\Tests\Assembler;

use MightyPDF\Assembler\Destination;
use MightyPDF\Assembler\Dictionary;
use MightyPDF\Assembler\Document;
use MightyPDF\Content\Font\StandardFont;
use MightyPDF\Content\PageBuilder;
use MightyPDF\Editor\PdfEditor;
use PHPUnit\Framework\TestCase;

final class LinkAnnotationTest extends TestCase
{
    public function testALinkOutOfTheDocumentCarriesAUriAction(): void
    {
        $link = self::linksOf(static function (Document $document, PageBuilder $content): void {
            $content->addLink(72, 700, 120, 14, 'https://example.com/a?b=c');
        })[0];

        self::assertSame('/Link', $link->get('Subtype')?->format());
        self::assertSame('[72 700 192 714]', $link->get('Rect')?->format());
        self::assertSame('(https://example.com/a?b=c)', self::action($link)?->get('URI')?->format());
    }

    public function testALinkInsideTheDocumentNamesAPageAndAView(): void
    {
        $link = self::linksOf(static function (Document $document, PageBuilder $content): void {
            $content->addInternalLink(72, 700, 120, 14, Destination::of($document->pages()[1], top: 500.0));
        })[0];

        self::assertNull($link->get('A'));
        self::assertMatchesRegularExpression('/^\[\d+ 0 R \/XYZ null 500 null\]$/', $link->get('Dest')?->format() ?? '');
    }

    /**
     * The spec's default border is a box drawn around every link, which
     * no document made this century wants and readers draw in a
     * startling black.
     */
    public function testALinkHasNoVisibleBorder(): void
    {
        $link = self::linksOf(static function (Document $document, PageBuilder $content): void {
            $content->addLink(0, 0, 10, 10, 'https://example.com');
        })[0];

        self::assertSame('[0 0 0]', $link->get('Border')?->format());
    }

    /** Without the print flag the link is absent from a printed or flattened copy. */
    public function testALinkIsMarkedForPrinting(): void
    {
        $link = self::linksOf(static function (Document $document, PageBuilder $content): void {
            $content->addLink(0, 0, 10, 10, 'https://example.com');
        })[0];

        self::assertSame(4, $link->get('F')?->value());
    }

    /**
     * A link draws nothing: the text that makes it look like a link is
     * drawn separately, which is what lets one sit over an image or a
     * table cell just as easily.
     */
    public function testALinkDrawsNothingByItself(): void
    {
        $document = new Document();
        $page = $document->newPage();
        (new PageBuilder($document, $page))->addLink(72, 700, 120, 14, 'https://example.com');

        self::assertSame([], $page->contentStreams());
    }

    public function testSeveralLinksOnAPageAreAllListed(): void
    {
        $links = self::linksOf(static function (Document $document, PageBuilder $content): void {
            $content->addLink(0, 0, 10, 10, 'https://one.example')
                ->addLink(0, 20, 10, 10, 'https://two.example')
                ->addInternalLink(0, 40, 10, 10, Destination::fitPage($document->pages()[1]));
        });

        self::assertCount(3, $links);
    }

    /**
     * Both link methods chain like every other drawing call.
     */
    public function testLinkCallsChain(): void
    {
        $document = new Document();
        $content = new PageBuilder($document, $document->newPage());

        self::assertSame($content, $content->addLink(0, 0, 1, 1, 'https://example.com'));
    }

    /**
     * @param \Closure(Document, PageBuilder): void $draw
     * @return list<Dictionary>
     */
    private static function linksOf(\Closure $draw): array
    {
        $document = new Document();
        $first = $document->newPage();
        $document->newPage();

        $content = new PageBuilder($document, $first);
        $content->drawText(StandardFont::Helvetica, 12.0, 72, 700, 'a page with links on it');
        $draw($document, $content);

        $editor = PdfEditor::fromBytes($document->save());
        $page = $editor->resolveDictionary(
            $editor->resolve($editor->resolveDictionary($editor->catalog()->get('Pages'))?->get('Kids'))?->items()[0],
        );

        $links = [];

        foreach ($editor->resolve($page?->get('Annots'))?->items() ?? [] as $annotation) {
            $links[] = $editor->resolveDictionary($annotation);
        }

        return $links;
    }

    private static function action(Dictionary $link): ?Dictionary
    {
        $action = $link->get('A');

        return $action instanceof Dictionary ? $action : null;
    }
}
