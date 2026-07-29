<?php

declare(strict_types=1);

namespace MightyPDF\Tests\Assembler;

use MightyPDF\Assembler\Stream;
use PHPUnit\Framework\TestCase;

final class StreamTest extends TestCase
{
    public function testUncompressedStreamContainsRawBytesAndCorrectLength(): void
    {
        $stream = new Stream(5, 'Hello, world!', compress: false);
        $rendered = $stream->render(true);

        self::assertStringContainsString('/Length 13', $rendered);
        self::assertStringNotContainsString('/Filter', $rendered);
        self::assertStringContainsString("stream\nHello, world!\nendstream", $rendered);
        self::assertStringStartsWith('5 0 obj', $rendered);
        self::assertStringEndsWith("endobj\n", $rendered);
    }

    public function testCompressedStreamDeclaresFlateDecodeAndRoundTrips(): void
    {
        $original = str_repeat('The quick brown fox jumps over the lazy dog. ', 20);
        $stream = new Stream(1, $original, compress: true);
        $rendered = $stream->render(true);

        self::assertStringContainsString('/Filter /FlateDecode', $rendered);

        // Extract the bytes between "stream\n" and "\nendstream" and
        // confirm they actually decompress back to the original --
        // a real round trip through PHP's zlib functions now that a
        // PHP runtime is available to test against.
        $matched = preg_match('/stream\n(.*)\nendstream/s', $rendered, $matches);
        self::assertSame(1, $matched);

        self::assertSame($original, gzuncompress($matches[1]));
    }

    public function testCompressedStreamIsSmallerForRepetitiveContent(): void
    {
        $original = str_repeat('A', 10_000);
        $stream = new Stream(1, $original, compress: true);

        preg_match('/\/Length (\d+)/', $stream->render(true), $matches);
        self::assertLessThan(10_000, (int) $matches[1]);
    }

    public function testAppendBytesGrowsTheSameStreamObject(): void
    {
        // This is what lets a whole page's worth of drawing calls share
        // one content stream (and one object id) instead of allocating a
        // new stream per operation.
        $stream = new Stream(1, 'first ', compress: false);
        $stream->appendBytes('second');

        $rendered = $stream->render(true);
        self::assertStringContainsString('/Length 12', $rendered);
        self::assertStringContainsString("stream\nfirst second\nendstream", $rendered);
        self::assertStringStartsWith('1 0 obj', $rendered, 'object id must not change across appends');
    }
}
