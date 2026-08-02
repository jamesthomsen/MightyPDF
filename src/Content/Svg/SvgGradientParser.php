<?php

declare(strict_types=1);

namespace MightyPDF\Content\Svg;

/**
 * Finds every gradient in an SVG document and resolves it, including the
 * href inheritance that lets one gradient borrow another's stops.
 *
 * The whole document is searched rather than just its <defs>: putting
 * paint servers in <defs> is a convention, not a rule, and a gradient
 * declared next to the shape that uses it is perfectly ordinary output
 * from real drawing tools.
 */
final class SvgGradientParser
{
    /** A gradient inheriting through more than this many href hops is malformed or circular. */
    private const int MAX_INHERITANCE_DEPTH = 8;

    private function __construct()
    {
    }

    /**
     * @return array<string, SvgGradient> keyed by the id that fill="url(#id)" names
     */
    public static function collect(\SimpleXMLElement $root, float $viewportWidth, float $viewportHeight): array
    {
        $elements = [];

        foreach (['linearGradient', 'radialGradient'] as $tag) {
            foreach ($root->xpath("//*[local-name()='$tag']") ?: [] as $element) {
                $id = isset($element['id']) ? (string) $element['id'] : null;

                if ($id !== null && $id !== '') {
                    $elements[$id] = $element;
                }
            }
        }

        $gradients = [];

        foreach ($elements as $id => $element) {
            $gradient = self::resolve($element, $elements, $viewportWidth, $viewportHeight);

            if ($gradient !== null) {
                $gradients[$id] = $gradient;
            }
        }

        return $gradients;
    }

    /**
     * @param array<string, \SimpleXMLElement> $elements
     */
    private static function resolve(
        \SimpleXMLElement $element,
        array $elements,
        float $viewportWidth,
        float $viewportHeight,
    ): ?SvgGradient {
        $attributes = self::inheritedAttributes($element, $elements);
        $stops = self::inheritedStops($element, $elements);
        $isLinear = $element->getName() === 'linearGradient';

        // objectBoundingBox is the default, and the one that makes a
        // gradient reusable across shapes of different sizes.
        $userSpace = ($attributes['gradientUnits'] ?? '') === 'userSpaceOnUse';
        $reference = $userSpace ? $viewportWidth : 1.0;
        $verticalReference = $userSpace ? $viewportHeight : 1.0;

        // A percentage of "the normalized diagonal" -- the spec's own
        // phrasing, and the only sensible single length for a radius in
        // a viewport that is not square.
        $diagonal = $userSpace
            ? sqrt($viewportWidth ** 2 + $viewportHeight ** 2) / M_SQRT2
            : 1.0;

        $coordinates = $isLinear
            ? [
                self::length($attributes['x1'] ?? null, 0.0, $reference),
                self::length($attributes['y1'] ?? null, 0.0, $verticalReference),
                self::length($attributes['x2'] ?? null, $reference, $reference),
                self::length($attributes['y2'] ?? null, 0.0, $verticalReference),
            ]
            : self::radialCoordinates($attributes, $reference, $verticalReference, $diagonal);

        return new SvgGradient(
            $isLinear ? SvgGradient::LINEAR : SvgGradient::RADIAL,
            $coordinates,
            $stops,
            $userSpace,
            self::composedTransform($attributes['gradientTransform'] ?? null),
        );
    }

    /**
     * @param array<string, string> $attributes
     * @return array{0: float, 1: float, 2: float, 3: float, 4: float}
     */
    private static function radialCoordinates(
        array $attributes,
        float $reference,
        float $verticalReference,
        float $diagonal,
    ): array {
        $cx = self::length($attributes['cx'] ?? null, $reference / 2, $reference);
        $cy = self::length($attributes['cy'] ?? null, $verticalReference / 2, $verticalReference);
        $r = self::length($attributes['r'] ?? null, $diagonal / 2, $diagonal);

        // The focal point defaults to the centre, which is what makes
        // the common case a plain concentric radial gradient.
        return [
            $cx,
            $cy,
            $r,
            self::length($attributes['fx'] ?? null, $cx, $reference),
            self::length($attributes['fy'] ?? null, $cy, $verticalReference),
        ];
    }

