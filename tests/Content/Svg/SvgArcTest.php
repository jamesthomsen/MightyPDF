<?php

declare(strict_types=1);

namespace MightyPDF\Tests\Content\Svg;

use MightyPDF\Content\ContentStream;
use MightyPDF\Content\Svg\SvgArc;
use PHPUnit\Framework\TestCase;

final class SvgArcTest extends TestCase
{
    public function testIdenticalEndpointsDrawNothing(): void
    {
        $stream = new ContentStream();
        SvgArc::emit($stream, 10, 10, 5, 5, 0, false, false, 10, 10);

        // Degenerate case still terminates the path segment (as a
        // zero-length line), it just shouldn't throw or hang.
        self::assertNotSame('', $stream->bytes());
    }

    public function testZeroRadiusDegeneratesToAStraightLine(): void
    {
        $stream = new ContentStream();
        SvgArc::emit($stream, 0, 0, 0, 5, 0, false, false, 10, 10);

        self::assertSame("10 10 l\n", $stream->bytes());
    }

    public function testQuarterCircleArcEndsAtTheRequestedPoint(): void
    {
        // A quarter-circle arc of radius 10 from (10,0) to (0,10) (center
        // at the origin) should end with a curveTo whose final endpoint
        // is exactly (0, 10), regardless of how many Bezier segments the
        // 90-degree sweep gets split into.
        $stream = new ContentStream();
        SvgArc::emit($stream, 10, 0, 10, 10, 0, false, true, 0, 10);

        $bytes = $stream->bytes();
        self::assertStringContainsString(' c', $bytes);

        preg_match_all('/([\d.-]+) ([\d.-]+) c\n/', $bytes, $matches);
        $lastX = (float) end($matches[1]);
        $lastY = (float) end($matches[2]);
        self::assertEqualsWithDelta(0.0, $lastX, 0.01);
        self::assertEqualsWithDelta(10.0, $lastY, 0.01);
    }

    public function testLargeArcFlagProducesMoreCurveSegmentsThanSmallArc(): void
    {
        // Endpoints 90 degrees apart on the circle: the "small" choice is
        // a 90-degree arc (1 segment), the "large" choice is the other
        // 270-degree way around (3 segments). Diametrically opposite
        // endpoints wouldn't work for this test -- both arcs would be an
        // equal 180 degrees, with no large/small distinction at all.
        $small = new ContentStream();
        SvgArc::emit($small, 10, 0, 10, 10, 0, false, true, 0, 10);

        $large = new ContentStream();
        SvgArc::emit($large, 10, 0, 10, 10, 0, true, true, 0, 10);

        $smallSegments = substr_count($small->bytes(), ' c');
        $largeSegments = substr_count($large->bytes(), ' c');

        self::assertGreaterThan($smallSegments, $largeSegments);
    }
}
