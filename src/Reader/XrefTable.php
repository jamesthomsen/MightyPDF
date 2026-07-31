<?php

declare(strict_types=1);

namespace MightyPDF\Reader;

use MightyPDF\Assembler\Dictionary;
use MightyPDF\Assembler\Types\PdfInteger;

/**
 * The document's cross-reference information, read by walking the
 * `startxref` -> section -> /Prev chain backwards through the file
 * (ISO 32000-2 §7.5.4, §7.5.6).
 *
 * A PDF that has been edited is a stack of sections, newest last in the
 * file and first in the chain, each superseding parts of the ones before
 * it. This class flattens that stack into one view where, for every object
 * id, the newest section that mentions it wins. The trailer is flattened
 * the same way: newest-wins per key, but missing keys inherited from
 * older sections, because incremental-update writers vary in how much of
 * the trailer they repeat.
 *
 * Only classic tables are read here. Cross-reference *streams* (PDF 1.5+,
 * and by now the common case) need FlateDecode plus PNG predictors, so
 * they arrive with the filter layer; until then this reports them by name
 * rather than failing obscurely.
 */
final class XrefTable
{
    /**
     * @param array<int, XrefEntry> $entries
     */
    private function __construct(
        private readonly array $entries,
        private readonly Dictionary $trailer,
        private readonly int $startXrefOffset,
    ) {
    }

    public static function read(Lexer $lexer): self
    {
        $startXrefOffset = $offset = self::readStartXref($lexer);

        /** @var array<int, XrefEntry> $entries */
        $entries = [];
        /** @var list<Dictionary> $trailers newest first */
        $trailers = [];
        $visited = [];

        while ($offset !== null) {
            if (isset($visited[$offset])) {
                throw ParseException::at($offset, 'Cross-reference sections form a loop');
            }

            $visited[$offset] = true;

            $section = self::readSection($lexer, $offset);

            foreach ($section['entries'] as $objectId => $entry) {
                // ??= is the whole superseding rule: sections are visited
                // newest first, so the first entry seen for an id is the
                // current one and an older section must never overwrite it.
                $entries[$objectId] ??= $entry;
            }

            $trailers[] = $section['trailer'];

            $previous = $section['trailer']->get('Prev');
            $offset = $previous instanceof PdfInteger ? $previous->value() : null;
        }

        return new self($entries, self::mergeTrailers($trailers), $startXrefOffset);
    }

    /**
     * Where the file's own `startxref` points -- the newest section, and
     * therefore the value an incremental update must record as its /Prev
     * so a reader can walk backwards from the new section into this one.
     */
    public function startXrefOffset(): int
    {
        return $this->startXrefOffset;
    }

    /**
     * The lowest object number an incremental update can safely allocate.
     *
     * Not simply /Size, because /Size is a claim the file makes about
     * itself and files get it wrong -- this repo's own test.pdf says
     * /Size 3 while its table describes four entries, the confirmed 2012
     * off-by-one. Allocating from a /Size that is too small would hand
     * out an id some existing object already uses, and the update would
     * silently overwrite it.
     */
    public function nextFreeObjectId(): int
    {
        $highestEntry = $this->entries === [] ? 0 : max(array_keys($this->entries));

        return max($this->size(), $highestEntry + 1);
    }

    public function entry(int $objectId): ?XrefEntry
    {
        return $this->entries[$objectId] ?? null;
    }

    /** @return array<int, XrefEntry> */
    public function entries(): array
    {
        return $this->entries;
    }

    public function trailer(): Dictionary
    {
        return $this->trailer;
    }

    /**
     * The trailer's /Size: one greater than the highest object number the
     * file uses, and therefore the first object number an incremental
     * update may safely allocate.
     */
    public function size(): int
    {
        $size = $this->trailer->get('Size');

        if (!$size instanceof PdfInteger) {
            // A file with no usable /Size is malformed, but the entries
            // themselves are enough to answer the question.
            return $this->entries === [] ? 1 : max(array_keys($this->entries)) + 1;
        }

        return $size->value();
    }

    private static function readStartXref(Lexer $lexer): int
    {
        $position = strrpos($lexer->bytes(), 'startxref');

        if ($position === false) {
            throw ParseException::at($lexer->length(), 'No "startxref" keyword -- this does not look like a PDF');
        }

        $lexer->seek($position + strlen('startxref'));
        $token = $lexer->nextToken();

        if ($token === null || $token->type !== TokenType::Number) {
            throw ParseException::at($position, 'The "startxref" keyword is not followed by an offset');
        }

        return (int) $token->value;
    }

    /**
     * @return array{entries: array<int, XrefEntry>, trailer: Dictionary}
     */
    private static function readSection(Lexer $lexer, int $offset): array
    {
        if ($offset < 0 || $offset >= $lexer->length()) {
            throw ParseException::at($offset, 'Cross-reference offset points outside the file');
        }

        $lexer->seek($offset);
        $token = $lexer->nextToken();

        if ($token === null || !$token->isKeyword('xref')) {
            throw ParseException::at($offset, 'Expected a cross-reference table -- cross-reference streams are not supported yet');
        }

        $entries = [];

        while (true) {
            $token = $lexer->nextToken();

            if ($token === null) {
                throw ParseException::at($lexer->offset(), 'Cross-reference table ended without a trailer');
            }

            if ($token->isKeyword('trailer')) {
                break;
            }

            if ($token->type !== TokenType::Number) {
                throw ParseException::at($token->offset, sprintf('Expected a subsection header or "trailer", found "%s"', $token->value));
            }

            $firstObjectId = (int) $token->value;
            $count = self::expectNumber($lexer);

            for ($i = 0; $i < $count; ++$i) {
                $entryOffset = self::expectNumber($lexer);
                $generation = self::expectNumber($lexer);
                $type = $lexer->nextToken();

                if ($type === null || $type->type !== TokenType::Keyword) {
                    throw ParseException::at($lexer->offset(), 'Expected "n" or "f" at the end of a cross-reference entry');
                }

                if ($type->value !== 'n') {
                    continue;
                }

                $objectId = $firstObjectId + $i;
                $entries[$objectId] = new XrefEntry($objectId, $generation, $entryOffset);
            }
        }

        $trailer = (new ObjectParser($lexer))->parseValue();

        if (!$trailer instanceof Dictionary) {
            throw ParseException::at($offset, 'The trailer is not a dictionary');
        }

        return ['entries' => $entries, 'trailer' => $trailer];
    }

    /** @param list<Dictionary> $trailers newest first */
    private static function mergeTrailers(array $trailers): Dictionary
    {
        $merged = new Dictionary();

        foreach ($trailers as $trailer) {
            foreach ($trailer->entries() as $key => $value) {
                $key = (string) $key;

                // Both are properties of one section rather than of the
                // document, and this merged dictionary describes neither
                // section. Carrying them would invite a caller to follow a
                // chain that has already been walked.
                if ($key === 'Prev' || $key === 'XRefStm') {
                    continue;
                }

                if ($merged->get($key) === null) {
                    $merged->set($key, $value);
                }
            }
        }

        return $merged;
    }

    private static function expectNumber(Lexer $lexer): int
    {
        $token = $lexer->nextToken();

        if ($token === null || $token->type !== TokenType::Number) {
            throw ParseException::at(
                $token?->offset ?? $lexer->offset(),
                'Expected a number in a cross-reference entry -- the subsection count may be larger than the entries that follow it',
            );
        }

        return (int) $token->value;
    }
}
