<?php

declare(strict_types=1);

namespace MightyPDF\Tests\Support;

use MightyPDF\Assembler\Dictionary;
use MightyPDF\Assembler\Document;
use MightyPDF\Assembler\Stream;
use MightyPDF\Assembler\Types\PdfArray;
use MightyPDF\Assembler\Types\PdfHexString;
use MightyPDF\Assembler\Types\PdfString;
use MightyPDF\Assembler\Types\PdfValue;
use MightyPDF\Editor\PageTree;
use MightyPDF\Editor\PdfEditor;
use PHPUnit\Framework\Assert;

/**
 * A finished document, parsed back, for tests that want to assert what a
 * reader finds rather than what the bytes happen to spell.
 *
 * The difference is not pedantic. `assertStringContainsString('/Rotate
 * 90', $document->save())` passes when *any* object anywhere in the file
 * contains that text -- a different page, a string, a stream that did
 * not get compressed -- and passes just as happily when the
 * cross-reference table points nowhere near it, because nothing ever
 * asked a reader to find it. Every bug the external checkers turned up
 * in this library was of exactly that shape: output that reads correctly
 * to grep and wrongly to a reader.
 *
 * So: walk to the thing and say what it should be.
 *
 *     $saved = SavedDocument::of($document);
 *     self::assertTrue($saved->value('ViewerPreferences', 'DisplayDocTitle'));
 *     self::assertSame(90, $saved->page(0)->get('Rotate')?->value());
 *
 * A path is followed key by key from the catalog, resolving indirect
 * references at every hop, and an integer segment indexes an array --
 * so ('Names', 'EmbeddedFiles', 'Names', 1) is the first file
 * specification in the name tree. Anything missing fails the test naming
 * the path it got to, which beats a null dereference three lines later.
 *
 * What this does *not* replace: assertions about content-stream
 * operators. A content stream really is a sequence of bytes meaning
 * "0 1 -1 0 cm", there is no object graph inside it, and matching the
 * text is the honest form of that claim. Use contentOf() to get at it.
 */
final class SavedDocument
{
    private function __construct(
        private readonly PdfEditor $editor,
        private readonly string $bytes,
    ) {
    }

    public static function of(Document $document, string $password = ''): self
    {
        return self::fromBytes($document->save(), $password);
    }

    public static function fromBytes(string $bytes, string $password = ''): self
    {
        return new self(PdfEditor::fromBytes($bytes, $password), $bytes);
    }

    public function editor(): PdfEditor
    {
        return $this->editor;
    }

    /** The raw bytes, for the few claims that really are about bytes. */
    public function bytes(): string
    {
        return $this->bytes;
    }

    public function catalog(): Dictionary
    {
        return $this->editor->catalog();
    }

    public function pageCount(): int
    {
        return (new PageTree($this->editor))->count();
    }

    public function page(int $index = 0): Dictionary
    {
        $page = (new PageTree($this->editor))->page($index);

        Assert::assertInstanceOf(Dictionary::class, $page, "there is no page at index $index");

        return $page;
    }

    /**
     * A page's inherited-or-own entry -- /Resources, /MediaBox and
     * /Rotate may all sit on an ancestor node, and a test that reads them
     * off the page dictionary directly is asserting where they were
     * written rather than what they are.
     */
    public function pageEntry(int $index, string $key): ?PdfValue
    {
        return $this->editor->resolve((new PageTree($this->editor))->inherited($this->page($index), $key));
    }

    /** @return list<Dictionary> the page's annotations, in order */
    public function annotations(int $pageIndex = 0): array
    {
        $annotations = $this->editor->resolve($this->page($pageIndex)->get('Annots'));

        if ($annotations === null) {
            return [];
        }

        Assert::assertInstanceOf(PdfArray::class, $annotations, 'a page /Annots should be an array');

        $resolved = [];

        foreach ($annotations->items() as $position => $item) {
            $annotation = $this->editor->resolveDictionary($item);

            Assert::assertInstanceOf(Dictionary::class, $annotation, "annotation $position does not resolve");

            $resolved[] = $annotation;
        }

        return $resolved;
    }

    /**
     * The document's form fields, in the order /Fields lists them.
     *
     * @return list<Dictionary>
     */
    public function fields(): array
    {
        $form = $this->editor->resolveDictionary($this->catalog()->get('AcroForm'));

        Assert::assertInstanceOf(Dictionary::class, $form, 'the document has no /AcroForm');

        $fields = $this->editor->resolve($form->get('Fields'));

        Assert::assertInstanceOf(PdfArray::class, $fields, '/AcroForm has no /Fields array');

        $resolved = [];

        foreach ($fields->items() as $position => $item) {
            $field = $this->editor->resolveDictionary($item);

            Assert::assertInstanceOf(Dictionary::class, $field, "field $position does not resolve");

            $resolved[] = $field;
        }

        return $resolved;
    }

    /**
     * The field with this /T, which is how a form is addressed by
     * everything that fills one in -- and a stronger thing to assert
     * than "a field with this name is somewhere in the bytes", since a
     * field missing from /Fields is a field no reader will find however
     * completely it was written.
     */
    public function field(string $name): Dictionary
    {
        foreach ($this->fields() as $field) {
            if (self::scalar($field->get('T')) === $name) {
                return $field;
            }
        }

        Assert::fail(sprintf(
            'no form field called "%s" -- /Fields lists %s',
            $name,
            implode(', ', array_map(
                static fn (Dictionary $f): string => '"' . self::scalar($f->get('T')) . '"',
                $this->fields(),
            )) ?: 'nothing',
        ));
    }

