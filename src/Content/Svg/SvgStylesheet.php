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
 * **Scope.** Type (`rect`), class (`.cls-1`), id (`#logo`) and the
 * universal selector, in any combination on one element (`rect.cls-1`),
 * joined by any of CSS's four combinators -- descendant (`g .label`),
 * child (`g > rect`), adjacent sibling (`rect + rect`) and general
 * sibling (`rect ~ text`) -- and in comma-separated groups. Matching a
 * combinator needs to know where the element sits, which is what
 * SvgElementPath carries.
 *
 * Pseudo-classes and attribute selectors are still ignored: they ask
 * questions about state and about attributes this renderer does not
 * model. An ignored selector contributes nothing and the rest of the
 * rule set still applies -- a selector understood in part would be
 * worse, since it would match the wrong elements confidently.
 */
final class SvgStylesheet
{
    /** The combinator joining one compound selector to the next. */
    private const string DESCENDANT = ' ';
    private const string CHILD = '>';
    private const string ADJACENT = '+';
    private const string SIBLING = '~';

    /**
     * The rules that could possibly match an element, filed under
     * something that element would have to be for them to.
     *
     * A rule's rightmost compound is the one the element in hand has to
     * satisfy itself -- everything left of it is about the element's
     * surroundings -- so whichever of an id, a class or a tag that
     * compound demands is a fact about every element the rule can match,
     * and rules demanding nothing go under '*'. Looking an element up
     * then asks after its own id, its own classes and its own tag, and
     * never sees the rest.
     *
     * @var array<string, list<array{
     *     specificity: int,
     *     order: int,
     *     compounds: list<array{tag: string|null, id: string|null, classes: list<string>}>,
     *     combinators: list<string>,
     *     declarations: array<string, string>
     * }>>
     */
    private readonly array $index;

    /**
     * @param list<array{
     *     specificity: int,
     *     order: int,
     *     compounds: list<array{tag: string|null, id: string|null, classes: list<string>}>,
     *     combinators: list<string>,
     *     declarations: array<string, string>
     * }> $rules
     */
    private function __construct(private readonly array $rules)
    {
        $index = [];

        foreach ($rules as $rule) {
            $index[self::keyOf($rule['compounds'][count($rule['compounds']) - 1])][] = $rule;
        }

        $this->index = $index;
    }

