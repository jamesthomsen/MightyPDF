<?php

declare(strict_types=1);

namespace MightyPDF\Tests\Assembler\Structure;

use MightyPDF\Assembler\Document;
use MightyPDF\Assembler\Stream;
use MightyPDF\Assembler\Structure\StructureRole;
use MightyPDF\Assembler\Types\PdfArray;
use MightyPDF\Assembler\Types\PdfInteger;
use MightyPDF\Assembler\Types\PdfName;
use MightyPDF\Content\Font\StandardFont;
use MightyPDF\Content\PageBuilder;
use MightyPDF\Editor\PageTree;
use MightyPDF\Editor\PdfEditor;
use MightyPDF\Tests\Support\SavedDocument;
use PHPUnit\Framework\TestCase;

final class StructureTreeTest extends TestCase
{
    public function testAnUntaggedDocumentSaysNothingAboutStructure(): void
    {
        $document = new Document();
        $page = $document->newPage();

        (new PageBuilder($document, $page))->drawText(StandardFont::Helvetica, 12.0, 60, 700, 'Plain');

        $saved = SavedDocument::of($document);

        self::assertNull($saved->at('StructTreeRoot'));
        self::assertNull($saved->at('MarkInfo'));

        // Against the *decoded* content stream. Searching the saved file
        // for "BDC" proved nothing at all: content streams are deflated,
        // so the operator never appears literally and the assertion
        // passed on tagged and untagged documents alike.
        self::assertStringNotContainsString('BDC', $saved->contentOf(0));
    }

    public function testDrawingDoesNotTurnTaggingOn(): void
    {
        // activeStructure() must never create the tree, or an untagged
        // document would become a half-tagged one the moment anything was
        // drawn on it.
        $document = new Document();
        $page = $document->newPage();

        (new PageBuilder($document, $page))->drawText(StandardFont::Helvetica, 12.0, 60, 700, 'Plain');

        self::assertNull($document->activeStructure());
    }

    public function testMarksTheDocumentAndCarriesItsLanguage(): void
    {
        $document = new Document();
        $document->newPage();
        $document->setLanguage('en-GB');
        $document->structure()->document();

        $editor = PdfEditor::fromBytes($document->save());

        self::assertSame('<< /Marked true >>', $editor->catalog()->get('MarkInfo')?->format());
        self::assertSame('(en-GB)', $editor->catalog()->get('Lang')?->format());
        self::assertNotNull($editor->resolveDictionary($editor->catalog()->get('StructTreeRoot')));
    }

    public function testTaggedContentIsWrappedAndAttached(): void
    {
        $document = new Document();
        $page = $document->newPage();
        $content = new PageBuilder($document, $page);

        $heading = $document->structure()->document()->child(StructureRole::Heading1);

        $content->tagged($heading, fn (PageBuilder $b) => $b->drawText(
            StandardFont::HelveticaBold,
            18.0,
            60,
            700,
            'Results',
        ));

        $pdf = $document->save();

        self::assertStringContainsString('/H1 << /MCID 0 >> BDC', self::firstPageContent($pdf));
        self::assertStringContainsString('EMC', self::firstPageContent($pdf));
        self::assertSame(0, SavedDocument::scalar(SavedDocument::fromBytes($pdf)->page(0)->get('StructParents')));
    }

    public function testMarkedContentIsClosedEvenWhenDrawingThrows(): void
    {
        $document = new Document();
        $page = $document->newPage();
        $content = new PageBuilder($document, $page);

        $element = $document->structure()->document()->child(StructureRole::Paragraph);

        try {
            $content->tagged($element, static function (): void {
                throw new \RuntimeException('while drawing');
            });
        } catch (\RuntimeException) {
            // Expected; the point is the state of the content stream.
        }

        // An unmatched BDC makes every mark after it belong to the wrong
        // element, for the rest of the page.
        $content = self::firstPageContent($document->save());

        self::assertSame(1, substr_count($content, 'BDC'));
        self::assertSame(substr_count($content, 'BDC'), substr_count($content, 'EMC'));
    }

