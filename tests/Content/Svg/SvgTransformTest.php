<?php

declare(strict_types=1);

namespace MightyPDF\Tests\Content\Svg;

use MightyPDF\Content\Svg\SvgTransform;
use PHPUnit\Framework\TestCase;

final class SvgTransformTest extends TestCase
{
    public function testEmptyOrNullTransformYieldsNoMatrices(): void
    {
        self::assertSame([], SvgTransform::parse(null));
        self::assertSame([], SvgTransform::parse(''));
    }

    public function testTranslate(): void
    {
        self::assertSame([[1.0, 0.0, 0.0, 1.0, 10.0, 20.0]], SvgTransform::parse('translate(10, 20)'));
    }

    public function testScaleWithBothAxes(): void
    {
        self::assertSame([[2.0, 0.0, 0.0, 3.0, 0.0, 0.0]], SvgTransform::parse('scale(2, 3)'));
    }

    public function testScaleWithOneArgumentAppliesToBothAxes(): void
    {
        self::assertSame([[2.0, 0.0, 0.0, 2.0, 0.0, 0.0]], SvgTransform::parse('scale(2)'));
    }

    public function testRotateAroundOrigin(): void
    {
        [$matrix] = SvgTransform::parse('rotate(90)');

        self::assertEqualsWithDelta(0.0, $matrix[0], 1e-9);
        self::assertEqualsWithDelta(1.0, $matrix[1], 1e-9);
        self::assertEqualsWithDelta(-1.0, $matrix[2], 1e-9);
        self::assertEqualsWithDelta(0.0, $matrix[3], 1e-9);
    }

    public function testRotateAroundPointExpandsToThreeMatrices(): void
    {
        $matrices = SvgTransform::parse('rotate(90, 5, 5)');

        self::assertCount(3, $matrices);
        self::assertSame([1.0, 0.0, 0.0, 1.0, 5.0, 5.0], $matrices[0]);
        self::assertSame([1.0, 0.0, 0.0, 1.0, -5.0, -5.0], $matrices[2]);
    }

    public function testMatrixFunction(): void
    {
        self::assertSame([[1.0, 2.0, 3.0, 4.0, 5.0, 6.0]], SvgTransform::parse('matrix(1,2,3,4,5,6)'));
    }

    public function testMultipleTransformsAreKeptSeparateInOrder(): void
    {
        $matrices = SvgTransform::parse('translate(10,20) scale(2)');

        self::assertCount(2, $matrices);
        self::assertSame([1.0, 0.0, 0.0, 1.0, 10.0, 20.0], $matrices[0]);
        self::assertSame([2.0, 0.0, 0.0, 2.0, 0.0, 0.0], $matrices[1]);
    }

    public function testRejectsUnsupportedFunction(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        SvgTransform::parse('perspective(10)');
    }
}
