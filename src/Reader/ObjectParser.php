<?php

declare(strict_types=1);

namespace MightyPDF\Reader;

use MightyPDF\Assembler\Dictionary;
use MightyPDF\Assembler\Stream;
use MightyPDF\Assembler\Types\PdfArray;
use MightyPDF\Assembler\Types\PdfBoolean;
use MightyPDF\Assembler\Types\PdfHexString;
use MightyPDF\Assembler\Types\PdfInteger;
use MightyPDF\Assembler\Types\PdfName;
use MightyPDF\Assembler\Types\PdfNull;
use MightyPDF\Assembler\Types\PdfReal;
use MightyPDF\Assembler\Types\PdfReference;
use MightyPDF\Assembler\Types\PdfString;
use MightyPDF\Assembler\Types\PdfValue;

/**
 * Builds objects (ISO 32000-2 §7.3) out of the Lexer's tokens.
 *
 * Parses straight into the *writer's* types -- Dictionary, Stream,
 * PdfName and friends -- rather than into a parallel reader-side object
 * model. That is the whole point of the design: editing a parsed document
 * is then just Dictionary::set(), and writing it back out is the
 * already-tested render() path, with no translation layer in between to
 * lose information in.
 */
final class ObjectParser
{
    /**
     * Tokens read during look-ahead and not consumed. PDF needs exactly
     * two tokens of look-ahead, and in one place only: "1 0 R" cannot be
     * told apart from the integer 1 followed by unrelated tokens until
     * both the generation number and the "R" have been seen.
     *
     * @var list<Token>
     */
    private array $pushedBack = [];

    /**
     * @param (\Closure(PdfReference): ?int)|null $resolveLength resolves an
     *        indirect /Length. Optional because the parser is usable on
     *        its own (the trailer dictionary, a fragment in a test) and
     *        only streams ever need it; when absent, a stream with an
     *        indirect /Length simply takes the endstream-scanning path.
     */
    public function __construct(
        private readonly Lexer $lexer,
        private readonly ?\Closure $resolveLength = null,
    ) {
    }

    /**
     * Parses the complete indirect object starting at $offset.
     *
     * $offset must point at the object number, i.e. exactly what an xref
     * entry records.
     */
    public function parseIndirectObjectAt(int $offset): IndirectObject
    {
        $this->pushedBack = [];
        $this->lexer->seek($offset);

        $objectId = $this->expectInteger('an object number');
        $generation = $this->expectInteger('a generation number');

        $keyword = $this->nextToken();
        if ($keyword === null || !$keyword->isKeyword('obj')) {
            throw ParseException::at($offset, 'Expected "obj" after the object and generation numbers');
        }

        return new IndirectObject($objectId, $generation, $this->parseValue($objectId, $generation));
    }

    /**
     * Parses a bare object at $offset -- one with no "N G obj" wrapper,
     * as the members of an object stream are stored.
     *
     * Clearing the look-ahead before seeking is the whole reason this
     * exists rather than callers doing seek-then-parseValue: a token
     * pushed back while parsing the *previous* object belongs to a
     * position the cursor is about to leave, and draining it afterwards
     * silently parses the wrong bytes. Only the second and later objects
     * are affected, which is exactly the kind of bug that survives a
     * cursory test.
     */
    public function parseValueAt(int $offset, ?int $objectId = null): PdfValue
    {
        $this->pushedBack = [];
        $this->lexer->seek($offset);

        return $this->parseValue($objectId);
    }

    /**
     * $objectId is stamped onto the value only when it turns out to be a
     * Dictionary or Stream, since those are the only types that can be
     * indirect objects in their own right. Nested dictionaries get null:
     * a page's inline /Resources sub-dictionary has no object number of
     * its own, and giving it one would make it render as a second, bogus
     * top-level object.
     *
     * $generation travels with it so that an object rewritten by an
     * incremental update keeps the identity it already had -- see
     * PdfObject::generation().
     */
    public function parseValue(?int $objectId = null, int $generation = 0): PdfValue
    {
        $token = $this->nextToken();

        if ($token === null) {
            throw ParseException::at($this->lexer->offset(), 'Expected an object, found end of file');
        }

        return $this->parseFromToken($token, $objectId, $generation);
    }

