<?php

declare(strict_types=1);

namespace MightyPDF\Content\Svg;

use MightyPDF\Exception\InvalidArgumentException;

/**
 * Parses an SVG/CSS paint value (as used in "fill"/"stroke" attributes)
 * into an RGB triple, or null for "none"/"transparent" (no paint).
 *
 * Supports #rgb/#rrggbb hex, rgb(r,g,b), and the original 16 HTML 4.01
 * named colors plus a handful of other very common CSS names -- not the
 * full ~147-name CSS color keyword table. An unrecognized name raises a
 * clear exception rather than silently guessing.
 */
final class SvgColor
{
    private const array NAMED_COLORS = [
        'black' => [0, 0, 0],
        'white' => [255, 255, 255],
        'red' => [255, 0, 0],
        'green' => [0, 128, 0],
        'blue' => [0, 0, 255],
        'yellow' => [255, 255, 0],
        'orange' => [255, 165, 0],
        'purple' => [128, 0, 128],
        'gray' => [128, 128, 128],
        'grey' => [128, 128, 128],
        'silver' => [192, 192, 192],
        'maroon' => [128, 0, 0],
        'olive' => [128, 128, 0],
        'lime' => [0, 255, 0],
        'aqua' => [0, 255, 255],
        'cyan' => [0, 255, 255],
        'teal' => [0, 128, 128],
        'navy' => [0, 0, 128],
        'fuchsia' => [255, 0, 255],
        'magenta' => [255, 0, 255],
        'pink' => [255, 192, 203],
        'brown' => [165, 42, 42],
    ];

    private function __construct()
    {
    }

    /**
     * The id in a paint value of the form `url(#sunset)`, or null for a
     * value that names a colour rather than a paint server.
     *
     * The quotes some tools write inside the parentheses are not part of
     * the id, and neither is the fallback colour that may follow the
     * reference -- `url(#sunset) blue` means "that gradient, or blue if
     * it cannot be found", and only the first half is answered here.
     */
    public static function referenceId(string $value): ?string
    {
        if (preg_match('/^url\(\s*[\'"]?#([^\'")\s]+)[\'"]?\s*\)/', trim($value), $matches) !== 1) {
            return null;
        }

        return $matches[1];
    }

    /** @return array{0: float, 1: float, 2: float}|null RGB, each 0.0-1.0, or null for no paint */
    public static function parse(?string $value): ?array
    {
        if ($value === null) {
            return null;
        }

        $value = trim($value);
        if ($value === '' || $value === 'none' || $value === 'transparent') {
            return null;
        }

        if (str_starts_with($value, 'url(')) {
            // A reference to a paint server. Gradients are resolved --
            // see referenceId() below and SvgRenderer, which is where
            // the document and the shape being painted are both in
            // scope. Patterns are not, and a reference to anything that
            // cannot be resolved degrades to "no paint" for this
            // property only, rather than failing the whole document over
            // one shape's decorative fill. That is the same fallback a
            // real renderer uses.
            return null;
        }

        if ($value[0] === '#') {
            return self::parseHex($value);
        }

        if (preg_match('/^rgb\(\s*(\d+)\s*,\s*(\d+)\s*,\s*(\d+)\s*\)$/i', $value, $m)) {
            return [(int) $m[1] / 255.0, (int) $m[2] / 255.0, (int) $m[3] / 255.0];
        }

        $lower = strtolower($value);
        if (isset(self::NAMED_COLORS[$lower])) {
            [$r, $g, $b] = self::NAMED_COLORS[$lower];

            return [$r / 255.0, $g / 255.0, $b / 255.0];
        }

        throw new InvalidArgumentException("Unrecognized SVG color value: \"$value\"");
    }

    /** @return array{0: float, 1: float, 2: float} */
    private static function parseHex(string $value): array
    {
        $hex = substr($value, 1);

        if (strlen($hex) === 3) {
            $hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
        }

        if (strlen($hex) !== 6 || !ctype_xdigit($hex)) {
            throw new InvalidArgumentException("Malformed hex color: \"$value\"");
        }

        return [
            hexdec(substr($hex, 0, 2)) / 255.0,
            hexdec(substr($hex, 2, 2)) / 255.0,
            hexdec(substr($hex, 4, 2)) / 255.0,
        ];
    }
}
