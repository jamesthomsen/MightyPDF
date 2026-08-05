<?php

declare(strict_types=1);

namespace MightyPDF\Reader;

use MightyPDF\Assembler\Dictionary;
use MightyPDF\Assembler\Stream;
use MightyPDF\Assembler\Types\PdfArray;
use MightyPDF\Assembler\Types\PdfInteger;
use MightyPDF\Assembler\Types\PdfName;
use MightyPDF\Assembler\Types\PdfValue;
use MightyPDF\Reader\Filter\StreamDecoder;

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
 * Both forms of section are read: the classic "xref ... trailer" table,
 * and the cross-reference *stream* that PDF 1.5+ introduced and that most
 * modern generators now emit. They are interchangeable within one chain,
 * so a file may perfectly well be a stream superseding a table.
 *
 * A cross-reference stream has no separate trailer -- the stream's own
 * dictionary carries /Root, /Info, /ID and /Prev. That works out neatly
 * here, since the flattening above only ever wanted a dictionary; but it
 * does mean the entries describing the *stream* (/Type, /W, /Index,
 * /Filter and friends) have to be kept out of the merged result, or they
 * would end up copied into a trailer describing the document.
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
        private readonly bool $usesCrossReferenceStreams,
    ) {
    }

    /**
     * Whether the newest section is a cross-reference stream rather than
     * a classic table -- which is what an incremental update has to match.
     *
     * A classic table whose /Prev points at a cross-reference stream is
     * not a conforming chain, and Ghostscript responds by discarding the
     * cross-reference information and rebuilding it by scanning. Only the
     * newest section matters: it is the one the update's /Prev will point
     * at, and older sections in either format are already chained
     * correctly among themselves.
     */
    public function usesCrossReferenceStreams(): bool
    {
        return $this->usesCrossReferenceStreams;
    }

    public static function read(Lexer $lexer): self
    {
        $startXrefOffset = $offset = self::readStartXref($lexer);

        /** @var array<int, XrefEntry> $entries */
        $entries = [];
        /** @var list<Dictionary> $trailers newest first */
        $trailers = [];
        $visited = [];
        $newestIsStream = false;

        while ($offset !== null) {
            if (isset($visited[$offset])) {
                throw ParseException::at($offset, 'Cross-reference sections form a loop');
            }

            $visited[$offset] = true;

            $section = self::readSection($lexer, $offset);

            if ($trailers === []) {
                $newestIsStream = $section['isStream'];
            }

            foreach ($section['entries'] as $objectId => $entry) {
                // ??= is the whole superseding rule: sections are visited
                // newest first, so the first entry seen for an id is the
                // current one and an older section must never overwrite it.
                $entries[$objectId] ??= $entry;
            }

            $trailers[] = $section['trailer'];

            // A hybrid-reference file (§7.5.8.4) keeps a classic table for
            // old readers and points, via /XRefStm, at a stream holding
            // the entries that table cannot express. Applied after the
            // table's own entries so the table still wins where both
            // speak: it is the section a conforming reader is meant to
            // trust.
            $hybrid = $section['trailer']->get('XRefStm');

            if ($hybrid instanceof PdfInteger && !isset($visited[$hybrid->value()])) {
                $visited[$hybrid->value()] = true;

                foreach (self::readSection($lexer, $hybrid->value())['entries'] as $objectId => $entry) {
                    $entries[$objectId] ??= $entry;
                }
            }

            $previous = $section['trailer']->get('Prev');
            $offset = $previous instanceof PdfInteger ? $previous->value() : null;
        }

        return new self($entries, self::mergeTrailers($trailers), $startXrefOffset, $newestIsStream);
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
     * itself and files get it wrong -- this project's own 2012 writer
     * emitted /Size 3 over a table describing four entries, the confirmed
     * off-by-one (sample in git history at 9508591, test.pdf).
     * Allocating from a /Size that is too small would hand
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
     * @return array{entries: array<int, XrefEntry>, trailer: Dictionary, isStream: bool}
     */
    private static function readSection(Lexer $lexer, int $offset): array
    {
        if ($offset < 0 || $offset >= $lexer->length()) {
            throw ParseException::at($offset, 'Cross-reference offset points outside the file');
        }

        $lexer->seek($offset);
        $token = $lexer->nextToken();

        // The two forms are told apart by what is at the offset: the
        // keyword "xref" for a classic table, an indirect object for a
        // cross-reference stream.
        if ($token === null || !$token->isKeyword('xref')) {
            return self::readStreamSection($lexer, $offset);
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
                $entries[$objectId] = XrefEntry::atOffset($objectId, $generation, $entryOffset);
            }
        }

        $trailer = (new ObjectParser($lexer))->parseValue();

        if (!$trailer instanceof Dictionary) {
            throw ParseException::at($offset, 'The trailer is not a dictionary');
        }

        return ['entries' => $entries, 'trailer' => $trailer, 'isStream' => false];
    }

    /**
     * A cross-reference stream (ISO 32000-2 §7.5.8): the same information
     * as a table, but as binary rows in a compressed stream, and able to
     * say the one thing a table cannot -- that an object lives inside an
     * object stream rather than at a byte offset.
     *
     * @return array{entries: array<int, XrefEntry>, trailer: Dictionary, isStream: bool}
     */
    private static function readStreamSection(Lexer $lexer, int $offset): array
    {
        // No length resolver: looking up an indirect /Length would mean
        // consulting the cross-reference table, which is the very thing
        // being read. In practice these always carry a direct /Length,
        // and if one does not, the parser's endstream scan covers it.
        $stream = (new ObjectParser($lexer))->parseIndirectObjectAt($offset)->value;

        if (!$stream instanceof Stream) {
            throw ParseException::at($offset, 'Expected a cross-reference table or stream');
        }

        $type = $stream->get('Type');

        if (!$type instanceof PdfName || $type->value() !== 'XRef') {
            throw ParseException::at($offset, 'The object at the cross-reference offset is not a /XRef stream');
        }

        $widths = self::integers($stream->get('W'));

        if (count($widths) < 3) {
            throw ParseException::at($offset, 'A cross-reference stream needs a /W array of three field widths');
        }

        $data = (new StreamDecoder())->decode($stream);
        $rowLength = $widths[0] + $widths[1] + $widths[2];

        if ($rowLength <= 0) {
            throw ParseException::at($offset, 'A cross-reference stream has zero-width rows');
        }

        $index = self::integers($stream->get('Index'));

        if ($index === []) {
            // Defaults to the whole range the stream claims to describe.
            $size = $stream->get('Size');
            $index = [0, $size instanceof PdfInteger ? $size->value() : 0];
        }

        $entries = [];
        $position = 0;

        for ($pair = 0; $pair + 1 < count($index); $pair += 2) {
            for ($i = 0; $i < $index[$pair + 1]; ++$i) {
                if ($position + $rowLength > strlen($data)) {
                    // Truncated. Keep what was read rather than losing the
                    // document; ObjectStore's scanner covers the rest.
                    break 2;
                }

                $objectId = $index[$pair] + $i;
                $entry = self::decodeRow($objectId, substr($data, $position, $rowLength), $widths);
                $position += $rowLength;

                if ($entry !== null) {
                    $entries[$objectId] = $entry;
                }
            }
        }

        return ['entries' => $entries, 'trailer' => $stream, 'isStream' => true];
    }

    /**
     * @param list<int> $widths the /W field widths
     */
    private static function decodeRow(int $objectId, string $row, array $widths): ?XrefEntry
    {
        // "If the first element is zero, the type field shall not be
        // present, and shall default to type 1" (§7.5.8.2) -- a saving
        // for a file where nothing is compressed and nothing is free.
        $type = $widths[0] === 0 ? 1 : self::bigEndian(substr($row, 0, $widths[0]));
        $second = self::bigEndian(substr($row, $widths[0], $widths[1]));
        $third = self::bigEndian(substr($row, $widths[0] + $widths[1], $widths[2]));

        return match ($type) {
            1 => XrefEntry::atOffset($objectId, $third, $second),
            2 => XrefEntry::inObjectStream($objectId, $second, $third),
            // Type 0 is a free entry; anything else is a type this
            // version of the spec has not defined, and the spec says to
            // ignore rather than guess at those.
            default => null,
        };
    }

    private static function bigEndian(string $bytes): int
    {
        $value = 0;

        for ($i = 0, $length = strlen($bytes); $i < $length; ++$i) {
            $value = ($value << 8) | ord($bytes[$i]);
        }

        return $value;
    }

    /** @return list<int> */
    private static function integers(?PdfValue $value): array
    {
        if (!$value instanceof PdfArray) {
            return [];
        }

        $out = [];

        foreach ($value->items() as $item) {
            if ($item instanceof PdfInteger) {
                $out[] = $item->value();
            }
        }

        return $out;
    }

    /**
     * Keys that describe a cross-reference *section* rather than the
     * document it belongs to.
     *
     * /Prev and /XRefStm chain the sections together, and this merged
     * dictionary describes no single section, so carrying them would
     * invite a caller to re-walk a chain already walked. The rest exist
     * only because a cross-reference stream's dictionary doubles as its
     * trailer: they describe how to decode that one stream. Letting
     * /Type /XRef or /W travel into a document trailer would be actively
     * harmful -- an incremental update copies the previous trailer
     * forward, so a classic table would end up announcing itself as a
     * cross-reference stream.
     *
     * @var list<string>
     */
    private const array SECTION_ONLY_KEYS = [
        'Prev', 'XRefStm', 'Type', 'W', 'Index', 'Filter', 'DecodeParms', 'Length', 'DL', 'F', 'DP',
    ];

    /** @param list<Dictionary> $trailers newest first */
    private static function mergeTrailers(array $trailers): Dictionary
    {
        $merged = new Dictionary();

        foreach ($trailers as $trailer) {
            foreach ($trailer->entries() as $key => $value) {
                $key = (string) $key;

                if (in_array($key, self::SECTION_ONLY_KEYS, true)) {
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