    private function parseFromToken(Token $token, ?int $objectId, int $generation = 0): PdfValue
    {
        return match ($token->type) {
            TokenType::Number => $this->parseNumberOrReference($token),
            TokenType::Name => new PdfName($token->value),
            // raw(), not latin1(): these bytes came out of a file and are
            // whichever encoding that file chose. See PdfString::raw().
            TokenType::LiteralString => PdfString::raw($token->value),
            TokenType::HexString => new PdfHexString($token->value),
            TokenType::ArrayOpen => $this->parseArray(),
            TokenType::DictionaryOpen => $this->parseDictionaryOrStream($objectId, $generation),
            TokenType::Keyword => $this->parseKeyword($token),
            default => throw ParseException::at($token->offset, sprintf('Unexpected token "%s"', $token->value)),
        };
    }

    private function parseNumberOrReference(Token $token): PdfValue
    {
        if (!self::isInteger($token->value)) {
            return new PdfReal((float) $token->value);
        }

        $generation = $this->nextToken();

        if ($generation !== null && $generation->type === TokenType::Number && self::isInteger($generation->value)) {
            $keyword = $this->nextToken();

            if ($keyword !== null && $keyword->isKeyword('R')) {
                return new PdfReference((int) $token->value, (int) $generation->value);
            }

            if ($keyword !== null) {
                $this->pushBack($keyword);
            }
        }

        if ($generation !== null) {
            $this->pushBack($generation);
        }

        return new PdfInteger((int) $token->value);
    }

    private function parseArray(): PdfArray
    {
        $items = [];

        while (true) {
            $token = $this->nextToken();

            if ($token === null) {
                throw ParseException::at($this->lexer->offset(), 'Unterminated array');
            }

            if ($token->type === TokenType::ArrayClose) {
                return new PdfArray(...$items);
            }

            $items[] = $this->parseFromToken($token, null);
        }
    }

    private function parseDictionaryOrStream(?int $objectId, int $generation): Dictionary
    {
        /** @var array<array-key, PdfValue> $entries */
        $entries = [];

        while (true) {
            $token = $this->nextToken();

            if ($token === null) {
                throw ParseException::at($this->lexer->offset(), 'Unterminated dictionary');
            }

            if ($token->type === TokenType::DictionaryClose) {
                break;
            }

            if ($token->type !== TokenType::Name) {
                if ($this->recoverFromStrayDictionaryToken($token)) {
                    break;
                }

                continue;
            }

            $entries[$token->value] = $this->parseValue();
        }

        $next = $this->nextToken();

        if ($next !== null && $next->isKeyword('stream')) {
            // Reposition explicitly instead of trusting the lexer's
            // current offset. Parsing the last dictionary value may have
            // read tokens past the keyword and pushed them back, so the
            // cursor can be anywhere -- and a stream body is found by raw
            // byte offset, where being a few bytes out is silent
            // corruption rather than a parse error.
            $this->pushedBack = [];
            $this->lexer->seek($next->offset + strlen('stream'));

            return $this->parseStream($entries, $objectId, $generation);
        }

        if ($next !== null) {
            $this->pushBack($next);
        }

        return self::fill(new Dictionary($objectId, $generation), $entries);
    }

    /**
     * Handles a token appearing where a dictionary key should be.
     *
     * Skipping it rather than rejecting the object. A dictionary's other
     * entries are still perfectly readable, and throwing away a whole page
     * because one generator emitted a stray value is exactly the wrong
     * trade for a library whose job is editing files it did not write.
     * This repo's own test.pdf is such a file: the 2012 writer emitted
     * "/Resources" with nothing after it, so /Resources swallows the
     * following /MediaBox key and leaves its "[0 0 612 792]" dangling.
     *
     * A composite has to be consumed whole -- skipping only the "[" would
     * resume parsing *inside* the array, where every element then looks
     * like more junk.
     *
     * @return bool true if the dictionary should be treated as ended here
     */
    private function recoverFromStrayDictionaryToken(Token $token): bool
    {
        // An object boundary means the ">>" is missing altogether. Give
        // the token back and let the dictionary end here: continuing to
        // hunt for a key would swallow the rest of the file, turning one
        // damaged object into an unreadable document.
        if ($token->isKeyword('endobj') || $token->isKeyword('stream') || $token->isKeyword('obj')) {
            $this->pushBack($token);

            return true;
        }

        if ($token->type === TokenType::ArrayOpen || $token->type === TokenType::DictionaryOpen) {
            $this->parseFromToken($token, null);
        }

        // Anything else is a single token, already consumed.
        return false;
    }

