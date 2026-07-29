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
 */
final class SvgTransform
{
    private function __construct()
    {
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
