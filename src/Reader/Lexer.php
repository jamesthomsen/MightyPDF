<?php

declare(strict_types=1);

namespace MightyPDF\Reader;

/**
 * Turns raw PDF bytes into tokens (ISO 32000-2 §7.2).
 *
 * Operates on bytes throughout -- never on characters. PDF files are
 * binary: a literal string can hold arbitrary bytes including NUL, and a
 * name can hold any byte at all via its `#XX` escape. Anything that
 * reached for a multibyte string function here would corrupt those, which
 * is the reader-side twin of the writer's 2012 escaping bugs (see PdfName
 * and PdfString, whose old escape tables searched for PHP source text
 * like '\x00' instead of the actual byte).
 *
 * The lexer is a cursor, not a stream: callers seek it freely, because
 * that is how a PDF is actually read -- the xref says "object 12 is at
 * byte 4831", and a stream body is a raw slice at an offset rather than
 * anything token-shaped.
 */
final class Lexer
{
    /** ISO 32000-2 Table 1: the six white-space bytes. */
    private const string WHITESPACE = "\x00\x09\x0A\x0C\x0D\x20";

    /** ISO 32000-2 Table 2: the delimiter bytes. */
    private const string DELIMITERS = "()<>[]{}/%";

    private const string NUMERIC = '0123456789+-.';

    private int $offset = 0;

    public function __construct(private readonly string $bytes)
    {
    }

    public function bytes(): string
    {
        return $this->bytes;
    }

    public function length(): int
    {
        return strlen($this->bytes);
    }

    public function offset(): int
    {
        return $this->offset;
    }

    public function seek(int $offset): void
    {
        if ($offset < 0 || $offset > strlen($this->bytes)) {
            throw ParseException::at($offset, 'Tried to seek outside the file');
        }

        $this->offset = $offset;
    }

    /** Raw bytes, bypassing tokenization entirely -- for stream bodies. */
    public function slice(int $offset, int $length): string
    {
        return substr($this->bytes, $offset, max(0, $length));
    }

    public function find(string $needle, int $from): ?int
    {
        $position = strpos($this->bytes, $needle, $from);

        return $position === false ? null : $position;
    }

    /**
     * Steps over the end-of-line marker that follows a `stream` keyword.
     *
     * The spec allows only CRLF or a bare LF here, never a bare CR -- but
     * files with a bare CR exist, and treating that CR as the first byte
     * of the stream data corrupts the whole stream. Tolerating it costs
     * nothing: a CR alone is never a legitimate first data byte in a
     * position where the spec forbids it.
     */
    public function skipEndOfLine(): void
    {
        if (($this->bytes[$this->offset] ?? '') === "\r") {
            ++$this->offset;
        }

        if (($this->bytes[$this->offset] ?? '') === "\n") {
            ++$this->offset;
        }
    }

    public function skipWhitespaceAndComments(): void
    {
        $length = strlen($this->bytes);

        while ($this->offset < $length) {
            $byte = $this->bytes[$this->offset];

            if (strpos(self::WHITESPACE, $byte) !== false) {
                ++$this->offset;
                continue;
            }

            if ($byte === '%') {
                while ($this->offset < $length && $this->bytes[$this->offset] !== "\r" && $this->bytes[$this->offset] !== "\n") {
                    ++$this->offset;
                }
                continue;
            }

            return;
        }
    }

    /** The next token, or null at end of file. */
    public function nextToken(): ?Token
    {
        $this->skipWhitespaceAndComments();

        if ($this->offset >= strlen($this->bytes)) {
            return null;
        }

        $start = $this->offset;
        $byte = $this->bytes[$start];

        return match (true) {
            $byte === '/' => new Token(TokenType::Name, $this->readName(), $start),
            $byte === '(' => new Token(TokenType::LiteralString, $this->readLiteralString(), $start),
            $byte === '<' => $this->readAngleBracket($start),
            $byte === '>' => $this->readDictionaryClose($start),
            $byte === '[' => $this->readSingleByte(TokenType::ArrayOpen, $start),
            $byte === ']' => $this->readSingleByte(TokenType::ArrayClose, $start),
            $byte === '{' => $this->readSingleByte(TokenType::ProcedureOpen, $start),
            $byte === '}' => $this->readSingleByte(TokenType::ProcedureClose, $start),
            $byte === ')' => throw ParseException::at($start, 'Found ")" outside a literal string'),
            strpos(self::NUMERIC, $byte) !== false => new Token(TokenType::Number, $this->readNumber(), $start),
            default => new Token(TokenType::Keyword, $this->readKeyword(), $start),
        };
    }

    private function readSingleByte(TokenType $type, int $start): Token
    {
        ++$this->offset;

        return new Token($type, $this->bytes[$start], $start);
    }

    private function readAngleBracket(int $start): Token
    {
        if (($this->bytes[$start + 1] ?? '') === '<') {
            $this->offset += 2;

            return new Token(TokenType::DictionaryOpen, '<<', $start);
        }

        return new Token(TokenType::HexString, $this->readHexString(), $start);
    }

    private function readDictionaryClose(int $start): Token
    {
        if (($this->bytes[$start + 1] ?? '') !== '>') {
            throw ParseException::at($start, 'Found ">" that does not close a dictionary');
        }

        $this->offset += 2;

        return new Token(TokenType::DictionaryClose, '>>', $start);
    }

