<?php

declare(strict_types=1);

namespace MightyPDF\Tests\Content\Svg;

use MightyPDF\Assembler\Dictionary;
use MightyPDF\Content\Svg\SvgPattern;
use MightyPDF\Content\Svg\SvgPatternParser;
use MightyPDF\Content\Svg\SvgTilingPattern;
use MightyPDF\Content\Svg\SvgTransform;
use PHPUnit\Framework\TestCase;

final class SvgPatternTest extends TestCase
{
    private const array UNIT_BOX = [0.0, 0.0, 1.0, 1.0];

    public function testCollectsPatternsByIdWithTheirTile(): void
    {
        $patterns = self::parse(
            '<pattern id="dots" x="1" y="2" width="20" height="30" patternUnits="userSpaceOnUse"><circle r="5"/></pattern>',
        );

        self::assertArrayHasKey('dots', $patterns);
        self::assertSame([1.0, 2.0, 20.0, 30.0], $patterns['dots']->tile(self::UNIT_BOX));
        self::assertTrue($patterns['dots']->userSpace);
    }

    /**
     * The two units attributes default in opposite directions: the tile
     * is a fraction of the shape's box, and what is drawn in it is not.
     * Getting them the same way round gives a tile of the right size
     * holding a drawing scaled by the size of the shape.
     */
    public function testTheTileAndItsContentsDefaultToDifferentUnits(): void
    {
        $pattern = self::parse('<pattern id="p" width="0.25" height="0.5"><rect width="1" height="1"/></pattern>')['p'];

        self::assertFalse($pattern->userSpace);
        self::assertTrue($pattern->contentInUserSpace);

        // A quarter by a half of a 40x80 box at (10, 20).
        self::assertSame([10.0, 20.0, 10.0, 40.0], $pattern->tile([10.0, 20.0, 40.0, 80.0]));
        self::assertNull($pattern->contentMatrix([10.0, 20.0, 40.0, 80.0]));
    }

    public function testContentInBoundingBoxUnitsIsScaledToTheShape(): void
    {
        $pattern = self::parse(
            '<pattern id="p" width="0.5" height="0.5" patternContentUnits="objectBoundingBox"><rect width="1" height="1"/></pattern>',
        )['p'];

        self::assertSame([40.0, 0.0, 0.0, 80.0, 10.0, 20.0], $pattern->contentMatrix([10.0, 20.0, 40.0, 80.0]));
    }

    public function testAViewBoxFitsTheContentToTheTile(): void
    {
        $pattern = self::parse(
            '<pattern id="p" x="0" y="0" width="20" height="20" patternUnits="userSpaceOnUse" viewBox="0 0 10 10"><rect width="10" height="10"/></pattern>',
        )['p'];

        // 10 units of content across 20 of tile: scaled by two, from the
        // tile's own corner.
        self::assertSame([2.0, 0.0, 0.0, 2.0, 0.0, 0.0], $pattern->contentMatrix(self::UNIT_BOX));
    }

    /**
     * A pattern with no content of its own draws the content of the one
     * it references -- and may still overrule its tile.
     */
    public function testAPatternInheritsContentThroughHref(): void
    {
        $patterns = self::parse(
            '<pattern id="base" width="10" height="10" patternUnits="userSpaceOnUse"><circle r="4"/></pattern>'
            . '<pattern id="wider" href="#base" width="30"/>',
        );

        self::assertSame('circle', $patterns['wider']->content->children()[0]->getName());
        self::assertSame([0.0, 0.0, 30.0, 10.0], $patterns['wider']->tile(self::UNIT_BOX));
    }

    public function testAPatternWithNoContentAnywhereIsNotCollected(): void
    {
        self::assertSame([], self::parse('<pattern id="empty" width="10" height="10"/>'));
    }

    /**
     * A tile with no area repeats nothing, and one measured against a
     * shape with no area has no size to be given.
     */
    public function testAPatternWithNothingToRepeatPaintsNothing(): void
    {
        $sized = self::parse('<pattern id="p" width="0.5" height="0.5"><rect width="1" height="1"/></pattern>')['p'];

        self::assertTrue($sized->canPaint([0.0, 0.0, 10.0, 10.0]));
        self::assertFalse($sized->canPaint([0.0, 0.0, 10.0, 0.0]));

        $unsized = self::parse('<pattern id="p" width="0" height="4"><rect width="1" height="1"/></pattern>')['p'];
        self::assertFalse($unsized->canPaint([0.0, 0.0, 10.0, 10.0]));
    }

    /**
     * The tile rectangle is the /BBox, in the shape's own coordinates,
     * and the steps are its size: SVG's model of "draw the content, show
     * what lands in the tile, repeat" is PDF's model exactly.
     */
    public function testTheTileBecomesTheBBoxAndTheSteps(): void
    {
        $pattern = self::parse(
            '<pattern id="p" x="5" y="7" width="20" height="30" patternUnits="userSpaceOnUse"><rect width="1" height="1"/></pattern>',
        )['p'];

        $tiling = SvgTilingPattern::build(1, $pattern, 're', new Dictionary(), SvgTransform::IDENTITY, self::UNIT_BOX);

        self::assertSame('1', $tiling->get('PatternType')?->format());
        self::assertSame('[5 7 25 37]', $tiling->get('BBox')?->format());
        self::assertSame('20', $tiling->get('XStep')?->format());
        self::assertSame('30', $tiling->get('YStep')?->format());
    }

    /**
     * Pattern space is measured from the page, not from the transform in
     * effect where the shape is drawn, so everything the shape has been
     * transformed by is folded into the pattern's own matrix -- the same
     * problem, and the same answer, as a gradient's.
     */
    public function testThePatternsMatrixCarriesTheShapesTransformAndItsOwn(): void
    {
        $pattern = self::parse(
            '<pattern id="p" width="10" height="10" patternUnits="userSpaceOnUse" patternTransform="translate(3 4)"><rect width="1" height="1"/></pattern>',
        )['p'];

        $placement = [2.0, 0.0, 0.0, -2.0, 100.0, 700.0];
        $tiling = SvgTilingPattern::build(1, $pattern, 're', new Dictionary(), $placement, self::UNIT_BOX);

        // translate(3, 4) under the placement: scaled by two, y flipped.
        self::assertSame('[2 0 0 -2 106 692]', $tiling->get('Matrix')?->format());
    }

    /** @return array<string, SvgPattern> */
    private static function parse(string $defs): array
    {
        $svg = simplexml_load_string(
            '<svg xmlns="http://www.w3.org/2000/svg" xmlns:xlink="http://www.w3.org/1999/xlink" viewBox="0 0 100 100"><defs>'
            . $defs . '</defs></svg>',
        );

        self::assertNotFalse($svg);

        return SvgPatternParser::collect($svg);
    }
}
