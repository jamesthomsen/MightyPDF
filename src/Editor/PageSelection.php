<?php

declare(strict_types=1);

namespace MightyPDF\Editor;

use MightyPDF\Assembler\Dictionary;
use MightyPDF\Assembler\Document;
use MightyPDF\Assembler\Types\PdfArray;
use MightyPDF\Editor\Form\FormImporter;
use MightyPDF\Exception\InvalidArgumentException;

/**
 * Which pages of an existing PDF to keep, and in what order -- extracting,
 * reordering, deleting and splitting, all of which are the same operation
 * seen from different angles.
 *
 * A selection is a value: every method returns a new one rather than
 * changing this, so a selection can be narrowed twice from the same
 * starting point without the first narrowing leaking into the second.
 * Nothing is read from the source or written anywhere until it is turned
 * into a document.
 *
 * ```php
 * PageSelection::from('report.pdf')->range(0, 4)->toFile('summary.pdf');
 * PageSelection::from('report.pdf')->except(0)->toFile('no-cover.pdf');
 * PageSelection::from('report.pdf')->reversed()->toFile('backwards.pdf');
 *
 * foreach (PageSelection::from('report.pdf')->split() as $n => $page) {
 *     $page->saveToFile("page-$n.pdf");
 * }
 * ```
 *
 * **Page numbers are zero-based**, like every other index in this library
 * and unlike the numbers a reader puts in its toolbar. Getting this wrong
 * is the obvious mistake, so an out-of-range index says so in as many
 * words rather than reporting a bare bound.
 *
 * What comes across is what PageImporter copies: content, resources,
 * geometry, annotations, links, form fields and bookmarks. Bookmarks
 * pointing at pages that were not selected are dropped with them, and
 * links to such a page keep their rectangle and stop doing anything --
 * see PdfMerger, which is the same machinery applied to whole files.
 */
final class PageSelection
{
    private readonly PageTree $tree;

    /**
     * @param list<int> $indexes zero-based, in the order they will appear,
     *        duplicates allowed
     */
    private function __construct(
        private readonly PdfEditor $source,
        private readonly ?array $indexes = null,
    ) {
        $this->tree = new PageTree($source);
    }

    /**
     * @param string $password only for a document that does not open with
     *        an empty one -- see PdfEditor::open().
     */
    public static function from(string $path, string $password = ''): self
    {
        return new self(PdfEditor::open($path, $password));
    }

    public static function fromBytes(string $bytes, string $password = ''): self
    {
        return new self(PdfEditor::fromBytes($bytes, $password));
    }

    /** For a document already opened, or one being edited alongside this. */
    public static function of(PdfEditor $editor): self
    {
        return new self($editor);
    }

    /** How many pages the *source* has, whatever this selection holds. */
    public function sourceCount(): int
    {
        return $this->tree->count();
    }

    /**
     * The selected page indexes, in order.
     *
     * @return list<int>
     */
    public function indexes(): array
    {
        if ($this->indexes !== null) {
            return $this->indexes;
        }

        // range(0, -1) counts *down* and would report a page in a document
        // that has none.
        return $this->tree->count() === 0 ? [] : range(0, $this->tree->count() - 1);
    }

    public function count(): int
    {
        return count($this->indexes());
    }

    /**
     * Exactly these pages, in exactly this order.
     *
     * Repeating an index repeats the page. Note that a repeated page
     * carrying form fields is refused when the document is built (see
     * refuseDuplicatedWidgets): one widget annotation cannot belong to two
     * pages at once, and silently dropping one of the copies' fields would
     * be a form that is subtly not the form it looks like.
     */
    public function pages(int ...$indexes): self
    {
        foreach ($indexes as $index) {
            $this->check($index);
        }

        return new self($this->source, array_values($indexes));
    }

    /**
     * A run of pages, inclusive at both ends.
     *
     * @param int|null $last the end of the document if omitted, so
     *        range(1) is "everything but the cover".
     */
    public function range(int $first, ?int $last = null): self
    {
        $last ??= $this->tree->count() - 1;

        $this->check($first);
        $this->check($last);

        return new self(
            $this->source,
            $first <= $last ? range($first, $last) : array_reverse(range($last, $first)),
        );
    }

    /** Everything currently selected except these, order otherwise kept. */
    public function except(int ...$indexes): self
    {
        foreach ($indexes as $index) {
            $this->check($index);
        }

        $dropped = array_flip($indexes);

        return new self($this->source, array_values(array_filter(
            $this->indexes(),
            static fn (int $index): bool => !isset($dropped[$index]),
        )));
    }

