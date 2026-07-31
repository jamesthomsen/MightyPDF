<?php

declare(strict_types=1);

namespace MightyPDF\Tests\Reader;

use MightyPDF\Reader\Lexer;
use MightyPDF\Reader\ParseException;
use MightyPDF\Reader\Token;
use MightyPDF\Reader\TokenType;
use PHPUnit\Framework\TestCase;

final class LexerTest extends TestCase
{
    public function testLexesTheBasicDelimiters(): void
    {
        self::assertSame(
            [
                TokenType::DictionaryOpen,
                TokenType::Name,
                TokenType::ArrayOpen,
                TokenType::Number,
                TokenType::ArrayClose,
                TokenType::DictionaryClose,
            ],
            array_map(
                static fn (Token $token): TokenType => $token->type,
                self::tokens('<< /Kids [ 1 ] >>'),
            ),
        );
    }

    public function testDecodesHexEscapesInNames(): void
    {
        self::assertSame('Name With#Escapes', self::firstValue('/Name#20With#23Escapes'));
    }

    public function testKeepsAMalformedNameEscapeLiteral(): void
    {
        // "#" not followed by two hex digits is malformed. A name is a
        // lookup key, so a slightly wrong key still beats discarding the
        // dictionary it belongs to.
        self::assertSame('A#ZZ', self::firstValue('/A#ZZ'));
    }

    public function testDecodesLiteralStringEscapes(): void
    {
        self::assertSame("a(b)c\\d\ne\tf", self::firstValue('(a\\(b\\)c\\\\d\\ne\\tf)'));
    }

    public function testAnUnknownEscapeStandsForItself(): void
    {
        self::assertSame('q', self::firstValue('(\\q)'));
    }

    public function testDecodesOctalEscapes(): void
    {
        self::assertSame("A\x08\x01", self::firstValue('(\\101\\10\\1)'));
    }

    public function testOctalEscapesAboveAByteWrapAround(): void
    {
        // "\400" overflows a byte; the spec says to drop the high-order
        // overflow rather than reject the string.
        self::assertSame("\x00", self::firstValue('(\\400)'));
    }

    public function testBackslashBeforeNewlineIsALineContinuation(): void
    {
        self::assertSame('ab', self::firstValue("(a\\\nb)"));
        self::assertSame('ab', self::firstValue("(a\\\r\nb)"));
    }

    public function testEndOfLineInsideAStringAlwaysMeansLineFeed(): void
    {
        // So a string's value does not depend on the line endings of the
        // machine that wrote the file.
        self::assertSame("a\nb\nc\nd", self::firstValue("(a\r\nb\rc\nd)"));
    }

    public function testTracksNestedParentheses(): void
    {
        self::assertSame('a(b)c', self::firstValue('(a(b)c)'));
    }

    public function testLexesHexStrings(): void
    {
        self::assertSame('Hello', self::firstValue('<48656C6C6F>'));
    }

    public function testHexStringIgnoresWhitespaceBetweenDigits(): void
    {
        self::assertSame('Hello', self::firstValue("<48 65\n6C\t6C 6F>"));
    }

    public function testHexStringPadsAnOddDigitCount(): void
    {
        // A trailing lone digit is defined to pad with zero, not to fail.
        self::assertSame("\xAB\xC0", self::firstValue('<ABC>'));
    }

    public function testSkipsComments(): void
    {
        $tokens = self::tokens("%PDF-1.7\n% another comment\n/Real");

        self::assertCount(1, $tokens);
        self::assertSame('Real', $tokens[0]->value);
    }

    public function testLexesNumbersPermissively(): void
    {
        // The lexer classifies; the parser decides what the text means.
        self::assertSame(
            ['0', '+17', '-.002', '34.5'],
            array_map(static fn (Token $token): string => $token->value, self::tokens('0 +17 -.002 34.5')),
        );
    }

    public function testKeywordsStopAtDelimiters(): void
    {
        $tokens = self::tokens('endobj<<');

        self::assertSame(TokenType::Keyword, $tokens[0]->type);
        self::assertSame('endobj', $tokens[0]->value);
        self::assertSame(TokenType::DictionaryOpen, $tokens[1]->type);
    }

    public function testTokensCarryTheirStartingOffset(): void
    {
        // Stream bodies are located by raw byte offset, so a token has to
        // know where it began even after look-ahead has moved the cursor.
        $tokens = self::tokens('  /A /B');

        self::assertSame(2, $tokens[0]->offset);
        self::assertSame(5, $tokens[1]->offset);
    }

    public function testReturnsNullAtEndOfFile(): void
    {
        self::assertNull((new Lexer("  \n% trailing comment"))->nextToken());
    }

    public function testRejectsAnUnterminatedLiteralString(): void
    {
        $this->expectException(ParseException::class);

        self::tokens('(unterminated');
    }

    public function testRejectsAnUnterminatedHexString(): void
    {
        $this->expectException(ParseException::class);

        self::tokens('<ABCD');
    }

    public function testRejectsAStrayClosingParenthesis(): void
    {
        $this->expectException(ParseException::class);

        self::tokens(') ');
    }

    public function testSeekingOutsideTheFileThrows(): void
    {
        $this->expectException(ParseException::class);

        (new Lexer('short'))->seek(500);
    }

    public function testSkipEndOfLineHandlesEveryLineEndingStyle(): void
    {
        foreach (["\r\n" => 2, "\n" => 1, "\r" => 1] as $eol => $expected) {
            $lexer = new Lexer($eol . 'data');
            $lexer->skipEndOfLine();

            self::assertSame($expected, $lexer->offset(), sprintf('for %s', bin2hex($eol)));
        }
    }

    /** @return list<Token> */
    private static function tokens(string $bytes): array
    {
        $lexer = new Lexer($bytes);
        $tokens = [];

        while (($token = $lexer->nextToken()) !== null) {
            $tokens[] = $token;
        }

        return $tokens;
    }

    private static function firstValue(string $bytes): string
    {
        $token = (new Lexer($bytes))->nextToken();

        self::assertNotNull($token);

        return $token->value;
    }
}