    /** @param array<array-key, PdfValue> $entries */
    private function parseStream(array $entries, ?int $objectId, int $generation): Stream
    {
        if ($objectId === null) {
            throw ParseException::at($this->lexer->offset(), 'Found a stream that is not an indirect object');
        }

        $this->lexer->skipEndOfLine();
        $data = $this->readStreamData($entries, $this->lexer->offset());

        // compress: false is not an optimisation -- it is correctness. The
        // bytes just read are already in whatever encoded form the file
        // stored them, and the /Filter entry copied across below is the
        // description of that encoding. Re-compressing on write would both
        // corrupt the data and contradict its own /Filter.
        return self::fill(new Stream($objectId, $data, false, $generation), $entries);
    }

    /**
     * @param array<array-key, PdfValue> $entries
     * @param int $dataStart offset of the first byte of stream data
     */
    private function readStreamData(array $entries, int $dataStart): string
    {
        $length = $this->declaredLength($entries['Length'] ?? null);

        if ($length !== null && $length >= 0 && $dataStart + $length <= $this->lexer->length()) {
            $this->lexer->seek($dataStart + $length);
            $token = $this->nextToken();

            if ($token !== null && $token->isKeyword('endstream')) {
                return $this->lexer->slice($dataStart, $length);
            }
        }

        // /Length was missing, indirect-and-unresolvable, or simply wrong;
        // all three are common in the wild, and a /Length that does not
        // land on "endstream" is not to be trusted even when it parses.
        // Scanning is what every robust reader falls back to.
        $end = $this->lexer->find('endstream', $dataStart);

        if ($end === null) {
            throw ParseException::at($dataStart, 'Stream has no "endstream" keyword');
        }

        $this->lexer->seek($end + strlen('endstream'));

        // The EOL before "endstream" is a delimiter, not data (§7.3.8.1).
        return self::stripTrailingEndOfLine($this->lexer->slice($dataStart, $end - $dataStart));
    }

    private function declaredLength(?PdfValue $length): ?int
    {
        if ($length instanceof PdfInteger) {
            return $length->value();
        }

        if ($length instanceof PdfReference && $this->resolveLength !== null) {
            return ($this->resolveLength)($length);
        }

        return null;
    }

    private function parseKeyword(Token $token): PdfValue
    {
        return match ($token->value) {
            'true' => new PdfBoolean(true),
            'false' => new PdfBoolean(false),
            // Kept as an explicit PdfNull rather than dropped. In a
            // dictionary the two are equivalent per spec, but in an array
            // they are not -- /Kids [1 0 R null 3 0 R] has three elements,
            // and silently collapsing it to two would shift every index.
            'null' => new PdfNull(),
            default => throw ParseException::at($token->offset, sprintf('Unexpected keyword "%s"', $token->value)),
        };
    }

    private function expectInteger(string $what): int
    {
        $token = $this->nextToken();

        if ($token === null || $token->type !== TokenType::Number || !self::isInteger($token->value)) {
            throw ParseException::at($token?->offset ?? $this->lexer->offset(), sprintf('Expected %s', $what));
        }

        return (int) $token->value;
    }

    private function nextToken(): ?Token
    {
        if ($this->pushedBack !== []) {
            return array_shift($this->pushedBack);
        }

        return $this->lexer->nextToken();
    }

    private function pushBack(Token $token): void
    {
        array_unshift($this->pushedBack, $token);
    }

    /**
     * @template T of Dictionary
     * @param T $dictionary
     * @param array<array-key, PdfValue> $entries
     * @return T
     */
    private static function fill(Dictionary $dictionary, array $entries): Dictionary
    {
        foreach ($entries as $key => $value) {
            // (string) is load-bearing: PHP silently turns an integer-like
            // array key back into a real int on the way out, and set()
            // declares a string parameter. Same hazard Dictionary::content()
            // documents -- and here the keys come from an arbitrary file,
            // where /1 (a checkbox appearance state named by its numeric
            // export value) is entirely ordinary.
            $dictionary->set((string) $key, $value);
        }

        return $dictionary;
    }

    private static function stripTrailingEndOfLine(string $data): string
    {
        if (str_ends_with($data, "\r\n")) {
            return substr($data, 0, -2);
        }

        if (str_ends_with($data, "\n") || str_ends_with($data, "\r")) {
            return substr($data, 0, -1);
        }

        return $data;
    }

    private static function isInteger(string $lexeme): bool
    {
        return preg_match('/^[+-]?\d+$/', $lexeme) === 1;
    }
}
