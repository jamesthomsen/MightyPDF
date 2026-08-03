<?php

declare(strict_types=1);

namespace MightyPDF\Content\Svg;

/**
 * Where an element sits in the drawing: what it is, what contains it,
 * and what came before it among its siblings.
 *
 * This exists for CSS selectors that describe an element's surroundings
 * -- `g .label`, `g > rect`, `rect + rect`. A selector like `.label`
 * needs nothing but the element; a selector with a combinator in it
 * needs the walk that reached it, and the renderer is the only thing
 * that has that.
 *
 * Built as a chain rather than an array of ancestors so that a sibling
 * at any level carries its own surroundings too: matching `.a + .b .c`
 * asks whether some ancestor of `.c` matches `.b`, and then whether
 * *that* element has a preceding sibling matching `.a`.
 */
final class SvgElementPath
{
    /**
     * @param list<string> $classes
     * @param list<self> $precedingSiblings in document order, so the
     *        adjacent sibling is the last of them
     */
    private function __construct(
        public readonly string $tag,
        public readonly ?string $id,
        public readonly array $classes,
        public readonly ?self $parent,
        public readonly array $precedingSiblings,
    ) {
    }

    /**
     * @param array<string, string> $attributes
     * @param list<self> $precedingSiblings
     */
    public static function of(
        string $tag,
        array $attributes,
        ?self $parent = null,
        array $precedingSiblings = [],
    ): self {
        $classes = preg_split('/\s+/', trim($attributes['class'] ?? '')) ?: [];

        return new self(
            $tag,
            ($attributes['id'] ?? '') === '' ? null : $attributes['id'],
            array_values(array_filter($classes, static fn (string $class): bool => $class !== '')),
            $parent,
            $precedingSiblings,
        );
    }

    /** @return list<self> from the immediate parent outwards */
    public function ancestors(): array
    {
        $ancestors = [];

        for ($element = $this->parent; $element !== null; $element = $element->parent) {
            $ancestors[] = $element;
        }

        return $ancestors;
    }
}
