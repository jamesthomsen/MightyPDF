<?php

declare(strict_types=1);

namespace MightyPDF\Content\Svg;

use MightyPDF\Content\PathSink;

/**
 * Converts an SVG elliptical-arc path segment (the "A"/"a" command) into
 * one or more cubic Bezier curves -- PDF has no arc primitive. This is
 * the standard endpoint-to-center parameterization + per-segment Bezier
 * approximation described in the SVG 1.1 Implementation Notes (Appendix
 * F.6), the same algorithm essentially every SVG-to-vector-format
 * converter uses.
 */
final class SvgArc
{
    private function __construct()
    {
    }

    public static function emit(
        PathSink $stream,
        float $x1,
        float $y1,
        float $rx,
        float $ry,
        float $rotationDeg,
        bool $largeArc,
        bool $sweep,
        float $x2,
        float $y2,
    ): void {
        if (($x1 === $x2 && $y1 === $y2) || $rx === 0.0 || $ry === 0.0) {
            // Per spec: identical endpoints draw nothing; a zero radius
            // degenerates to a straight line.
            $stream->lineTo($x2, $y2);

            return;
        }

        $rx = abs($rx);
        $ry = abs($ry);
        $phi = deg2rad($rotationDeg);
        $cosPhi = cos($phi);
        $sinPhi = sin($phi);

        $dx2 = ($x1 - $x2) / 2;
        $dy2 = ($y1 - $y2) / 2;
        $x1p = $cosPhi * $dx2 + $sinPhi * $dy2;
        $y1p = -$sinPhi * $dx2 + $cosPhi * $dy2;

        $lambda = ($x1p ** 2) / ($rx ** 2) + ($y1p ** 2) / ($ry ** 2);
        if ($lambda > 1) {
            $scale = sqrt($lambda);
            $rx *= $scale;
            $ry *= $scale;
        }

        $sign = ($largeArc !== $sweep) ? 1.0 : -1.0;
        $num = ($rx ** 2) * ($ry ** 2) - ($rx ** 2) * ($y1p ** 2) - ($ry ** 2) * ($x1p ** 2);
        $den = ($rx ** 2) * ($y1p ** 2) + ($ry ** 2) * ($x1p ** 2);
        $co = $den === 0.0 ? 0.0 : $sign * sqrt(max(0.0, $num / $den));

        $cxp = $co * ($rx * $y1p / $ry);
        $cyp = $co * (-$ry * $x1p / $rx);

        $cx = $cosPhi * $cxp - $sinPhi * $cyp + ($x1 + $x2) / 2;
        $cy = $sinPhi * $cxp + $cosPhi * $cyp + ($y1 + $y2) / 2;

        $theta1 = self::angleBetween(1.0, 0.0, ($x1p - $cxp) / $rx, ($y1p - $cyp) / $ry);
        $deltaTheta = self::angleBetween(
            ($x1p - $cxp) / $rx,
            ($y1p - $cyp) / $ry,
            (-$x1p - $cxp) / $rx,
            (-$y1p - $cyp) / $ry,
        );

        if (!$sweep && $deltaTheta > 0) {
            $deltaTheta -= 2 * M_PI;
        } elseif ($sweep && $deltaTheta < 0) {
            $deltaTheta += 2 * M_PI;
        }

        // Split into segments of at most 90 degrees each -- the cubic
        // Bezier approximation of a circular arc loses accuracy beyond
        // that.
        $segmentCount = (int) ceil(abs($deltaTheta) / (M_PI / 2));
        $segmentAngle = $deltaTheta / $segmentCount;

        for ($i = 0; $i < $segmentCount; ++$i) {
            $a1 = $theta1 + $i * $segmentAngle;
            $a2 = $a1 + $segmentAngle;
            self::emitSegment($stream, $cx, $cy, $rx, $ry, $cosPhi, $sinPhi, $a1, $a2);
        }
    }

    private static function emitSegment(
        PathSink $stream,
        float $cx,
        float $cy,
        float $rx,
        float $ry,
        float $cosPhi,
        float $sinPhi,
        float $a1,
        float $a2,
    ): void {
        $t = tan(($a2 - $a1) / 4);
        $alpha = sin($a2 - $a1) * (sqrt(4 + 3 * $t ** 2) - 1) / 3;

        $cosA1 = cos($a1);
        $sinA1 = sin($a1);
        $cosA2 = cos($a2);
        $sinA2 = sin($a2);

        $p1x = $cosA1 - $alpha * $sinA1;
        $p1y = $sinA1 + $alpha * $cosA1;
        $p2x = $cosA2 + $alpha * $sinA2;
        $p2y = $sinA2 - $alpha * $cosA2;

        [$x1, $y1] = self::toEllipse($cx, $cy, $rx, $ry, $cosPhi, $sinPhi, $p1x, $p1y);
        [$x2, $y2] = self::toEllipse($cx, $cy, $rx, $ry, $cosPhi, $sinPhi, $p2x, $p2y);
        [$x3, $y3] = self::toEllipse($cx, $cy, $rx, $ry, $cosPhi, $sinPhi, $cosA2, $sinA2);

        $stream->curveTo($x1, $y1, $x2, $y2, $x3, $y3);
    }

    /** @return array{0: float, 1: float} */
    private static function toEllipse(
        float $cx,
        float $cy,
        float $rx,
        float $ry,
        float $cosPhi,
        float $sinPhi,
        float $unitX,
        float $unitY,
    ): array {
        return [
            $cx + $cosPhi * ($rx * $unitX) - $sinPhi * ($ry * $unitY),
            $cy + $sinPhi * ($rx * $unitX) + $cosPhi * ($ry * $unitY),
        ];
    }

    private static function angleBetween(float $ux, float $uy, float $vx, float $vy): float
    {
        $dot = $ux * $vx + $uy * $vy;
        $lenProduct = sqrt(($ux ** 2 + $uy ** 2) * ($vx ** 2 + $vy ** 2));
        $cosAngle = max(-1.0, min(1.0, $dot / $lenProduct));
        $angle = acos($cosAngle);

        return ($ux * $vy - $uy * $vx) < 0 ? -$angle : $angle;
    }
}
