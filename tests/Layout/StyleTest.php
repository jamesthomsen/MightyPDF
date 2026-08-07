<?php

declare(strict_types=1);

namespace MightyPDF\Tests\Layout;

use MightyPDF\Content\Color;
use MightyPDF\Content\Font\StandardFont;
use MightyPDF\Content\Text\HorizontalAlign;
use MightyPDF\Content\Text\VerticalAlign;
use MightyPDF\Layout\Border;
use MightyPDF\Layout\Style;
use PHPUnit\Framework\TestCase;

final class StyleTest extends TestCase
{
    public function testWithChangesOnlyWhatItIsGiven(): void
    {
        $base = new Style(StandardFont::TimesBold, 12.0, Color::fromHex('#333'), paddingPt: 2.0);
        $variant = $base->with(align: HorizontalAlign::Right);

        self::assertSame(HorizontalAlign::Right, $variant->align);
        self::assertSame(StandardFont::TimesBold, $variant->font);
        self::assertSame(12.0, $variant->sizePt);
        self::assertSame(2.0, $variant->paddingPt);
        self::assertTrue($variant->color->equals($base->color));
    }

    public function testWithLeavesTheOriginalAlone(): void
    {
        $base = new Style(align: HorizontalAlign::Left);
        $base->with(align: HorizontalAlign::Center);

        self::assertSame(HorizontalAlign::Left, $base->align);
    }

    /**
     * The zebra stripe, which is the case a positional-argument cell
     * signature handles worst: one property differs and the other seven
     * must not drift.
     */
    public function testAStripeIsTheBaseStylePlusAFill(): void
    {
        $row = new Style(StandardFont::Helvetica, 9.0, border: Border::bottom());
        $stripe = $row->with(fill: Color::gray(0.95));

        self::assertNull($row->fill);
        self::assertNotNull($stripe->fill);
        self::assertSame($row->border, $stripe->border);
        self::assertSame(9.0, $stripe->sizePt);
    }

    /**
     * null means "leave it alone" for the other seven properties, so it
     * cannot also mean "no fill" -- hence a method that says so.
     */
    public function testWithoutFillIsHowAFillIsRemoved(): void
    {
        $filled = new Style(fill: Color::black());

        self::assertNotNull($filled->with(fill: null)->fill, 'null means unchanged, not "no fill"');
        self::assertNull($filled->withoutFill()->fill);
    }

    public function testDefaultsAreABodyTextStyle(): void
    {
        $style = new Style();

        self::assertSame(StandardFont::Helvetica, $style->font);
        self::assertSame(10.0, $style->sizePt);
        self::assertSame(HorizontalAlign::Left, $style->align);
        self::assertSame(VerticalAlign::Middle, $style->valign);
        self::assertNull($style->fill);
        self::assertTrue($style->border->isEmpty());
    }

    /** Kept in step with PageBuilder::drawParagraph()'s own default. */
    public function testTheDefaultLineHeightIs115PercentOfTheTypeSize(): void
    {
        self::assertEqualsWithDelta(13.8, (new Style(sizePt: 12.0))->lineHeightPt(), 1e-9);
    }
}
