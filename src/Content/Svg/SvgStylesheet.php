<?php

declare(strict_types=1);

namespace MightyPDF\Content\Svg;

/**
 * The CSS rules an SVG carries in its <style> elements, reduced to the
 * one question the renderer asks: given this element, which declarations
 * apply to it?
 *
 * This exists because drawing tools write style blocks, not attributes.
 * An SVG exported from Illustrator or Figma routinely looks like
 * `<style>.cls-1{fill:#e74c3c}</style>` with every shape carrying
 * `class="cls-1"` and no fill of its own -- so a renderer that reads
 * only presentation attributes draws the whole drawing black.
 *
 * **Scope: selectors that name one element.** Type (`rect`), class
 * (`.cls-1`), id (`#logo`), the universal selector, and any combination
 * of those on a single element (`rect.cls-1`), plus comma-separated
 * groups of them. Anything that describes an element's *surroundings* --
 * descendant and child combinators, sibling selectors -- is ignored
 * rather than approximated, since matching those needs a view of the
 * tree that this deliberately does not take. Pseudo-classes and
 * attribute selectors are ignored for the same reason. An ignored
 * selector contributes nothing; the rest of the rule set still applies.
 */
final class SvgStylesheet
{
    /**
     * @param list<array{
     *     specificity: int,
     *     order: int,
     *     tag: string|null,
     *     id: string|null,
     *     classes: list<string>,
     *     declarations: array<string, string>
     * }> $rules
     */
    private function __construct(private readonly array $rules)
    {
    }

    public static function empty(): self
    {
        return new self([]);
    }

    /** Reads every <style> element in the document, in the order they appear. */
    public static function parse(\SimpleXMLElement $root): self
    {
        $rules = [];

        foreach ($root->xpath("//*[local-name()='style']") ?: [] as $element) {
            foreach (self::parseRules((string) $element) as $rule) {
                // Document order across every block, since the tiebreak
                // between rules of equal specificity is which was
                // written last -- and that is a question about the
                // document, not about the block it came from.
                $rule['order'] = count($rules);
                $rules[] = $rule;
            }
        }

        return new self($rules);
    }

    public function isEmpty(): bool
    {
        return $this->rules === [];
    }

    /**
     * The declarations that apply to an element, already resolved
     * against each other: a more specific rule wins, and between two of
     * equal specificity the one written later does.
     *
     * @param array<string, string> $attributes the element's own attributes
     * @return array<string, string>
     */
    public function declarationsFor(string $tag, array $attributes): array
    {
        if ($this->rules === []) {
            return [];
        }

        $id = $attributes['id'] ?? null;
        $classes = preg_split('/\s+/', trim($attributes['class'] ?? '')) ?: [];
        $classes = array_filter($classes, static fn (string $class): bool => $class !== '');

        $matched = [];

        foreach ($this->rules as $rule) {
            if (!self::matches($rule, $tag, $id, $classes)) {
                continue;
            }

            $matched[] = $rule;
        }

        // Sorted rather than compared per declaration: the cascade is a
        // property of the rule, and applying them in order is what makes
        // the last writer of each property the winner.
        usort(
            $matched,
            static fn (array $a, array $b): int => [$a['specificity'], $a['order']] <=> [$b['specificity'], $b['order']],
        );

        $declarations = [];

        foreach ($matched as $rule) {
            $declarations = array_merge($declarations, $rule['declarations']);
        }

        return $declarations;
    }

    /**
     * @param array{specificity: int, order: int, tag: string|null, id: string|null, classes: list<string>, declarations: array<string, string>} $rule
     * @param list<string> $classes
     */
    private static function matches(array $rule, string $tag, ?string $id, array $classes): bool
    {
        if ($rule['tag'] !== null && $rule['tag'] !== $tag) {
            return false;
        }

        if ($rule['id'] !== null && $rule['id'] !== $id) {
            return false;
        }

        foreach ($rule['classes'] as $required) {
            if (!in_array($required, $classes, true)) {
                return false;
            }
        }

        return true;
    }

