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
     * @param ?self $previousSibling the element immediately before this
     *        one, which is a chain back through all of them
     */
    private function __construct(
        public readonly string $tag,
        public readonly ?string $id,
        public readonly array $classes,
        public readonly ?self $parent,
        public readonly ?self $previousSibling,
    ) {
    }

    /**
     * @param array<string, string> $attributes
     */
    public static function of(
        string $tag,
        array $attributes,
        ?self $parent = null,
        ?self $previousSibling = null,
    ): self {
        $classes = preg_split('/\s+/', trim($attributes['class'] ?? '')) ?: [];

        return new self(
            $tag,
            ($attributes['id'] ?? '') === '' ? null : $attributes['id'],
            array_values(array_filter($classes, static fn (string $class): bool => $class !== '')),
            $parent,
            $previousSibling,
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

    /**
     * @return list<self> from the nearest earlier sibling backwards,
     *         which is the order a general-sibling selector wants to try
     *         them in
     *
     * Walked from a chain rather than kept as a list. Handing each
     * element a copy of the siblings before it costs an array per
     * element of a length that grows with every one of them: an 813 KB
     * drawing of plain rectangles took three gigabytes, and doubling
     * the shapes quadrupled it. The chain says the same thing for one
     * reference each.
     */
    public function earlierSiblings(): array
    {
        $siblings = [];

        for ($element = $this->previousSibling; $element !== null; $element = $element->previousSibling) {
            $siblings[] = $element;
        }

        return $siblings;
    }
}
