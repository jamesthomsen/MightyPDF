<?php

declare(strict_types=1);

namespace MightyPDF\Reader;

/**
 * One lexical token, together with the byte offset it started at.
 *
 * $value is the *decoded* content for Name, LiteralString and HexString:
 * `#20` escapes, `\n` escapes, octal escapes and hex digit pairs are all
 * resolved by the lexer, so nothing downstream ever has to decode a second
 * time (double-decoding an escape is a classic source of silent
 * corruption). For Number and Keyword it is the raw lexeme, since those
 * have no escaping and the parser wants to inspect the literal text --
 * "0000000017" and "17" are the same number but not the same token text.
 *
 * $offset is carried on every token because a PDF stream body is located
 * by raw byte offset, not by token position: the parser has to be able to
 * say "the bytes start immediately after *that* `stream` keyword" even
 * when look-ahead has already read past it.
 */
final readonly class Token
{
    public function __construct(
        public TokenType $type,
        public string $value,
        public int $offset,
    ) {
    }

    public function isKeyword(string $keyword): bool
    {
        return $this->type === TokenType::Keyword && $this->value === $keyword;
    }
}