    /**
     * @return list<array{specificity: int, order: int, tag: string|null, id: string|null, classes: list<string>, declarations: array<string, string>}>
     */
    private static function parseRules(string $css): array
    {
        // Comments first: they may sit anywhere, including between a
        // selector and its brace, and every pattern below would
        // otherwise have to allow for them.
        $css = (string) preg_replace('!/\*.*?\*/!s', '', $css);
        $css = self::withoutAtRules($css);

        $rules = [];

        preg_match_all('/([^{}]+)\{([^{}]*)\}/s', $css, $blocks, PREG_SET_ORDER);

        foreach ($blocks as $block) {
            $selectors = trim($block[1]);

            if ($selectors === '') {
                continue;
            }

            $declarations = self::parseDeclarations($block[2]);

            if ($declarations === []) {
                continue;
            }

            foreach (explode(',', $selectors) as $selector) {
                $parsed = self::parseSelector(trim($selector));

                if ($parsed !== null) {
                    $rules[] = $parsed + ['declarations' => $declarations, 'order' => 0];
                }
            }
        }

        return $rules;
    }

    /**
     * Removes at-rules -- @media, @supports, @font-face, @import --
     * along with everything inside them.
     *
     * They have to go before the rules are matched, not while: an
     * @media block *contains* ordinary-looking rules, and a pattern
     * that scans for "selector { declarations }" happily finds them
     * inside one. Reading them is worse than ignoring them, since the
     * rules a document keeps for one medium are usually the opposite of
     * what it wants for another -- a `@media print { fill: black }` is
     * how a drawing says "not like this on screen", and lifting it out
     * of its block applies it everywhere.
     *
     * Deciding a PDF is the "print" medium and honouring those rules
     * would be defensible, but it is a decision, and it needs the rest
     * of the query language to be worth making.
     */
    private static function withoutAtRules(string $css): string
    {
        while (($start = strpos($css, '@')) !== false) {
            $depth = 0;
            $end = null;

            for ($i = $start, $length = strlen($css); $i < $length; ++$i) {
                $character = $css[$i];

                if ($character === '{') {
                    ++$depth;
                } elseif ($character === '}') {
                    --$depth;

                    if ($depth === 0) {
                        $end = $i + 1;

                        break;
                    }
                } elseif ($character === ';' && $depth === 0) {
                    // A statement rather than a block: @import and
                    // @charset end at a semicolon and have no body.
                    $end = $i + 1;

                    break;
                }
            }

            $css = substr($css, 0, $start) . substr($css, $end ?? strlen($css));
        }

        return $css;
    }

    /**
     * @return array{specificity: int, tag: string|null, id: string|null, classes: list<string>}|null
     *         null for a selector this does not match on -- see the
     *         class doc comment for which those are
     */
    private static function parseSelector(string $selector): ?array
    {
        if ($selector === '' || preg_match('/[\s>+~\[\]:()]/', $selector) === 1) {
            return null;
        }

        if (preg_match_all('/([.#]?)([A-Za-z0-9_-]+)|(\*)/', $selector, $parts, PREG_SET_ORDER) === 0) {
            return null;
        }

        $tag = null;
        $id = null;
        $classes = [];
        $consumed = 0;

        foreach ($parts as $part) {
            $consumed += strlen($part[0]);

            if (($part[3] ?? '') === '*') {
                continue;
            }

            match ($part[1]) {
                '.' => $classes[] = $part[2],
                '#' => $id = $part[2],
                default => $tag = $part[2],
            };
        }

        // Anything left over is syntax this did not understand, and a
        // selector understood in part is a selector that matches the
        // wrong elements.
        if ($consumed !== strlen($selector)) {
            return null;
        }

        return [
            // The usual CSS weighting: an id beats any number of
            // classes, a class beats any number of element names.
            'specificity' => 100 * ($id === null ? 0 : 1) + 10 * count($classes) + ($tag === null ? 0 : 1),
            'tag' => $tag,
            'id' => $id,
            'classes' => $classes,
        ];
    }

    /** @return array<string, string> */
    private static function parseDeclarations(string $body): array
    {
        $declarations = [];

        foreach (explode(';', $body) as $declaration) {
            if (!str_contains($declaration, ':')) {
                continue;
            }

            [$property, $value] = array_map(trim(...), explode(':', $declaration, 2));

            if ($property !== '' && $value !== '') {
                $declarations[$property] = $value;
            }
        }

        return $declarations;
    }
}
