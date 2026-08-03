<?php

declare(strict_types=1);

namespace MightyPDF\Content\Svg;

/**
 * Parses an SVG "transform" attribute (translate/scale/rotate/matrix)
 * into a list of 2D affine matrices, one per function in the attribute,
 * in the order written.
 *
 * Deliberately does not compose them into a single combined matrix: PDF's
 * "cm" operator already concatenates onto whatever CTM is active, so
 * emitting one "cm" call per parsed matrix, in the same left-to-right
 * order as the SVG attribute, produces the identical net transform
 * without this class needing its own matrix-multiplication code.
 * rotate(angle, cx, cy) (rotation about a point) is expanded into its
 * three constituent translate/rotate/translate-back matrices for exactly
 * the same reason.
 *
 * compose() exists for the one case where that does not hold: a gradient
 * is painted through a PDF pattern, and a pattern carries a single
 * matrix of its own rather than being drawn under the CTM. Working out
 * what that matrix should be means multiplying transforms here after
 * all.
 */
final class SvgTransform
{
    /** @var array{0: float, 1: float, 2: float, 3: float, 4: float, 5: float} */
    public const array IDENTITY = [1.0, 0.0, 0.0, 1.0, 0.0, 0.0];

    private function __construct()
    {
    }

    /**
     * The single matrix equivalent to applying $first and then $second.
     *
     * PDF and SVG both write a 2D affine transform as [a b c d e f] and
     * both treat a point as a row vector, so this is the same
     * multiplication in both worlds -- which is what lets a gradient's
     * own transform, the shape's bounding box and the placement of the
     * whole drawing be folded into one pattern matrix.
     *
     * @param array{0: float, 1: float, 2: float, 3: float, 4: float, 5: float} $first
     * @param array{0: float, 1: float, 2: float, 3: float, 4: float, 5: float} $second
     * @return array{0: float, 1: float, 2: float, 3: float, 4: float, 5: float}
     */
    public static function compose(array $first, array $second): array
    {
        [$a1, $b1, $c1, $d1, $e1, $f1] = $first;
        [$a2, $b2, $c2, $d2, $e2, $f2] = $second;

        return [
            $a1 * $a2 + $b1 * $c2,
            $a1 * $b2 + $b1 * $d2,
            $c1 * $a2 + $d1 * $c2,
            $c1 * $b2 + $d1 * $d2,
            $e1 * $a2 + $f1 * $c2 + $e2,
            $e1 * $b2 + $f1 * $d2 + $f2,
        ];
    }

    /**
     * A whole transform list multiplied out into one matrix.
     *
     * Element transforms are concatenated one "cm" at a time, so they
     * stay a list; a paint server's transform has to become a single
     * matrix, because that is all a PDF pattern has room for.
     *
     * @return array{0: float, 1: float, 2: float, 3: float, 4: float, 5: float}|null
     *         null where there is no transform at all, which callers
     *         treat as "leave the matrix alone" rather than composing an
     *         identity into it
     */
    public static function composed(?string $transform): ?array
    {
        $matrices = self::parse($transform);

        if ($matrices === []) {
            return null;
        }

        $composed = array_shift($matrices);

        foreach ($matrices as $matrix) {
            $composed = self::compose($matrix, $composed);
        }

        return $composed;
    }

    /** @return list<array{0: float, 1: float, 2: float, 3: float, 4: float, 5: float}> */
    public static function parse(?string $transform): array
    {
        if ($transform === null || trim($transform) === '') {
            return [];
        }

        $matrices = [];

        preg_match_all('/([a-zA-Z]+)\s*\(([^)]*)\)/', $transform, $calls, PREG_SET_ORDER);

        foreach ($calls as $call) {
            $function = strtolower($call[1]);
            $args = self::parseNumberList($call[2]);

            array_push($matrices, ...self::matricesForFunction($function, $args));
        }

        return $matrices;
    }

    /** @param list<float> $args */
    private static function matricesForFunction(string $function, array $args): array
    {
        return match ($function) {
            'translate' => [[1.0, 0.0, 0.0, 1.0, $args[0] ?? 0.0, $args[1] ?? 0.0]],
            'scale' => [[$args[0] ?? 1.0, 0.0, 0.0, $args[1] ?? $args[0] ?? 1.0, 0.0, 0.0]],
            'rotate' => self::rotate($args),
            'skewx' => [[1.0, 0.0, tan(deg2rad($args[0] ?? 0.0)), 1.0, 0.0, 0.0]],
            'skewy' => [[1.0, tan(deg2rad($args[0] ?? 0.0)), 0.0, 1.0, 0.0, 0.0]],
            'matrix' => [[
                $args[0] ?? 1.0, $args[1] ?? 0.0, $args[2] ?? 0.0,
                $args[3] ?? 1.0, $args[4] ?? 0.0, $args[5] ?? 0.0,
            ]],
            default => throw new \InvalidArgumentException("Unsupported SVG transform function: \"$function\""),
        };
    }

    /** @param list<float> $args @return list<array{0: float, 1: float, 2: float, 3: float, 4: float, 5: float}> */
    private static function rotate(array $args): array
    {
        $angle = deg2rad($args[0] ?? 0.0);
        $cos = cos($angle);
        $sin = sin($angle);
        $rotation = [$cos, $sin, -$sin, $cos, 0.0, 0.0];

        if (!isset($args[1], $args[2])) {
            return [$rotation];
        }

        [$cx, $cy] = [$args[1], $args[2]];

        return [
            [1.0, 0.0, 0.0, 1.0, $cx, $cy],
            $rotation,
            [1.0, 0.0, 0.0, 1.0, -$cx, -$cy],
        ];
    }

    /** @return list<float> */
    private static function parseNumberList(string $text): array
    {
        preg_match_all('/-?\d*\.?\d+(?:[eE][+-]?\d+)?/', $text, $matches);

        return array_map(floatval(...), $matches[0]);
    }
}