    /**
     * What an element must have for a rule ending in $compound to stand
     * a chance: its id, failing that any one of its classes, failing
     * that its tag, and '*' for a compound that demands none of the
     * three.
     *
     * Any one class will do rather than all of them -- an element with
     * the class is a superset of the elements the rule matches, which is
     * what a first pass has to be. The prefixes keep the three kinds of
     * name apart, since an element could perfectly well be a `text` with
     * class `text`.
     *
     * @param array{tag: string|null, id: string|null, classes: list<string>} $compound
     */
    private static function keyOf(array $compound): string
    {
        return match (true) {
            $compound['id'] !== null => '#' . $compound['id'],
            $compound['classes'] !== [] => '.' . $compound['classes'][0],
            $compound['tag'] !== null => 'tag:' . $compound['tag'],
            default => '*',
        };
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
     * @return array<string, string>
     */
    public function declarationsFor(SvgElementPath $element): array
    {
        if ($this->rules === []) {
            return [];
        }

        $matched = [];

        // Only the rules filed under something this element actually is,
        // rather than all of them. Testing every rule against every
        // element costs their product, which is the shape of a real
        // drawing rather than a contrived one: a chart of 3,000 shapes
        // under a 300-rule stylesheet is 900,000 attempts, and took 200
        // ms to place against 36 ms for the same drawing with its styles
        // inlined. The lookups below find about two.
        foreach ($this->candidatesFor($element) as $rule) {
            if (!self::matches($rule, $element)) {
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
     * The rules worth trying against $element: those filed under its id,
     * under any class it carries, under its tag, and those filed under
     * nothing in particular.
     *
     * A rule sits in exactly one of those buckets, so nothing is
     * returned twice however many of an element's classes are mentioned
     * elsewhere in the stylesheet. The cascade is settled afterwards by
     * sorting on specificity and document order, so the order these come
     * back in does not matter.
     *
     * @return iterable<array{specificity: int, order: int, compounds: list<array{tag: string|null, id: string|null, classes: list<string>}>, combinators: list<string>, declarations: array<string, string>}>
     */
    private function candidatesFor(SvgElementPath $element): iterable
    {
        $keys = ['*', 'tag:' . $element->tag];

        if ($element->id !== null) {
            $keys[] = '#' . $element->id;
        }

        foreach ($element->classes as $class) {
            $keys[] = '.' . $class;
        }

        foreach ($keys as $key) {
            yield from $this->index[$key] ?? [];
        }
    }

    /**
     * Matched right to left, which is both how browsers do it and the
     * only order that terminates quickly: the element in hand either is
     * the selector's subject or nothing else matters.
     *
     * @param array{specificity: int, order: int, compounds: list<array{tag: string|null, id: string|null, classes: list<string>}>, combinators: list<string>, declarations: array<string, string>} $rule
     */
    private static function matches(array $rule, SvgElementPath $element): bool
    {
        $last = count($rule['compounds']) - 1;

        if (!self::compoundMatches($rule['compounds'][$last], $element)) {
            return false;
        }

        $answered = [];

        return self::matchesLeftOf($rule, $last - 1, $element, $answered);
    }

    /**
     * Whether the part of the selector before compound $index + 1 is
     * satisfied by $element's surroundings.
     *
     * Descendant and general-sibling combinators are the ones that need
     * to try more than one candidate: "some ancestor" and "some earlier
     * sibling" are both allowed to fail on the nearest one and succeed
     * further out, which is why this recurses rather than walking.
     *
     * $answered is what keeps that recursion affordable. The question
     * asked here depends on nothing but the compound and the element, so
     * an answer already worked out is the answer -- and without saying
     * so, a selector that fails only at its leftmost end re-derives the
     * same failures down every path through the tree, which is
     * exponential in the number of combinators. Measured: a 441-byte
     * drawing with ten of them took 48 seconds to place, and each
     * further combinator multiplied that by about eighteen.
     *
     * @param array{compounds: list<array{tag: string|null, id: string|null, classes: list<string>}>, combinators: list<string>, ...} $rule
     * @param array<string, bool> $answered
     */
    private static function matchesLeftOf(array $rule, int $index, SvgElementPath $element, array &$answered): bool
    {
        if ($index < 0) {
            return true;
        }

        $key = $index . ':' . spl_object_id($element);

        if (isset($answered[$key])) {
            return $answered[$key];
        }

        $compound = $rule['compounds'][$index];
        $candidates = match ($rule['combinators'][$index]) {
            self::CHILD => $element->parent === null ? [] : [$element->parent],
            self::DESCENDANT => $element->ancestors(),
            self::ADJACENT => $element->previousSibling === null ? [] : [$element->previousSibling],
            default => $element->earlierSiblings(),
        };

        // Recorded before the loop as well as after it: a path is a
        // chain of distinct elements, so this cannot be reached again
        // while it is being answered, and seeding it keeps a malformed
        // chain from recurring even if one ever were.
        $answered[$key] = false;

        foreach ($candidates as $candidate) {
            if (self::compoundMatches($compound, $candidate) && self::matchesLeftOf($rule, $index - 1, $candidate, $answered)) {
                return $answered[$key] = true;
            }
        }

        return false;
    }

    /**
     * @param array{tag: string|null, id: string|null, classes: list<string>} $compound
     */
    private static function compoundMatches(array $compound, SvgElementPath $element): bool
    {
        if ($compound['tag'] !== null && $compound['tag'] !== $element->tag) {
            return false;
        }

        if ($compound['id'] !== null && $compound['id'] !== $element->id) {
            return false;
        }

        foreach ($compound['classes'] as $required) {
            if (!in_array($required, $element->classes, true)) {
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
        //
        // Kept as it was where the match fails -- a comment megabytes
        // long exhausts PCRE's backtrack limit, and preg_replace() says
        // so by returning null. Casting that to a string would answer
        // "this drawing has no styles at all", which is a whole
        // stylesheet silently discarded over one unclosed comment.
        $css = preg_replace('!/\*.*?\*/!s', '', $css) ?? $css;
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
        $length = strlen($css);
        $kept = [];
        $at = 0;

        // The pieces are collected and joined once rather than the
        // string being rebuilt around each at-rule, which copies all of
        // it per rule and turns a stylesheet full of @media into
        // quadratic work.
        while (($start = strpos($css, '@', $at)) !== false) {
            $kept[] = substr($css, $at, $start - $at);
            $depth = 0;
            $end = null;

            for ($i = $start; $i < $length; ++$i) {
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

            $at = $end ?? $length;
        }

        $kept[] = substr($css, $at);

        return implode('', $kept);
    }

    /**
     * Splits a selector into the compounds it is made of and the
     * combinators between them, subject last.
     *
     * @return array{specificity: int, compounds: list<array{tag: string|null, id: string|null, classes: list<string>}>, combinators: list<string>}|null
     *         null for a selector this does not match on -- see the
     *         class doc comment for which those are
     */
    private static function parseSelector(string $selector): ?array
    {
        $selector = trim($selector);

        if ($selector === '' || preg_match('/[\[\]:()]/', $selector) === 1) {
            return null;
        }

        // Whitespace around an explicit combinator belongs to it, not to
        // a descendant combinator of its own -- "g > rect" is two
        // compounds, not four.
        $pieces = preg_split('/\s*([>+~])\s*|\s+/', $selector, flags: PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY);

        if ($pieces === false || $pieces === []) {
            return null;
        }

        $compounds = [];
        $combinators = [];
        $specificity = 0;

        foreach ($pieces as $piece) {
            if (in_array($piece, [self::CHILD, self::ADJACENT, self::SIBLING], true)) {
                // Two combinators in a row, or one at either end, is
                // syntax rather than structure.
                if (count($combinators) >= count($compounds)) {
                    return null;
                }

                $combinators[] = $piece;

                continue;
            }

            $compound = self::parseCompound($piece);

            if ($compound === null) {
                return null;
            }

            // A compound following another with nothing between them was
            // separated by whitespace, which is the descendant
            // combinator spelled with nothing at all.
            if (count($compounds) > count($combinators)) {
                $combinators[] = self::DESCENDANT;
            }

            $compounds[] = $compound;
            $specificity += $compound['specificity'];
        }

        if ($compounds === [] || count($combinators) !== count($compounds) - 1) {
            return null;
        }

        return [
            'specificity' => $specificity,
            'compounds' => array_map(
                static fn (array $compound): array => [
                    'tag' => $compound['tag'],
                    'id' => $compound['id'],
                    'classes' => $compound['classes'],
                ],
                $compounds,
            ),
            'combinators' => $combinators,
        ];
    }

    /**
     * One element's worth of selector: "rect", ".cls-1", "rect#logo.a".
     *
     * @return array{specificity: int, tag: string|null, id: string|null, classes: list<string>}|null
     */
    private static function parseCompound(string $compound): ?array
    {
        if (preg_match_all('/([.#]?)([A-Za-z0-9_-]+)|(\*)/', $compound, $parts, PREG_SET_ORDER) === 0) {
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
        if ($consumed !== strlen($compound)) {
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
