<?php

declare(strict_types=1);

namespace MightyPDF\Tests\Content\Svg;

use MightyPDF\Content\Svg\SvgElementPath;
use MightyPDF\Content\Svg\SvgStylesheet;
use PHPUnit\Framework\TestCase;

final class SvgStylesheetTest extends TestCase
{
    public function testAClassSelectorAppliesToElementsCarryingTheClass(): void
    {
        $sheet = self::sheet('.brand { fill: #ff0000; }');

        self::assertSame(['fill' => '#ff0000'], self::applied($sheet, 'rect', ['class' => 'brand']));
        self::assertSame([], self::applied($sheet, 'rect', []));
    }

    public function testOneOfSeveralClassesIsEnough(): void
    {
        $sheet = self::sheet('.brand { fill: #ff0000; }');

        self::assertSame(['fill' => '#ff0000'], self::applied($sheet, 'rect', ['class' => 'shadow brand wide']));
    }

    public function testASelectorMayRequireSeveralClassesAtOnce(): void
    {
        $sheet = self::sheet('.a.b { fill: red; }');

        self::assertSame(['fill' => 'red'], self::applied($sheet, 'rect', ['class' => 'a b']));
        self::assertSame([], self::applied($sheet, 'rect', ['class' => 'a']));
    }

    public function testTypeAndIdSelectors(): void
    {
        $sheet = self::sheet('rect { fill: grey; } #hero { fill: green; }');

        self::assertSame(['fill' => 'grey'], self::applied($sheet, 'rect', []));
        self::assertSame([], self::applied($sheet, 'circle', []));
        self::assertSame(['fill' => 'green'], self::applied($sheet, 'circle', ['id' => 'hero']));
    }

    public function testTheUniversalSelectorAppliesToEverything(): void
    {
        self::assertSame(['fill' => 'red'], self::applied(self::sheet('* { fill: red; }'), 'path'));
    }

    public function testAGroupOfSelectorsSharesOneBlock(): void
    {
        $sheet = self::sheet('.a, .b { fill: red; }');

        self::assertSame(['fill' => 'red'], self::applied($sheet, 'rect', ['class' => 'a']));
        self::assertSame(['fill' => 'red'], self::applied($sheet, 'rect', ['class' => 'b']));
    }

    /** An id beats any number of classes, and a class beats an element name. */
    public function testTheMoreSpecificSelectorWins(): void
    {
        $sheet = self::sheet('#hero { fill: green; } .brand { fill: red; } rect { fill: grey; }');

        $declarations = self::applied($sheet, 'rect', ['id' => 'hero', 'class' => 'brand']);

        self::assertSame('green', $declarations['fill']);
    }

    public function testATypeAndClassTogetherBeatTheClassAlone(): void
    {
        $sheet = self::sheet('.brand { fill: red; } rect.brand { fill: orange; }');

        self::assertSame('orange', self::applied($sheet, 'rect', ['class' => 'brand'])['fill']);
        self::assertSame('red', self::applied($sheet, 'circle', ['class' => 'brand'])['fill']);
    }

    public function testBetweenEquallySpecificRulesTheLaterOneWins(): void
    {
        $sheet = self::sheet('.brand { fill: red; } .brand { fill: blue; }');

        self::assertSame('blue', self::applied($sheet, 'rect', ['class' => 'brand'])['fill']);
    }

    public function testRulesFromSeveralStyleBlocksAreOrderedAcrossTheDocument(): void
    {
        $sheet = SvgStylesheet::parse(new \SimpleXMLElement(
            '<svg><style>.brand { fill: red; }</style><g><style>.brand { fill: blue; }</style></g></svg>',
        ));

        self::assertSame('blue', self::applied($sheet, 'rect', ['class' => 'brand'])['fill']);
    }

    public function testDeclarationsFromRulesOfDifferentSpecificityAreCombined(): void
    {
        $sheet = self::sheet('rect { fill: grey; stroke: black; } .brand { fill: red; }');

        self::assertSame(
            ['fill' => 'red', 'stroke' => 'black'],
            self::applied($sheet, 'rect', ['class' => 'brand']),
        );
    }

    /**
     * The rules inside an @media block look exactly like ordinary ones
     * to a pattern that scans for "selector { declarations }", and a
     * drawing's print rules are usually the opposite of its screen
     * rules -- so reading them is worse than ignoring the block.
     */
    public function testAtMediaBlocksAreIgnoredWithEverythingInsideThem(): void
    {
        $sheet = self::sheet('.brand { fill: red; } @media print { .brand { fill: black; } }');

        self::assertSame('red', self::applied($sheet, 'rect', ['class' => 'brand'])['fill']);
    }

    public function testNestedAtRulesAreIgnoredWhole(): void
    {
        $sheet = self::sheet('@supports (fill: red) { @media print { .brand { fill: black; } } } .brand { fill: red; }');

        self::assertSame('red', self::applied($sheet, 'rect', ['class' => 'brand'])['fill']);
    }

    public function testAtStatementsWithNoBlockAreIgnored(): void
    {
        $sheet = self::sheet('@import url("other.css"); .brand { fill: red; }');

        self::assertSame('red', self::applied($sheet, 'rect', ['class' => 'brand'])['fill']);
    }