    public function testEachPageGetsItsOwnParentTreeKeyAndItsOwnMarkNumbering(): void
    {
        $document = new Document();
        $structure = $document->structure();
        $root = $structure->document();

        foreach ([0, 1] as $index) {
            $page = $document->newPage();
            $content = new PageBuilder($document, $page);

            $content->tagged($root->child(StructureRole::Paragraph), fn (PageBuilder $b) => $b->drawText(
                StandardFont::Helvetica,
                12.0,
                60,
                700,
                "Page $index",
            ));
        }

        $editor = PdfEditor::fromBytes($document->save());
        $tree = new PageTree($editor);

        $keys = [];

        foreach ($tree->pages() as $page) {
            $keys[] = $editor->resolve($page->get('StructParents'))?->format();
        }

        // Unique across the document: two pages sharing a key means the
        // marks of the second are attributed to the elements of the first.
        self::assertSame(['0', '1'], $keys);

        // And MCIDs restart per page, which is what makes that safe.
        $marks = 0;

        foreach ($tree->pages() as $page) {
            $marks += substr_count(self::contentOf($editor, $page), '/MCID 0');
        }

        self::assertSame(2, $marks);
    }

    public function testEveryMarkResolvesThroughTheParentTree(): void
    {
        $pdf = self::taggedDocument();
        $editor = PdfEditor::fromBytes($pdf);

        $root = $editor->resolveDictionary($editor->catalog()->get('StructTreeRoot'));
        $parentTree = $editor->resolveDictionary($root?->get('ParentTree'));
        $nums = $editor->resolve($parentTree?->get('Nums'));

        self::assertInstanceOf(PdfArray::class, $nums);

        $byKey = [];
        $items = $nums->items();

        for ($i = 0; $i + 1 < count($items); $i += 2) {
            $key = $editor->resolve($items[$i]);
            $array = $editor->resolve($items[$i + 1]);

            if ($key instanceof PdfInteger && $array instanceof PdfArray) {
                $byKey[$key->value()] = $array->items();
            }
        }

        $checked = 0;

        foreach ((new PageTree($editor))->pages() as $page) {
            $key = $editor->resolve($page->get('StructParents'));

            self::assertInstanceOf(PdfInteger::class, $key);
            self::assertArrayHasKey($key->value(), $byKey);

            preg_match_all(
                '/\/(\w+)\s*<<\s*\/MCID\s+(\d+)\s*>>\s*BDC/',
                self::contentOf($editor, $page),
                $matches,
                PREG_SET_ORDER,
            );

            foreach ($matches as [, $tag, $mcid]) {
                $element = $editor->resolveDictionary($byKey[$key->value()][(int) $mcid] ?? null);

                self::assertNotNull($element, "MCID $mcid should resolve through the parent tree");

                // The element the parent tree points at must be the one
                // the content stream named, or the reverse lookup is
                // wired to the wrong thing while looking correct.
                $role = $editor->resolve($element->get('S'));

                self::assertInstanceOf(PdfName::class, $role);
                self::assertSame($tag, $role->value());

                ++$checked;
            }
        }

        self::assertGreaterThan(0, $checked);
    }

    public function testAnArtifactIsOutsideTheStructure(): void
    {
        $document = new Document();
        $page = $document->newPage();
        $content = new PageBuilder($document, $page);

        $document->structure()->document();

        $content->artifact(
            fn (PageBuilder $b) => $b->drawText(StandardFont::Helvetica, 9.0, 60, 40, 'Page 1 of 1'),
            'Pagination',
        );

        $content = self::firstPageContent($document->save());

        self::assertStringContainsString('/Artifact << /Type /Pagination >> BDC', $content);
        // No MCID, because it belongs to no element.
        self::assertStringNotContainsString('/MCID', $content);
    }

    public function testAFigureCarriesItsAlternateText(): void
    {
        $document = new Document();
        $page = $document->newPage();
        $content = new PageBuilder($document, $page);

        $figure = $document->structure()->document()->child(StructureRole::Figure);
        $figure->setAlternateText('A bar chart of revenue by region.');

        $content->tagged($figure, fn (PageBuilder $b) => $b->fillRectangle(60, 400, 100, 80));

        $saved = SavedDocument::of($document);
        // The document element's sole child is written directly rather
        // than wrapped in an array -- see the /K 0 test below.
        $element = $saved->dictionary('StructTreeRoot', 'K', 'K');

        self::assertSame('Figure', $element->get('S')?->value());
        self::assertSame('A bar chart of revenue by region.', SavedDocument::scalar($element->get('Alt')));
    }

    public function testRefusesToSkipAHeadingLevel(): void
    {
        $document = new Document();
        $document->newPage();

        $root = $document->structure()->document();
        $root->child(StructureRole::Heading1);

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('would go from H1 to H3');

        $root->child(StructureRole::Heading3);
    }

