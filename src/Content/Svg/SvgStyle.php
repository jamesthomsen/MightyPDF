<?php

declare(strict_types=1);

namespace MightyPDF\Content\Svg;

/**
 * The inherited presentation-attribute state (fill/stroke/opacity/etc.)
 * at a point in the SVG element tree.
 *
 * fill/stroke/stroke-width/fill-rule are ordinary inherited properties: a
 * descendant keeps the ancestor's value unless it sets its own. opacity
 * is technically NOT inherited in the CSS/SVG sense -- a parent's
 * opacity should create an isolated transparency group that its whole
 * subtree composites into, then applies alpha to once. Building that
 * (a Form XObject + soft mask) is real complexity beyond this milestone's
 * "practical common subset" scope, so this instead approximates it by
 * multiplying opacity/fill-opacity/stroke-opacity down the tree as if
 * they were inherited. That's exact for the common case (opacity set on
 * a leaf shape, or a group with non-overlapping children) and only
 * diverges from strict spec behavior for overlapping semi-transparent
 * shapes nested inside a group opacity -- an uncommon case for the
 * icons/logos/diagrams this is aimed at.
 */
final class SvgStyle
{
    /**
     * @param array{0: float, 1: float, 2: float}|null $fill
     * @param array{0: float, 1: float, 2: float}|null $stroke
     */
    public function __construct(
        public readonly ?array $fill = [0.0, 0.0, 0.0],
        public readonly ?array $stroke = null,
        public readonly float $strokeWidth = 1.0,
        public readonly float $fillOpacity = 1.0,
        public readonly float $strokeOpacity = 1.0,
        public readonly bool $evenOdd = false,
    ) {
    }

    public function merge(\SimpleXMLElement $element): self
    {
        $attrs = self::collectAttributes($element);

        $fill = isset($attrs['fill']) ? SvgColor::parse($attrs['fill']) : $this->fill;
        $stroke = isset($attrs['stroke']) ? SvgColor::parse($attrs['stroke']) : $this->stroke;
        $strokeWidth = isset($attrs['stroke-width']) ? (float) $attrs['stroke-width'] : $this->strokeWidth;
        $evenOdd = isset($attrs['fill-rule']) ? $attrs['fill-rule'] === 'evenodd' : $this->evenOdd;

        $ownOpacity = isset($attrs['opacity']) ? (float) $attrs['opacity'] : 1.0;
        $ownFillOpacity = isset($attrs['fill-opacity']) ? (float) $attrs['fill-opacity'] : 1.0;
        $ownStrokeOpacity = isset($attrs['stroke-opacity']) ? (float) $attrs['stroke-opacity'] : 1.0;

        return new self(
            $fill,
            $stroke,
            $strokeWidth,
            $this->fillOpacity * $ownFillOpacity * $ownOpacity,
            $this->strokeOpacity * $ownStrokeOpacity * $ownOpacity,
            $evenOdd,
        );
    }

    /** @return array<string, string> presentation attributes, with any "style" attribute's declarations overlaid */
    private static function collectAttributes(\SimpleXMLElement $element): array
    {
        $attrs = [];
        foreach ($element->attributes() as $name => $value) {
            $attrs[(string) $name] = (string) $value;
        }

        if (isset($attrs['style'])) {
            foreach (explode(';', $attrs['style']) as $declaration) {
                $declaration = trim($declaration);
                if ($declaration === '' || !str_contains($declaration, ':')) {
                    continue;
                }
                [$property, $value] = array_map(trim(...), explode(':', $declaration, 2));
                $attrs[$property] = $value;
            }
        }

        return $attrs;
    }
}
