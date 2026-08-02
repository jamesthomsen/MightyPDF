<?php

declare(strict_types=1);

namespace MightyPDF\Tests\Content\Svg;

use MightyPDF\Content\Svg\SvgStylesheet;
use PHPUnit\Framework\TestCase;

final class SvgStylesheetTest extends TestCase
{
    public function testAClassSelectorAppliesToElementsCarryingTheClass(): void
    {
        $sheet = self::sheet('.brand { fill: #ff0000; }');

        self::assertSame(['fill' => '#ff0000'], $sheet->declarationsFor('rect', ['class' => 'brand']));
        self::assertSame([], $sheet->declarationsFor('rect', []));
    }

    public function testOneOfSeveralClassesIsEnough(): void
    {
        $sheet = self::sheet('.brand { fill: #ff0000; }');

        self::assertSame(['fill' => '#ff0000'], $sheet->declarationsFor('rect', ['class' => 'shadow brand wide']));
    }

    public function testASelectorMayRequireSeveralClassesAtOnce(): void
    {
        $sheet = self::sheet('.a.b { fill: red; }');

        self::assertSame(['fill' => 'red'], $sheet->declarationsFor('rect', ['class' => 'a b']));
        self::assertSame([], $sheet->declarationsFor('rect', ['class' => 'a']));
    }

    public function testTypeAndIdSelectors(): void
    {
        $sheet = self::sheet('rect { fill: grey; } #hero { fill: green; }');

        self::assertSame(['fill' => 'grey'], $sheet->declarationsFor('rect', []));
        self::assertSame([], $sheet->declarationsFor('circle', []));
        self::assertSame(['fill' => 'green'], $sheet->declarationsFor('circle', ['id' => 'hero']));
    }

    public function testTheUniversalSelectorAppliesToEverything(): void
    {
        self::assertSame(['fill' => 'red'], self::sheet('* { fill: red; }')->declarationsFor('path', []));
    }

    public function testAGroupOfSelectorsSharesOneBlock(): void
    {
        $sheet = self::sheet('.a, .b { fill: red; }');

        self::assertSame(['fill' => 'red'], $sheet->declarationsFor('rect', ['class' => 'a']));
        self::assertSame(['fill' => 'red'], $sheet->declarationsFor('rect', ['class' => 'b']));
    }

    /** An id beats any number of classes, and a class beats an element name. */
    public function testTheMoreSpecificSelectorWins(): void
    {
        $sheet = self::sheet('#hero { fill: green; } .brand { fill: red; } rect { fill: grey; }');

        $declarations = $sheet->declarationsFor('rect', ['id' => 'hero', 'class' => 'brand']);

        self::assertSame('green', $declarations['fill']);
    }

    public function testATypeAndClassTogetherBeatTheClassAlone(): void
    {
        $sheet = self::sheet('.brand { fill: red; } rect.brand { fill: orange; }');

        self::assertSame('orange', $sheet->declarationsFor('rect', ['class' => 'brand'])['fill']);
        self::assertSame('red', $sheet->declarationsFor('circle', ['class' => 'brand'])['fill']);
    }

    public function testBetweenEquallySpecificRulesTheLaterOneWins(): void
    {
        $sheet = self::sheet('.brand { fill: red; } .brand { fill: blue; }');

        self::assertSame('blue', $sheet->declarationsFor('rect', ['class' => 'brand'])['fill']);
    }

    public function testRulesFromSeveralStyleBlocksAreOrderedAcrossTheDocument(): void
    {
        $sheet = SvgStylesheet::parse(new \SimpleXMLElement(
            '<svg><style>.brand { fill: red; }</style><g><style>.brand { fill: blue; }</style></g></svg>',
        ));

        self::assertSame('blue', $sheet->declarationsFor('rect', ['class' => 'brand'])['fill']);
    }

    public function testDeclarationsFromRulesOfDifferentSpecificityAreCombined(): void
    {
        $sheet = self::sheet('rect { fill: grey; stroke: black; } .brand { fill: red; }');

        self::assertSame(
            ['fill' => 'red', 'stroke' => 'black'],
            $sheet->declarationsFor('rect', ['class' => 'brand']),
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

        self::assertSame('red', $sheet->declarationsFor('rect', ['class' => 'brand'])['fill']);
    }

    public function testNestedAtRulesAreIgnoredWhole(): void
    {
        $sheet = self::sheet('@supports (fill: red) { @media print { .brand { fill: black; } } } .brand { fill: red; }');

        self::assertSame('red', $sheet->declarationsFor('rect', ['class' => 'brand'])['fill']);
    }

    public function testAtStatementsWithNoBlockAreIgnored(): void
    {
        $sheet = self::sheet('@import url("other.css"); .brand { fill: red; }');

        self::assertSame('red', $sheet->declarationsFor('rect', ['class' => 'brand'])['fill']);
    }

    public function testCommentsAreStripped(): void
    {
        $sheet = self::sheet('/* .brand { fill: black; } */ .brand /* here */ { fill: red; }');

        self::assertSame('red', $sheet->declarationsFor('rect', ['class' => 'brand'])['fill']);
    }

    /**
     * Matching a descendant selector needs a view of the tree this does
     * not take, and a selector understood in part matches the wrong
     * elements -- so it matches nothing at all.
     */
    public function testSelectorsThisCannotMatchOnAreIgnored(): void
    {
        foreach (['g .brand', 'g > rect', 'a + b', 'rect:hover', 'rect[fill]'] as $selector) {
            self::assertSame(
                [],
                self::sheet("$selector { fill: red; }")->declarationsFor('rect', ['class' => 'brand']),
                $selector,
            );
        }
    }

    public function testTheRestOfTheSheetSurvivesASelectorItCannotMatchOn(): void
    {
        $sheet = self::sheet('g .brand { fill: black; } .brand { fill: red; }');

        self::assertSame('red', $sheet->declarationsFor('rect', ['class' => 'brand'])['fill']);
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
}
