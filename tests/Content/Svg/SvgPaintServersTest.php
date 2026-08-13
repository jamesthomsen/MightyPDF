<?php

declare(strict_types=1);

namespace MightyPDF\Tests\Content\Svg;

use MightyPDF\Content\Svg\SvgPaintServers;
use PHPUnit\Framework\TestCase;

final class SvgPaintServersTest extends TestCase
{
    /**
     * A shape's bounding box is asked for up to three times -- by the
     * soft mask, by the fill and by the stroke -- and for a path each
     * ask is a second walk of its "d". The answer cannot change in
     * between, so it is measured once.
     *
     * Worth a test of its own because the way to get this wrong is
     * invisible: an arrow function captures by value, which hands every
     * call its own copy of the memo and quietly measures every time.
     */
    public function testABoundingBoxIsMeasuredOnceHoweverOftenItIsAskedFor(): void
    {
        $measured = 0;
        $bounds = static function () use (&$measured): array {
            ++$measured;

            return [1.0, 2.0, 3.0, 4.0];
        };

        $once = self::measuredOnce($bounds);

        self::assertSame([1.0, 2.0, 3.0, 4.0], $once());
        self::assertSame([1.0, 2.0, 3.0, 4.0], $once());
        self::assertSame([1.0, 2.0, 3.0, 4.0], $once());
        self::assertSame(1, $measured, 'the box is measured once and remembered');
    }

    /** Two shapes are two boxes: the memo belongs to one call, not to the class. */
    public function testEachShapeMeasuresItsOwnBox(): void
    {
        $measured = 0;
        $bounds = static function () use (&$measured): array {
            ++$measured;

            return [0.0, 0.0, 1.0, 1.0];
        };

        self::measuredOnce($bounds)();
        self::measuredOnce($bounds)();

        self::assertSame(2, $measured);
    }

    /**
     * @param \Closure(): array{0: float, 1: float, 2: float, 3: float} $bounds
     * @return \Closure(): array{0: float, 1: float, 2: float, 3: float}
     */
    private static function measuredOnce(\Closure $bounds): \Closure
    {
        return (new \ReflectionMethod(SvgPaintServers::class, 'measuredOnce'))->invoke(null, $bounds);
    }
}
