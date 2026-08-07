<?php

declare(strict_types=1);

/**
 * The general drawing primitives: arbitrary paths, ellipses, rounded
 * rectangles and regular polygons, dashes and caps and joins gathered
 * into a Stroke, and the three scoped graphics states -- a transform, a
 * clip and an alpha -- each of which puts the page back as it found it.
 *
 * The scoping is the point. Every one of these takes a closure, so there
 * is no way to leave a rotation or a clip in effect by forgetting to
 * close it -- which is what a paired start/stop pair of calls invites.
 */

require __DIR__ . '/../vendor/autoload.php';

use MightyPDF\Assembler\Document;
use MightyPDF\Assembler\PageSize;
use MightyPDF\Content\Color;
use MightyPDF\Content\Dash;
use MightyPDF\Content\Font\StandardFont;
use MightyPDF\Content\LineCap;
use MightyPDF\Content\PageBuilder;
use MightyPDF\Content\PathSink;
use MightyPDF\Content\Stroke;
use MightyPDF\Content\Text\HorizontalAlign;

$document = new Document();
$page = $document->newPage(PageSize::A4);
$content = new PageBuilder($document, $page);

$ink = Color::fromHex('#1f2933');
$blue = Color::fromHex('#2563eb');
$amber = Color::fromHex('#f59e0b');
$rose = Color::fromHex('#e11d48');

$label = static function (string $text, float $x, float $y) use ($content, $ink): void {
    $content->drawTextInBox(StandardFont::Helvetica, 8.0, $x, $y, 150, 12, $text, color: $ink);
};

$content->drawText(StandardFont::HelveticaBold, 20.0, 57, 770, 'Shapes, transforms, transparency', ...$ink->rgb());

// -- Shapes ------------------------------------------------------------

$label('Ellipse, circle, rounded rectangle', 57, 725);

$content->drawEllipse(100, 680, 42, 24, $blue)
    ->drawCircle(210, 680, 24, fill: null, stroke: new Stroke($rose, 2.0))
    ->drawRoundedRectangle(255, 656, 90, 48, 12, Color::fromHex('#e0e7ff'), Stroke::hairline($blue));

$label('Regular polygons -- 3, 5 and 8 sides, a vertex at the top', 57, 620);

foreach ([3, 5, 8] as $index => $sides) {
    $content->drawRegularPolygon(100 + $index * 90, 560, 30, $sides, $amber, Stroke::hairline($ink));
}

// -- Strokes -----------------------------------------------------------

$label('Solid, dashed, dotted and dash-dot -- dots need a round cap to show at all', 57, 505);

$strokes = [
    new Stroke($ink, 1.5),
    Stroke::dashed(1.5, 6.0, $ink),
    Stroke::dotted(2.5, 5.0, $ink),
    new Stroke($ink, 1.5, Dash::dashDot(9.0, 3.0), LineCap::Round),
];

foreach ($strokes as $index => $stroke) {
    $content->drawPolyline([[70.0, 480.0 - $index * 14], [400.0, 480.0 - $index * 14]], $stroke);
}

// -- An arbitrary path -------------------------------------------------

$label('An arbitrary path: lines and cubic curves, filled and stroked', 57, 405);

$content->drawPath(
    static fn (PathSink $path) => $path->moveTo(70, 330)
        ->curveTo(130, 400, 190, 300, 250, 360)
        ->curveTo(310, 420, 370, 320, 430, 370)
        ->lineTo(430, 330)
        ->lineTo(70, 330)
        ->closePath(),
    fill: Color::fromHex('#dbeafe'),
    stroke: new Stroke($blue, 1.2),
);

// -- Scoped state ------------------------------------------------------

$label('Rotation about a point, and text turned about its own baseline', 57, 285);

// The fan: the same square drawn twelve times, each under its own
// rotation about the middle of the group. Nothing is left rotated
// afterwards, so the label below sits square on the page.
for ($i = 0; $i < 12; ++$i) {
    $content->rotated(
        $i * 30.0,
        140.0,
        200.0,
        static function (PageBuilder $content) use ($blue): void {
            $content->drawRectangle(180, 194, 40, 12, $blue);
        },
    );
}

$content->drawTextRotated(StandardFont::Helvetica, 9.0, 300, 160, 90.0, 'Turned 90 degrees', $ink);
$content->drawTextRotated(StandardFont::Helvetica, 9.0, 320, 160, 45.0, 'and 45', $ink);

// -- Clipping and alpha -------------------------------------------------

$label('Clipped to a box, and drawn at 25% opacity', 380, 285);

$content->clippedToRectangle(
    380,
    170,
    150,
    70,
    static function (PageBuilder $content) use ($amber, $rose): void {
        // Both circles overflow the box; only what is inside it is drawn.
        $content->drawCircle(400, 205, 60, $amber)
            ->drawCircle(500, 205, 60, $rose);
    },
);

$content->drawRectangle(380, 170, 150, 70, stroke: Stroke::hairline($ink));

$content->faded(0.25, static function (PageBuilder $content) use ($rose): void {
    $content->drawRegularPolygon(455, 100, 45, 6, $rose);
});

$content->drawTextInBox(
    StandardFont::Helvetica,
    8.0,
    380,
    45,
    150,
    12,
    'Everything above is back to normal',
    HorizontalAlign::Center,
    color: $ink,
);

$document->saveToFile(__DIR__ . '/output/17-shapes-transforms-and-transparency.pdf');

echo "Wrote output/17-shapes-transforms-and-transparency.pdf\n";
