<?php

declare(strict_types=1);

namespace MightyPDF\Content\Svg;

/**
 * Finds every <pattern> in an SVG document and resolves it, including
 * the href inheritance a pattern shares with gradients: one pattern can
 * borrow another's tile, its content, or both.
 *
 * Written separately from SvgGradientParser rather than generalised out
 * of it. The two share the shape of the problem -- collect by id, follow
 * href, resolve units -- and almost none of the details: a gradient
 * resolves to numbers, while a pattern resolves to a piece of the
 * document that has yet to be drawn.
 */
final class SvgPatternParser
{
    /** A pattern inheriting through more than this many href hops is malformed or circular. */
    private const int MAX_INHERITANCE_DEPTH = 8;

    private function __construct()
    {
    }

    /**
     * @return array<string, SvgPattern> keyed by the id that fill="url(#id)" names
     */
    public static function collect(\SimpleXMLElement $root): array
    {
        $elements = [];

        foreach ($root->xpath("//*[local-name()='pattern']") ?: [] as $element) {
            $id = isset($element['id']) ? (string) $element['id'] : null;

            if ($id !== null && $id !== '') {
                $elements[$id] = $element;
            }
        }

        $patterns = [];

        foreach ($elements as $id => $element) {
            $pattern = self::resolve($element, $elements);

            if ($pattern !== null) {
                $patterns[$id] = $pattern;
            }
        }

        return $patterns;
    }

    /**
     * @param array<string, \SimpleXMLElement> $elements
     */
    private static function resolve(\SimpleXMLElement $element, array $elements): ?SvgPattern
    {
        $attributes = self::inheritedAttributes($element, $elements);
        $content = self::contentElement($element, $elements);

        if ($content === null) {
            return null;
        }

        // objectBoundingBox for the tile, userSpaceOnUse for what is
        // drawn in it: the defaults are opposites, and getting them the
        // wrong way round produces a tile of the right size holding a
        // drawing scaled by the size of the shape.
        $userSpace = ($attributes['patternUnits'] ?? '') === 'userSpaceOnUse';
        $contentInUserSpace = ($attributes['patternContentUnits'] ?? '') !== 'objectBoundingBox';

        return new SvgPattern(
            self::length($attributes['x'] ?? null, $userSpace),
            self::length($attributes['y'] ?? null, $userSpace),
            self::length($attributes['width'] ?? null, $userSpace),
            self::length($attributes['height'] ?? null, $userSpace),
            $userSpace,
            $contentInUserSpace,
            self::viewBox($attributes['viewBox'] ?? null),
            $attributes['preserveAspectRatio'] ?? 'xMidYMid meet',
            SvgTransform::composed($attributes['patternTransform'] ?? null),
            $content,
        );
    }

    /**
     * The element whose children fill the tile: this pattern's own if it
     * has any, otherwise those of the pattern it references.
     *
     * All or nothing, like a gradient's stops -- a pattern with content
     * of its own does not also draw an inherited pattern's.
     *
     * @param array<string, \SimpleXMLElement> $elements
     */
    private static function contentElement(
        \SimpleXMLElement $element,
        array $elements,
        int $depth = 0,
    ): ?\SimpleXMLElement {
        if ($element->children()->count() > 0) {
            return $element;
        }

        $parent = self::referenced($element, $elements);

        if ($parent === null || $depth >= self::MAX_INHERITANCE_DEPTH) {
            return null;
        }

        return self::contentElement($parent, $elements, $depth + 1);
    }

    /**
     * @param array<string, \SimpleXMLElement> $elements
     * @return array<string, string>
     */
    private static function inheritedAttributes(
        \SimpleXMLElement $element,
        array $elements,
        int $depth = 0,
    ): array {
        $attributes = [];

        foreach ($element->attributes() as $name => $value) {
            $attributes[(string) $name] = (string) $value;
        }

        $parent = self::referenced($element, $elements);

        if ($parent === null || $depth >= self::MAX_INHERITANCE_DEPTH) {
            return $attributes;
        }

        return $attributes + self::inheritedAttributes($parent, $elements, $depth + 1);
    }

    /**
     * The pattern this one inherits from, via SVG 2's "href" or the older
     * "xlink:href" -- both current in the wild, as with gradients.
     *
     * @param array<string, \SimpleXMLElement> $elements
     */
    private static function referenced(\SimpleXMLElement $element, array $elements): ?\SimpleXMLElement
    {
        $href = (string) ($element->attributes()['href'] ?? '');

        if ($href === '') {
            $href = (string) ($element->attributes('http://www.w3.org/1999/xlink')['href'] ?? '');
        }

        if (!str_starts_with($href, '#')) {
            return null;
        }

        return $elements[substr($href, 1)] ?? null;
    }

    /** @return array{0: float, 1: float, 2: float, 3: float}|null */
    private static function viewBox(?string $value): ?array
    {
        if ($value === null) {
            return null;
        }

        $parts = preg_split('/[\s,]+/', trim($value));

        if ($parts === false || count($parts) !== 4) {
            return null;
        }

        return array_map(floatval(...), $parts);
    }

    /**
     * A tile measurement, which may be a percentage.
     *
     * In objectBoundingBox units -- the default -- the numbers are
     * already fractions of the shape's box, so "10%" and "0.1" say the
     * same thing. In user space a percentage would be a fraction of the
     * viewport, which is a length this parser does not have; such a
     * pattern is left with a zero-sized tile and so paints nothing,
     * rather than being placed somewhere invented.
     */
    private static function length(?string $value, bool $userSpace): float
    {
        $value = trim($value ?? '');

        if ($value === '') {
            return 0.0;
        }

        if (str_ends_with($value, '%')) {
            return $userSpace ? 0.0 : (float) substr($value, 0, -1) / 100.0;
        }

        return (float) $value;
    }
}
