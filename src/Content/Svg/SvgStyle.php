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
    /** CSS's initial font-size, and as good a default as any for a document that names none. */
    private const float DEFAULT_FONT_SIZE = 16.0;

    /** A weight at or above this draws with the bold cut of the font. */
    private const int BOLD_WEIGHT = 600;

    /**
     * $fillReference/$strokeReference hold the id of a paint server --
     * `fill="url(#sunset)"` -- which is a paint this class cannot
     * resolve on its own: a gradient is a document-wide definition, and
     * what it becomes depends on the shape being painted. It is carried
     * as written and resolved by SvgRenderer, and it displaces the plain
     * colour rather than sitting alongside it, since an element has one
     * fill.
     *
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
        public readonly ?string $fillReference = null,
        public readonly ?string $strokeReference = null,
        public readonly ?string $fontFamily = null,
        public readonly float $fontSizePt = self::DEFAULT_FONT_SIZE,
        public readonly bool $bold = false,
        public readonly bool $italic = false,
        public readonly string $textAnchor = 'start',
        public readonly float $letterSpacing = 0.0,
    ) {
    }

    public function merge(\SimpleXMLElement $element): self
    {
        return $this->mergeAttributes(self::collectAttributes($element));
    }

    /**
     * The same merge from an attribute map rather than an element, for
     * callers holding something other than SimpleXML -- text content is
     * walked as DOM, since mixed content is the one thing SimpleXML
     * cannot present (see SvgRenderer).
     *
     * @param array<string, string> $attrs
     */
    public function mergeAttributes(array $attrs): self
    {
        $attrs = self::withStyleDeclarations($attrs);

        [$fill, $fillReference] = $this->paint($attrs['fill'] ?? null, $this->fill, $this->fillReference);
        [$stroke, $strokeReference] = $this->paint($attrs['stroke'] ?? null, $this->stroke, $this->strokeReference);

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
            $fillReference,
            $strokeReference,
            $attrs['font-family'] ?? $this->fontFamily,
            isset($attrs['font-size']) ? self::fontSize($attrs['font-size'], $this->fontSizePt) : $this->fontSizePt,
            isset($attrs['font-weight']) ? self::isBold($attrs['font-weight']) : $this->bold,
            isset($attrs['font-style']) ? $attrs['font-style'] !== 'normal' : $this->italic,
            $attrs['text-anchor'] ?? $this->textAnchor,
            isset($attrs['letter-spacing']) ? (float) $attrs['letter-spacing'] : $this->letterSpacing,
        );
    }

    /**
     * A font size, which CSS lets you write in a dozen units and SVG
     * artwork almost always writes as a plain number of user units.
     *
     * "em" and percentages are relative to the inherited size, which is
     * cheap to honour and would otherwise read as a size of 1. Other
     * units are taken at face value, the same simplification
     * SvgDocument makes for the drawing's own width and height.
     */
    private static function fontSize(string $value, float $inherited): float
    {
        $value = trim($value);

        if (!preg_match('/-?\d*\.?\d+/', $value, $matches)) {
            return $inherited;
        }

        $number = (float) $matches[0];

        return match (true) {
            str_ends_with($value, '%') => $inherited * $number / 100,
            str_ends_with($value, 'em'), str_ends_with($value, 'rem') => $inherited * $number,
            default => $number,
        };
    }

    private static function isBold(string $weight): bool
    {
        return match ($weight) {
            'bold', 'bolder' => true,
            'normal', 'lighter' => false,
            default => (int) $weight >= self::BOLD_WEIGHT,
        };
    }

    /**
     * Resolves one paint property against what was inherited: a colour,
     * a reference to a paint server, or neither -- and an element that
     * sets the property at all replaces both halves of what it inherited.
     *
     * @param array{0: float, 1: float, 2: float}|null $inheritedColor
     * @return array{0: array{0: float, 1: float, 2: float}|null, 1: string|null}
     */
    private function paint(?string $value, ?array $inheritedColor, ?string $inheritedReference): array
    {
        if ($value === null) {
            return [$inheritedColor, $inheritedReference];
        }

        $reference = SvgColor::referenceId($value);

        return $reference !== null ? [null, $reference] : [SvgColor::parse($value), null];
    }

    /** @return array<string, string> */
    private static function collectAttributes(\SimpleXMLElement $element): array
    {
        $attrs = [];
        foreach ($element->attributes() as $name => $value) {
            $attrs[(string) $name] = (string) $value;
        }

        return $attrs;
    }

    /**
     * Presentation attributes with any "style" attribute's declarations
     * overlaid, since either spelling means the same thing and tools
     * disagree about which to write.
     *
     * @param array<string, string> $attrs
     * @return array<string, string>
     */
    private static function withStyleDeclarations(array $attrs): array
    {
        foreach (explode(';', $attrs['style'] ?? '') as $declaration) {
            $declaration = trim($declaration);

            if ($declaration === '' || !str_contains($declaration, ':')) {
                continue;
            }

            [$property, $value] = array_map(trim(...), explode(':', $declaration, 2));
            $attrs[$property] = $value;
        }

        return $attrs;
    }
}