    public function reversed(): self
    {
        return new self($this->source, array_reverse($this->indexes()));
    }

    /**
     * One document per selected page, in order.
     *
     * @return list<Document>
     */
    public function split(): array
    {
        return $this->chunks(1);
    }

    /**
     * The selection cut into documents of at most $size pages each.
     *
     * @return list<Document>
     */
    public function chunks(int $size): array
    {
        if ($size < 1) {
            throw new InvalidArgumentException(
                "A chunk has to hold at least one page, and $size was asked for.",
            );
        }

        $out = [];

        foreach (array_chunk($this->indexes(), $size) as $chunk) {
            $out[] = $this->pages(...$chunk)->toDocument();
        }

        return $out;
    }

    /** The selected pages as a new document, ready to be saved or added to. */
    public function toDocument(): Document
    {
        return self::combine($this);
    }

    public function toBytes(): string
    {
        return $this->toDocument()->save();
    }

    public function toFile(string $path): void
    {
        $this->toDocument()->saveToFile($path);
    }

    /**
     * Several selections, from as many different files as you like, into
     * one document.
     *
     * This is what PdfMerger::merge() is: a merge is every page of every
     * file, which is only the least selective case of the same operation.
     */
    public static function combine(self ...$selections): Document
    {
        $document = new Document();

        // One form and one outline for the whole result, not one per
        // source -- see PdfMerger for why those two are questions about
        // the combination rather than about any file in it.
        $form = new FormImporter($document);
        $outlines = new OutlineImporter($document);

        foreach ($selections as $selection) {
            $selection->importInto($document, $form, $outlines);
        }

        $form->finish();

        return $document;
    }

    private function importInto(Document $document, FormImporter $form, OutlineImporter $outlines): void
    {
        $importer = new PageImporter($this->source, $document, $form);
        $pages = $this->tree->pages();

        $this->refuseDuplicatedWidgets($pages);

        $form->takeFormSettings(
            $this->source->resolveDictionary($this->source->catalog()->get('AcroForm')),
        );

        foreach ($this->indexes() as $index) {
            if (isset($pages[$index])) {
                $importer->import($pages[$index]);
            }
        }

        // After the pages, so that every bookmark that still points
        // somewhere knows where.
        $outlines->take($this->source, $importer->importedPages());
    }

    /**
     * A page selected twice is copied twice, which is fine for content and
     * not fine for form fields: a widget annotation is listed by exactly
     * one page, and importing it a second time would either move it or
     * duplicate an annotation that a form cannot hold twice.
     *
     * Refused rather than silently resolved. The two plausible silent
     * behaviours -- fields on the first copy only, or fields on the last
     * -- both produce a document that looks right and is not, which is the
     * failure this library tries hardest to avoid.
     *
     * @param list<Dictionary> $pages
     */
    private function refuseDuplicatedWidgets(array $pages): void
    {
        $seen = [];

        foreach ($this->indexes() as $index) {
            if (!isset($seen[$index])) {
                $seen[$index] = true;

                continue;
            }

            if (isset($pages[$index]) && $this->hasWidgets($pages[$index])) {
                throw new InvalidArgumentException(sprintf(
                    'Page %d is selected more than once and carries form fields. A field\'s widget '
                    . 'belongs to one page, so it cannot come across twice -- copy the page without its '
                    . 'form (flatten it first with FormFlattener), or select it once.',
                    $index,
                ));
            }
        }
    }

    private function hasWidgets(Dictionary $page): bool
    {
        $annotations = $this->source->resolve($page->get('Annots'));

        foreach ($annotations instanceof PdfArray ? $annotations->items() : [] as $entry) {
            if (FormImporter::isWidget($this->source->resolveDictionary($entry))) {
                return true;
            }
        }

        return false;
    }

    private function check(int $index): void
    {
        $count = $this->tree->count();

        if ($index >= 0 && $index < $count) {
            return;
        }

        if ($count === 0) {
            throw new InvalidArgumentException(
                'This document has no pages, so there is nothing to select.',
            );
        }

        throw new InvalidArgumentException(sprintf(
            'This document has %d page%s, and page indexes are zero-based, so they run 0 to %d. '
            . 'You asked for %d%s.',
            $count,
            $count === 1 ? '' : 's',
            $count - 1,
            $index,
            $index === $count ? sprintf(' -- if you meant page %d as a reader numbers it, that is %d here', $index, $index - 1) : '',
        ));
    }
}