    /**
     * A page's font resources, keyed by the name the content stream
     * refers to them by.
     *
     * Read through the page's inherited /Resources, because that is
     * where a reader looks: a font that is in the file but not named
     * here cannot be selected by any Tf operator, and "/BaseFont
     * /Helvetica appears somewhere" never noticed the difference.
     *
     * @return array<string, Dictionary>
     */
    public function fonts(int $pageIndex = 0): array
    {
        $resources = $this->editor->resolveDictionary($this->pageEntry($pageIndex, 'Resources'));

        Assert::assertInstanceOf(Dictionary::class, $resources, "page $pageIndex has no /Resources");

        $fonts = $this->editor->resolveDictionary($resources->get('Font'));

        if ($fonts === null) {
            return [];
        }

        $resolved = [];

        foreach ($fonts->entries() as $name => $value) {
            $font = $this->editor->resolveDictionary($value);

            Assert::assertInstanceOf(Dictionary::class, $font, "/Font /$name does not resolve");

            $resolved[(string) $name] = $font;
        }

        return $resolved;
    }

    /**
     * The font a page names, when there is only one worth naming --
     * which is the shape of most tests here.
     */
    public function font(int $pageIndex = 0, ?string $name = null): Dictionary
    {
        $fonts = $this->fonts($pageIndex);

        if ($name !== null) {
            Assert::assertArrayHasKey($name, $fonts, "page $pageIndex names no font /$name");

            return $fonts[$name];
        }

        Assert::assertCount(1, $fonts, 'more than one font on the page -- say which');

        return reset($fonts);
    }

    /** The decoded content of a page, for assertions about operators. */
    public function contentOf(int $pageIndex = 0): string
    {
        $contents = $this->editor->resolve($this->page($pageIndex)->get('Contents'));
        $streams = $contents instanceof PdfArray ? $contents->items() : [$contents];

        $bytes = '';

        foreach ($streams as $item) {
            $stream = $this->editor->resolve($item);

            Assert::assertInstanceOf(Stream::class, $stream, 'a page /Contents entry should be a stream');

            $bytes .= $this->editor->store()->decodedStream($stream);
        }

        return $bytes;
    }

    /**
     * Walks from the catalog. String segments are dictionary keys,
     * integers are array indices, and references are resolved at each
     * hop. Null when the path runs out -- absence is a thing tests
     * assert, so it is a return value rather than a failure.
     */
    public function at(string|int ...$path): ?PdfValue
    {
        return $this->from($this->editor->catalog(), ...$path);
    }

    /**
     * The same walk from somewhere other than the catalog -- a font
     * already in hand, an annotation, a field -- for the paths that are
     * long enough that starting at the top says less than starting at
     * the thing. A CID font's programme is five hops from the page and
     * one from its font.
     */
    public function from(PdfValue $start, string|int ...$path): ?PdfValue
    {
        $current = $start;
        $walked = [];

        foreach ($path as $segment) {
            $walked[] = $segment;

            if (is_int($segment)) {
                if (!$current instanceof PdfArray) {
                    Assert::fail(sprintf('/%s is not an array', implode('/', array_slice($walked, 0, -1))));
                }

                $current = $current->items()[$segment] ?? null;
            } else {
                if (!$current instanceof Dictionary) {
                    Assert::fail(sprintf('/%s is not a dictionary', implode('/', array_slice($walked, 0, -1))));
                }

                $current = $current->get($segment);
            }

            if ($current === null) {
                return null;
            }

            $current = $this->editor->resolve($current);
        }

        return $current;
    }

    /**
     * The scalar at a path -- a name's string, a number, a boolean, a
     * string's text -- which is what most assertions actually want to
     * compare.
     */
    public function value(string|int ...$path): mixed
    {
        return self::scalar($this->at(...$path), '/' . implode('/', $path));
    }

    /**
     * The same normalization for a value already in hand, which is what
     * a test reading an entry off a page or an annotation needs.
     *
     * It exists because the accessor is not the same across the types: a
     * name, a number and a boolean answer value(), while a string
     * answers toUtf8() and would fatal on value(). Which one a given
     * entry holds is a detail of the format, not of the claim being
     * made, so tests should not have to know -- and every one that did
     * would have to be revisited the day an entry legitimately changes
     * type.
     */
    public static function scalar(?PdfValue $value, string $what = 'the value'): mixed
    {
        if ($value === null) {
            return null;
        }

        if ($value instanceof PdfString || $value instanceof PdfHexString) {
            return $value->toUtf8();
        }

        Assert::assertTrue(
            method_exists($value, 'value'),
            sprintf('%s is a %s, which has no scalar value', $what, $value::class),
        );

        return $value->value();
    }

    public function dictionary(string|int ...$path): Dictionary
    {
        $value = $this->at(...$path);

        Assert::assertInstanceOf(Dictionary::class, $value, sprintf('expected a dictionary at /%s', implode('/', $path)));

        return $value;
    }

    public function array(string|int ...$path): PdfArray
    {
        $value = $this->at(...$path);

        Assert::assertInstanceOf(PdfArray::class, $value, sprintf('expected an array at /%s', implode('/', $path)));

        return $value;
    }

    public function stream(string|int ...$path): Stream
    {
        $value = $this->at(...$path);

        Assert::assertInstanceOf(Stream::class, $value, sprintf('expected a stream at /%s', implode('/', $path)));

        return $value;
    }

    /** A stream's decoded bytes, for the streams that are not page content. */
    public function decoded(string|int ...$path): string
    {
        return $this->editor->store()->decodedStream($this->stream(...$path));
    }
}
