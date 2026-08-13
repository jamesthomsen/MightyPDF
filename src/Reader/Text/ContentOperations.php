<?php

declare(strict_types=1);

namespace MightyPDF\Reader\Text;

use MightyPDF\Assembler\Types\PdfArray;
use MightyPDF\Assembler\Types\PdfHexString;
use MightyPDF\Assembler\Types\PdfName;
use MightyPDF\Assembler\Types\PdfReal;
use MightyPDF\Assembler\Types\PdfString;
use MightyPDF\Assembler\Types\PdfValue;
use MightyPDF\Reader\Lexer;
use MightyPDF\Reader\ParseException;
use MightyPDF\Reader\TokenType;

/**
 * A content stream read back as a sequence of operations.
 *
 * A content stream is postfix: operands first, then the operator that
 * consumes them (ISO 32000-2 §7.8.2). That is the only structure there is
 * -- there is no grammar saying how many operands an operator takes, and a
 * reader is expected to know. So this collects operands until it meets a
 * keyword, hands both over, and starts again.
 *
 * Deliberately forgiving. Content streams in the wild contain operators no
 * specification lists, operands of the wrong type, and stray tokens left
 * by generators long since retired. A reader that stops at the first of
 * those extracts nothing from a page that renders perfectly well, so
 * anything unrecognised is passed along to be ignored rather than refused.
 */
final class ContentOperations
{
    /**
     * A run of operands longer than this is not a drawing operation, it is
     * a file trying to make a reader allocate. The longest legitimate one
     * is a TJ array, which is a single operand.
     */
    private const int MAX_OPERANDS = 64;

    private function __construct()
    {
    }

    /**
     * @return iterable<array{string, list<PdfValue>}> operator, operands
     */
    public static function of(string $content): iterable
    {
        $lexer = new Lexer($content);
        $operands = [];

        while (true) {
            try {
                $token = $lexer->nextToken();
            } catch (ParseException) {
                // A malformed token -- an unterminated string, most
                // likely -- ends the stream rather than the extraction.
                // Whatever came before it is still real text.
                return;
            }

            if ($token === null) {
                return;
            }

            if ($token->type !== TokenType::Keyword) {
                if (count($operands) < self::MAX_OPERANDS) {
                    $operands[] = self::value($lexer, $token->type, $token->value);
                }

                continue;
            }

            // Inline images put raw, unescaped binary between ID and EI,
            // which is not tokenizable and will otherwise be read as
            // thousands of nonsense operators.
            if ($token->value === 'BI') {
                self::skipInlineImage($lexer);
                $operands = [];

                continue;
            }

            yield [$token->value, $operands];

            $operands = [];
        }
    }

    /** @return PdfValue */
    private static function value(Lexer $lexer, TokenType $type, string $raw): PdfValue
    {
        return match ($type) {
            TokenType::Number => new PdfReal((float) $raw),
            TokenType::Name => new PdfName($raw),
            TokenType::LiteralString => PdfString::raw($raw),
            TokenType::HexString => new PdfHexString($raw),
            TokenType::ArrayOpen => self::array($lexer),
            // Dictionaries appear as operands to BDC and DP, which nothing
            // here acts on; a placeholder keeps the operand count right
            // without parsing something that will be discarded.
            default => new PdfName(''),
        };
    }

    private static function array(Lexer $lexer): PdfArray
    {
        $items = [];

        for ($n = 0; $n < self::MAX_OPERANDS; ++$n) {
            $token = $lexer->nextToken();

            if ($token === null || $token->type === TokenType::ArrayClose) {
                break;
            }

            $items[] = self::value($lexer, $token->type, $token->value);
        }

        return new PdfArray(...$items);
    }

    /**
     * Steps over an inline image's binary data.
     *
     * "EI" has to be found in bytes that may contain those two characters
     * by coincidence, so it only counts when it is delimited on both
     * sides -- which is what §8.9.7 says a reader should look for, and is
     * still a heuristic rather than a guarantee.
     */
    private static function skipInlineImage(Lexer $lexer): void
    {
        $bytes = $lexer->bytes();
        $from = $lexer->offset();

        $id = $lexer->find('ID', $from);

        if ($id === null) {
            $lexer->seek($lexer->length());

            return;
        }

        for ($at = $id + 2; $at < strlen($bytes); ++$at) {
            $end = strpos($bytes, 'EI', $at);

            if ($end === false) {
                break;
            }

            $before = $bytes[$end - 1] ?? ' ';
            $after = $bytes[$end + 2] ?? ' ';

            if (self::isWhitespace($before) && (self::isWhitespace($after) || $end + 2 >= strlen($bytes))) {
                $lexer->seek($end + 2);

                return;
            }

            $at = $end + 1;
        }

        $lexer->seek($lexer->length());
    }

    private static function isWhitespace(string $byte): bool
    {
        return $byte === ' ' || $byte === "\n" || $byte === "\r" || $byte === "\t" || $byte === "\0" || $byte === "\f";
    }
}