    public function testAllowsHeadingsToDescendAndReturn(): void
    {
        $document = new Document();
        $document->newPage();

        $root = $document->structure()->document();

        $root->child(StructureRole::Heading1);
        $root->child(StructureRole::Heading2);
        $root->child(StructureRole::Heading3);
        // Back up to a sibling of the H2: legal, and the normal shape of
        // a document with more than one section.
        $root->child(StructureRole::Heading2);

        self::assertSame(2, $document->structure()->lastHeadingLevel());
    }

    public function testAnElementWithOneChildWritesItDirectly(): void
    {
        $document = new Document();
        $page = $document->newPage();
        $content = new PageBuilder($document, $page);

        $paragraph = $document->structure()->document()->child(StructureRole::Paragraph);
        $content->tagged($paragraph, fn (PageBuilder $b) => $b->drawText(
            StandardFont::Helvetica,
            12.0,
            60,
            700,
            'One run',
        ));

        // /K 0 rather than /K [0]: §14.7.2 permits both, and readers that
        // trip over a single-element array are commoner than the reverse.
        // So the type is the claim, not just the number.
        $kids = SavedDocument::of($document)->at('StructTreeRoot', 'K', 'K', 'K');

        self::assertInstanceOf(PdfInteger::class, $kids);
        self::assertSame(0, $kids->value());
    }

    public function testARoleCanBeMappedOntoAStandardOne(): void
    {
        $document = new Document();
        $document->newPage();

        $document->structure()->mapRole('Recital', StructureRole::Paragraph);

        self::assertSame('P', SavedDocument::of($document)->value('StructTreeRoot', 'RoleMap', 'Recital'));
    }

    public function testHeadingLevelsAreKnownToTheRole(): void
    {
        self::assertSame(1, StructureRole::Heading1->headingLevel());
        self::assertSame(6, StructureRole::Heading6->headingLevel());
        self::assertNull(StructureRole::Paragraph->headingLevel());

        self::assertTrue(StructureRole::Heading->isHeading());
        self::assertTrue(StructureRole::Heading3->isHeading());
        self::assertFalse(StructureRole::Paragraph->isHeading());

        self::assertTrue(StructureRole::Section->isGrouping());
        self::assertFalse(StructureRole::Paragraph->isGrouping());
    }

    public function testStampingAnExistingDocumentDrawsUntagged(): void
    {
        // A page being stamped is in a document whose structure tree this
        // library did not build; adding marks without attaching them is
        // worse than not claiming to be tagged at all.
        $document = new Document();
        $document->newPage();

        $editor = PdfEditor::fromBytes($document->save());
        $overlay = new \MightyPDF\Editor\PageOverlay($editor, (new PageTree($editor))->page(0));

        self::assertNull((new \MightyPDF\Editor\EditedDocument($editor))->activeStructure());

        $overlay->content()->drawText(StandardFont::Helvetica, 12.0, 60, 700, 'Stamped');
        $overlay->apply();

        // Decoded, for the same reason as above -- the compressed form
        // of this assertion could never have failed.
        self::assertStringNotContainsString('BDC', SavedDocument::fromBytes($editor->save())->contentOf(0));
    }

    /** The decoded content of the first page -- streams are compressed. */
    private static function firstPageContent(string $pdf): string
    {
        $editor = PdfEditor::fromBytes($pdf);
        $page = (new PageTree($editor))->page(0);

        self::assertNotNull($page);

        return self::contentOf($editor, $page);
    }

    private static function contentOf(PdfEditor $editor, \MightyPDF\Assembler\Dictionary $page): string
    {
        $contents = $editor->resolve($page->get('Contents'));
        $items = $contents instanceof PdfArray ? $contents->items() : [$page->get('Contents')];

        $out = '';

        foreach ($items as $item) {
            $stream = $editor->resolve($item);

            if ($stream instanceof Stream && $editor->store()->canDecode($stream)) {
                $out .= $editor->store()->decodedStream($stream);
            }
        }

        return $out;
    }

    private static function taggedDocument(): string
    {
        $document = new Document();
        $structure = $document->structure();
        $section = $structure->document()->child(StructureRole::Section);

        $page = $document->newPage();
        $content = new PageBuilder($document, $page);

        $content->tagged($section->child(StructureRole::Heading1), fn (PageBuilder $b) => $b->drawText(
            StandardFont::HelveticaBold,
            18.0,
            60,
            700,
            'Results',
        ));

        $content->tagged($section->child(StructureRole::Paragraph), fn (PageBuilder $b) => $b->drawText(
            StandardFont::TimesRoman,
            11.0,
            60,
            670,
            'Revenue rose twelve per cent.',
        ));

        return $document->save();
    }
}