    /**
     * Numbers are lexed permissively -- every byte that could plausibly be
     * part of one is consumed, and the parser decides what it means. A
     * strict scanner would reject shapes like "34.5-12" that broken
     * generators emit, and rejecting the whole file over a malformed
     * number in some annotation nobody reads is the wrong trade.
     */
    private function readNumber(): string
    {
        $start = $this->offset;
        $length = strlen($this->bytes);

        while ($this->offset < $length && strpos(self::NUMERIC, $this->bytes[$this->offset]) !== false) {
            ++$this->offset;
        }

        return substr($this->bytes, $start, $this->offset - $start);
    }

    private function readKeyword(): string
    {
        $start = $this->offset;
        $length = strlen($this->bytes);

        while ($this->offset < $length && $this->isRegular($this->bytes[$this->offset])) {
            ++$this->offset;
        }

        if ($this->offset === $start) {
            throw ParseException::at($start, sprintf('Unexpected byte 0x%02X', ord($this->bytes[$start])));
        }

        return substr($this->bytes, $start, $this->offset - $start);
    }

    /** Decodes `#XX` escapes as it goes (ISO 32000-2 §7.3.5). */
    private function readName(): string
    {
        ++$this->offset;
        $length = strlen($this->bytes);
        $out = '';

        while ($this->offset < $length) {
            $byte = $this->bytes[$this->offset];

            if (!$this->isRegular($byte)) {
                break;
            }

            ++$this->offset;

            $isEscape = $byte === '#'
                && $this->offset + 1 < $length
                && ctype_xdigit($this->bytes[$this->offset])
                && ctype_xdigit($this->bytes[$this->offset + 1]);

            if ($isEscape) {
                $out .= chr((int) hexdec(substr($this->bytes, $this->offset, 2)));
                $this->offset += 2;
                continue;
            }

            // A "#" not followed by two hex digits is malformed. Emitting
            // it literally keeps the name usable; a name is a lookup key,
            // and a slightly wrong key beats no dictionary at all.
            $out .= $byte;
        }

        return $out;
    }

    /** ISO 32000-2 §7.3.4.2. */
    private function readLiteralString(): string
    {
        $start = $this->offset;
        ++$this->offset;
        $length = strlen($this->bytes);
        $depth = 1;
        $out = '';

        while ($this->offset < $length) {
            $byte = $this->bytes[$this->offset++];

            if ($byte === '\\') {
                $out .= $this->readEscapeSequence();
                continue;
            }

            if ($byte === '(') {
                ++$depth;
                $out .= $byte;
                continue;
            }

            if ($byte === ')') {
                if (--$depth === 0) {
                    return $out;
                }

                $out .= $byte;
                continue;
            }

            // An unescaped end-of-line marker inside a string always means
            // a single LF, whatever bytes the file actually used, so that
            // a string's value does not depend on the line endings of the
            // machine that wrote it.
            if ($byte === "\r") {
                if (($this->bytes[$this->offset] ?? '') === "\n") {
                    ++$this->offset;
                }

                $out .= "\n";
                continue;
            }

            $out .= $byte;
        }

        throw ParseException::at($start, 'Unterminated literal string');
    }

    private function readEscapeSequence(): string
    {
        $length = strlen($this->bytes);

        if ($this->offset >= $length) {
            return '';
        }

        $byte = $this->bytes[$this->offset++];

        // A backslash immediately before an end-of-line marker is a line
        // continuation: it contributes nothing at all to the string.
        if ($byte === "\r") {
            if (($this->bytes[$this->offset] ?? '') === "\n") {
                ++$this->offset;
            }

            return '';
        }

        if ($byte === "\n") {
            return '';
        }

        if ($byte >= '0' && $byte <= '7') {
            $octal = $byte;

            while (strlen($octal) < 3 && $this->offset < $length && $this->bytes[$this->offset] >= '0' && $this->bytes[$this->offset] <= '7') {
                $octal .= $this->bytes[$this->offset++];
            }

            // "\400" and above overflow a byte; the spec says to ignore
            // the high-order overflow rather than treat it as an error.
            return chr(((int) octdec($octal)) & 0xFF);
        }

        return match ($byte) {
            'n' => "\n",
            'r' => "\r",
            't' => "\t",
            'b' => "\x08",
            'f' => "\x0C",
            // Everything else stands for itself: "\(", "\)" and "\\" of
            // course, but also a meaningless escape like "\q", which the
            // spec defines as simply "q".
            default => $byte,
        };
    }

    /** ISO 32000-2 §7.3.4.3. */
    private function readHexString(): string
    {
        $start = $this->offset;
        ++$this->offset;
        $length = strlen($this->bytes);
        $digits = '';

        while ($this->offset < $length) {
            $byte = $this->bytes[$this->offset++];

            if ($byte === '>') {
                // An odd digit count is defined to pad with a trailing
                // zero, not to be an error.
                if (strlen($digits) % 2 === 1) {
                    $digits .= '0';
                }

                $binary = hex2bin($digits);

                return $binary === false ? '' : $binary;
            }

            if (ctype_xdigit($byte)) {
                $digits .= $byte;
            }

            // White space between digits is explicitly legal and ignored.
            // Any other byte is malformed; skipping it recovers the string
            // rather than losing the object that contains it.
        }

        throw ParseException::at($start, 'Unterminated hexadecimal string');
    }

    private function isRegular(string $byte): bool
    {
        return strpos(self::WHITESPACE, $byte) === false
            && strpos(self::DELIMITERS, $byte) === false;
    }
}