    /**
     * Every attribute of this gradient, with any it does not set taken
     * from the gradient it references, and so on up the chain.
     *
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

        $parent = self::referencedGradient($element, $elements);

        if ($parent === null || $depth >= self::MAX_INHERITANCE_DEPTH) {
            return $attributes;
        }

        return $attributes + self::inheritedAttributes($parent, $elements, $depth + 1);
    }

    /**
     * This gradient's stops, or -- if it declares none -- the stops of
     * the gradient it references. Inheritance is all or nothing: a
     * gradient with even one stop of its own does not merge in another's.
     *
     * @param array<string, \SimpleXMLElement> $elements
     * @return list<SvgGradientStop>
     */
    private static function inheritedStops(
        \SimpleXMLElement $element,
        array $elements,
        int $depth = 0,
    ): array {
        $stops = self::ownStops($element);

        if ($stops !== []) {
            return $stops;
        }

        $parent = self::referencedGradient($element, $elements);

        if ($parent === null || $depth >= self::MAX_INHERITANCE_DEPTH) {
            return [];
        }

        return self::inheritedStops($parent, $elements, $depth + 1);
    }

    /** @return list<SvgGradientStop> */
    private static function ownStops(\SimpleXMLElement $element): array
    {
        $stops = [];
        $previousOffset = 0.0;

        foreach ($element->children() as $child) {
            if ($child->getName() !== 'stop') {
                continue;
            }

            $attributes = self::presentationAttributes($child);
            $offset = self::length($attributes['offset'] ?? null, 0.0, 1.0);

            // Offsets are required to be non-decreasing, and a file that
            // breaks that would otherwise produce a function whose
            // domain runs backwards -- which readers reject outright.
            $offset = max($previousOffset, min(1.0, max(0.0, $offset)));
            $previousOffset = $offset;

            $stops[] = new SvgGradientStop(
                $offset,
                SvgColor::parse($attributes['stop-color'] ?? '#000000') ?? [0.0, 0.0, 0.0],
            );
        }

        return $stops;
    }

    /**
     * A stop's colour may be written as an attribute or inside a style
     * declaration, and tools disagree about which -- Illustrator writes
     * one, Figma the other.
     *
     * @return array<string, string>
     */
    private static function presentationAttributes(\SimpleXMLElement $element): array
    {
        $attributes = [];

        foreach ($element->attributes() as $name => $value) {
            $attributes[(string) $name] = (string) $value;
        }

        foreach (explode(';', $attributes['style'] ?? '') as $declaration) {
            if (!str_contains($declaration, ':')) {
                continue;
            }

            [$property, $value] = array_map(trim(...), explode(':', $declaration, 2));
            $attributes[$property] = $value;
        }

        return $attributes;
    }

    /**
     * The gradient this one inherits from, via SVG 2's "href" or the
     * older "xlink:href". Both are current in the wild: tools have been
     * slow to move, and files outlive tools.
     *
     * @param array<string, \SimpleXMLElement> $elements
     */
    private static function referencedGradient(\SimpleXMLElement $element, array $elements): ?\SimpleXMLElement
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

    /**
     * gradientTransform holds a transform list like any other, and the
     * pattern it becomes takes a single matrix -- so unlike element
     * transforms, which PDF concatenates one "cm" at a time, these have
     * to be multiplied out here.
     *
     * @return array{0: float, 1: float, 2: float, 3: float, 4: float, 5: float}|null
     */
    private static function composedTransform(?string $transform): ?array
    {
        $matrices = SvgTransform::parse($transform);

        if ($matrices === []) {
            return null;
        }

        $composed = array_shift($matrices);

        foreach ($matrices as $matrix) {
            $composed = SvgTransform::compose($matrix, $composed);
        }

        return $composed;
    }

    /**
     * A gradient coordinate, which may be a plain number or a percentage
     * of whatever it is measured against.
     */
    private static function length(?string $value, float $default, float $reference): float
    {
        if ($value === null || trim($value) === '') {
            return $default;
        }

        $value = trim($value);

        if (str_ends_with($value, '%')) {
            return (float) substr($value, 0, -1) / 100.0 * $reference;
        }

        return (float) $value;
    }
}