    public function testCommentsAreStripped(): void
    {
        $sheet = self::sheet('/* .brand { fill: black; } */ .brand /* here */ { fill: red; }');

        self::assertSame('red', self::applied($sheet, 'rect', ['class' => 'brand'])['fill']);
    }

    /**
     * Matching a descendant selector needs a view of the tree this does
     * not take, and a selector understood in part matches the wrong
     * elements -- so it matches nothing at all.
     */
    /**
     * Pseudo-classes ask about state and attribute selectors about
     * attributes this does not model. Both are dropped whole: a selector
     * understood in part matches the wrong elements confidently.
     */
    public function testSelectorsThisCannotMatchOnAreIgnored(): void
    {
        foreach (['rect:hover', 'rect[fill]', '.brand:nth-child(2)', 'g > > rect', '> rect'] as $selector) {
            self::assertSame(
                [],
                self::applied(self::sheet("$selector { fill: red; }"), 'rect', ['class' => 'brand']),
                $selector,
            );
        }
    }

    public function testTheRestOfTheSheetSurvivesASelectorItCannotMatchOn(): void
    {
        $sheet = self::sheet('.brand[data-x] { fill: black; } .brand { fill: red; }');

        self::assertSame('red', self::applied($sheet, 'rect', ['class' => 'brand'])['fill']);
    }

    public function testADescendantSelectorMatchesThroughAnyAncestor(): void
    {
        $sheet = self::sheet('g .brand { fill: red; }');

        $group = SvgElementPath::of('g', []);
        $inner = SvgElementPath::of('g', [], $group);
        $rect = SvgElementPath::of('rect', ['class' => 'brand'], $inner);

        self::assertSame(['fill' => 'red'], $sheet->declarationsFor($rect));
        self::assertSame([], $sheet->declarationsFor(SvgElementPath::of('rect', ['class' => 'brand'])));
    }

    public function testAChildSelectorMatchesOnlyTheImmediateParent(): void
    {
        $sheet = self::sheet('g > .brand { fill: red; }');

        $group = SvgElementPath::of('g', []);
        $child = SvgElementPath::of('rect', ['class' => 'brand'], $group);
        $grandchild = SvgElementPath::of('rect', ['class' => 'brand'], SvgElementPath::of('a', [], $group));

        self::assertSame(['fill' => 'red'], $sheet->declarationsFor($child));
        self::assertSame([], $sheet->declarationsFor($grandchild));
    }

    public function testTheAdjacentSiblingSelectorLooksAtTheElementJustBefore(): void
    {
        $sheet = self::sheet('rect + text { fill: red; }');

        $rect = SvgElementPath::of('rect', []);
        $circle = SvgElementPath::of('circle', [], null, [$rect]);

        self::assertSame(['fill' => 'red'], $sheet->declarationsFor(SvgElementPath::of('text', [], null, [$rect])));
        self::assertSame([], $sheet->declarationsFor(SvgElementPath::of('text', [], null, [$rect, $circle])));
    }

    public function testTheGeneralSiblingSelectorLooksAtEveryElementBefore(): void
    {
        $sheet = self::sheet('rect ~ text { fill: red; }');

        $rect = SvgElementPath::of('rect', []);
        $circle = SvgElementPath::of('circle', [], null, [$rect]);

        self::assertSame(['fill' => 'red'], $sheet->declarationsFor(SvgElementPath::of('text', [], null, [$rect, $circle])));
        self::assertSame([], $sheet->declarationsFor(SvgElementPath::of('text', [], null, [$circle])));
    }

    /**
     * A descendant combinator may have to try more than one ancestor:
     * the nearest one that matches on its own may fail the rest of the
     * selector while a further one satisfies all of it.
     */
    public function testAnAncestorThatFailsTheRestOfTheSelectorDoesNotEndTheSearch(): void
    {
        $sheet = self::sheet('#chart g .bar { fill: red; }');

        $chart = SvgElementPath::of('svg', ['id' => 'chart']);
        $outer = SvgElementPath::of('g', [], $chart);
        $inner = SvgElementPath::of('g', [], $outer);
        $bar = SvgElementPath::of('rect', ['class' => 'bar'], $inner);

        self::assertSame(['fill' => 'red'], $sheet->declarationsFor($bar));
    }

    /** Every compound in the selector counts towards its specificity. */
    public function testACombinatorSelectorOutweighsASimpleOne(): void
    {
        $sheet = self::sheet('.brand { fill: red; } g .brand { fill: green; }');

        $rect = SvgElementPath::of('rect', ['class' => 'brand'], SvgElementPath::of('g', []));

        self::assertSame('green', $sheet->declarationsFor($rect)['fill']);
    }

    public function testADocumentWithNoStyleBlocksHasNoRules(): void
    {
        self::assertTrue(SvgStylesheet::parse(new \SimpleXMLElement('<svg><rect/></svg>'))->isEmpty());
        self::assertTrue(SvgStylesheet::empty()->isEmpty());
    }

    private static function sheet(string $css): SvgStylesheet
    {
        return SvgStylesheet::parse(new \SimpleXMLElement('<svg><style>' . $css . '</style></svg>'));
    }

    /**
     * @param array<string, string> $attributes
     * @return array<string, string>
     */
    private static function applied(SvgStylesheet $sheet, string $tag, array $attributes = []): array
    {
        return $sheet->declarationsFor(SvgElementPath::of($tag, $attributes));
    }
}
