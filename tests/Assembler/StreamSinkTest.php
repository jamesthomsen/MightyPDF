<?php

declare(strict_types=1);

namespace MightyPDF\Tests\Assembler;

use MightyPDF\Assembler\StreamSink;
use MightyPDF\Assembler\StringSink;
use MightyPDF\Tests\Support\RefusingStreamWrapper;
use PHPUnit\Framework\TestCase;

final class StreamSinkTest extends TestCase
{
    public function testWritesReachTheStream(): void
    {
        $handle = fopen('php://memory', 'w+b');
        self::assertIsResource($handle);

        $sink = new StreamSink($handle);
        $sink->write('%PDF-1.7');
        $sink->write("\nbody");

        rewind($handle);
        self::assertSame("%PDF-1.7\nbody", stream_get_contents($handle));

        fclose($handle);
    }

    public function testOffsetCountsBytesWrittenNotTheStreamPosition(): void
    {
        $handle = fopen('php://memory', 'w+b');
        self::assertIsResource($handle);

        $sink = new StreamSink($handle);
        self::assertSame(0, $sink->offset());

        $sink->write('12345');
        self::assertSame(5, $sink->offset());

        $sink->write('678');
        self::assertSame(8, $sink->offset());

        fclose($handle);
    }

    /**
     * The offset is the one an xref entry is built from, so it has to be
     * measured in bytes rather than in whatever the string looks like:
     * a stream object's body is binary, and multi-byte sequences are not
     * one character each.
     */
    public function testOffsetIsInBytes(): void
    {
        $handle = fopen('php://memory', 'w+b');
        self::assertIsResource($handle);

        $sink = new StreamSink($handle);
        $sink->write("\xE2\xE3\xCF\xD3");

        self::assertSame(4, $sink->offset());

        fclose($handle);
    }

    public function testAStringSinkCountsTheSameWay(): void
    {
        $string = new StringSink();
        $stream = new StreamSink($handle = fopen('php://memory', 'w+b'));

        foreach (['%PDF-1.7', "\xE2\xE3\xCF\xD3", '1 0 obj'] as $chunk) {
            $string->write($chunk);
            $stream->write($chunk);

            self::assertSame($string->offset(), $stream->offset());
        }

        fclose($handle);
    }

    public function testARefusedWriteThrowsRatherThanTruncatingSilently(): void
    {
        $handle = RefusingStreamWrapper::open();
        $sink = new StreamSink($handle);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/Failed writing the PDF/');

        $sink->write('anything');
    }

    public function testARefusedWriteLeavesTheOffsetWhereItWas(): void
    {
        $sink = new StreamSink(RefusingStreamWrapper::open());

        try {
            $sink->write('anything');
        } catch (\RuntimeException) {
            // Expected -- what matters is what the sink says afterwards.
        }

        // Not "8 bytes written": nothing landed, and an xref built from
        // an optimistic offset is worse than no file at all.
        self::assertSame(0, $sink->offset());
    }

    public function testANonResourceIsRefusedUpFront(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/needs an open stream resource/');

        // @phpstan-ignore-next-line -- the point of the test
        new StreamSink('/tmp/not-a-handle.pdf');
    }

    public function testAClosedHandleIsRefusedUpFront(): void
    {
        $handle = fopen('php://memory', 'w+b');
        self::assertIsResource($handle);
        fclose($handle);

        $this->expectException(\InvalidArgumentException::class);

        new StreamSink($handle);
    }
}
