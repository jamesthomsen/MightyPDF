<?php

declare(strict_types=1);

namespace MightyPDF\Tests\Content\Svg;

use MightyPDF\Content\ContentStream;
use MightyPDF\Content\Svg\SvgPathParser;
use PHPUnit\Framework\TestCase;

final class SvgPathParserTest extends TestCase
{
    public function testMoveAndLineAbsolute(): void
    {
        $stream = new ContentStream();
        SvgPathParser::apply('M 10 20 L 30 40', $stream);

        self::assertSame("10 20 m\n30 40 l\n", $stream->bytes());
    }

    public function testMoveAndLineRelative(): void
    {
        $stream = new ContentStream();
        SvgPathParser::apply('m 10 20 l 5 5', $stream);

        self::assertSame("10 20 m\n15 25 l\n", $stream->bytes());
    }

    public function testImplicitLinetoAfterMoveto(): void
    {
        // "M 0 0 10 10 20 20" == moveto(0,0), then two implicit linetos.
        $stream = new ContentStream();
        SvgPathParser::apply('M 0 0 10 10 20 20', $stream);

        self::assertSame("0 0 m\n10 10 l\n20 20 l\n", $stream->bytes());
    }

    public function testHorizontalAndVerticalLineto(): void
    {
        $stream = new ContentStream();
        SvgPathParser::apply('M 0 0 H 10 V 20', $stream);

        self::assertSame("0 0 m\n10 0 l\n10 20 l\n", $stream->bytes());
    }

    public function testCubicBezierMapsDirectlyToCOperator(): void
    {
        $stream = new ContentStream();
        SvgPathParser::apply('M 0 0 C 1 2 3 4 5 6', $stream);

        self::assertSame("0 0 m\n1 2 3 4 5 6 c\n", $stream->bytes());
    }

    public function testClosePath(): void
    {
        $stream = new ContentStream();
        SvgPathParser::apply('M 0 0 L 10 0 L 10 10 Z', $stream);

        self::assertStringEndsWith("h\n", $stream->bytes());
    }

    public function testQuadraticBezierIsElevatedToCubic(): void
    {
        // Q from (0,0) via control (10,10) to (20,0): elevated cubic
        // control points are P0 + 2/3(Q-P0) and P1 + 2/3(Q-P1).
        $stream = new ContentStream();
        SvgPathParser::apply('M 0 0 Q 10 10 20 0', $stream);

        self::assertSame("0 0 m\n6.666667 6.666667 13.333333 6.666667 20 0 c\n", $stream->bytes());
    }

    public function testSmoothCubicReflectsThePreviousControlPoint(): void
    {
        // After "C ... 10 10 20 0" the reflected control point for the
        // following S is (2*20-10, 2*0-10) = (30, -10).
        $stream = new ContentStream();
        SvgPathParser::apply('M 0 0 C 0 0 10 10 20 0 S 40 10 40 0', $stream);

        self::assertStringContainsString('30 -10 40 10 40 0 c', $stream->bytes());
    }

    public function testRejectsPathNotStartingWithACommand(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        SvgPathParser::apply('10 20 L 30 40', new ContentStream());
    }
}
