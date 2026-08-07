<?php

declare(strict_types=1);

/**
 * A business report through the layout layer: a grade placard, a
 * bordered and zebra-striped table that breaks across pages by itself, a
 * chart drawn through the primitives in the same coordinate space, and a
 * legal disclaimer on every page including the ones the break created.
 *
 * The same document through PageBuilder alone is perfectly possible --
 * that is all this is underneath -- but every cell would be a fill, four
 * rules, a width measurement and a piece of vertical-centring
 * arithmetic, and the disclaimer would depend on each drawing function
 * remembering to place one.
 */

require __DIR__ . '/../vendor/autoload.php';

use MightyPDF\Assembler\Document;
use MightyPDF\Assembler\PageSize;
use MightyPDF\Content\Color;
use MightyPDF\Content\Font\StandardFont;
use MightyPDF\Content\Text\HorizontalAlign;
use MightyPDF\Content\Text\VerticalAlign;
use MightyPDF\Layout\Border;
use MightyPDF\Layout\Flow;
use MightyPDF\Layout\Margins;
use MightyPDF\Layout\Style;
use MightyPDF\Layout\Unit;

$ink = Color::fromHex('#1f2933');
$muted = Color::fromHex('#6b7280');
$navy = Color::fromHex('#334155');
$stripe = Color::fromRgb255(244, 246, 248);

$flow = new Flow(
    new Document(),
    PageSize::A4,
    new Margins(18.0, 15.0, 20.0, 15.0),
    Unit::Millimetres,
);

$title = new Style(StandardFont::HelveticaBold, 20.0, $ink, valign: VerticalAlign::CapMiddle);
$body = new Style(StandardFont::Helvetica, 9.5, $ink, paddingPt: 4.0);
$heading = $body->with(font: StandardFont::HelveticaBold, color: Color::white(), fill: $navy, border: Border::box(0.3));
$row = $body->with(border: Border::bottom(0.1, Color::gray(0.8)));

// Registered before anything is drawn, but run at finish() -- which is
// why "of $total" is the real count rather than a placeholder patched in
// afterwards. Every page gets one, including pages the table's automatic
// break added while it was running.
$flow->onEachPage(function (Flow $flow, int $page, int $total) use ($muted): void {
    $footer = new Style(StandardFont::Helvetica, 7.0, $muted, align: HorizontalAlign::Center);

    $flow->line(15.0, 281.0, 195.0, 281.0, 0.2, Color::gray(0.85));
    $flow->cellAt(15.0, 283.0, 180.0, 5.0, "Illustrative only -- not legal advice. Page $page of $total.", $footer);
});

// -- The grade placard ------------------------------------------------
//
// A display-sized letter is where a centring error stops being
// invisible: CapMiddle centres the capital itself, which is what the eye
// reads as centred. Middle would sit it half a descent high -- 14pt at
// this size, on the first thing anyone looks at.
$flow->cell($flow->contentWidth(), 14.0, 'Compliance Scorecard', $title);
$flow->newLine(20.0);

$flow->cellAt(
    75.0,
    40.0,
    60.0,
    60.0,
    'B',
    new Style(
        StandardFont::HelveticaBold,
        135.0,
        Color::white(),
        $navy,
        Border::box(0.6, $navy),
        HorizontalAlign::Center,
        VerticalAlign::CapMiddle,
    ),
);

$flow->moveTo(15.0, 108.0);
$flow->paragraph(
    $flow->contentWidth(),
    'Overall standing across 42 monitored controls for the period ending 30 June 2026. '
    . 'Controls are re-tested quarterly; a failure recorded here is remediated before the next cycle.',
    $body->with(color: $muted, align: HorizontalAlign::Justify),
);

$flow->newLine(6.0);

// -- The table --------------------------------------------------------

$columns = [90.0, 45.0, 45.0];
$headings = ['Control', 'Owner', 'Status'];

foreach ($headings as $index => $text) {
    $flow->cell($columns[$index], 7.0, $text, $heading->with(
        align: $index === 2 ? HorizontalAlign::Right : HorizontalAlign::Left,
    ));
}

$flow->newLine(7.0);

for ($i = 1; $i <= 42; $i++) {
    // The stripe is the row style plus a fill -- one property, seven
    // held steady, which is the case a positional cell signature loses.
    $style = $i % 2 === 0 ? $row->with(fill: $stripe) : $row;

    $flow->cell($columns[0], 6.0, "Access review procedure $i", $style);
    $flow->cell($columns[1], 6.0, ['Security', 'Finance', 'Operations'][$i % 3], $style);
    $flow->cell($columns[2], 6.0, $i % 7 === 0 ? 'Remediating' : 'Pass', $style->with(align: HorizontalAlign::Right));
    $flow->newLine(6.0);
}

// -- Something the layer knows nothing about --------------------------
//
// A chart is drawn through the primitives, but in the same millimetre
// space as everything else -- and breakIfNeeded() lets it take part in
// the page-break decision rather than reimplementing it.
$flow->newLine(8.0);
$flow->breakIfNeeded(60.0);

$flow->cell($flow->contentWidth(), 8.0, 'Trailing twelve months', $body->with(font: StandardFont::HelveticaBold));
$flow->newLine(10.0);

$top = $flow->y();
$left = 20.0;
$width = 170.0;
$height = 45.0;

$flow->rect($left, $top, $width, $height, null, Border::box(0.2, Color::gray(0.8)));

$scores = [61, 64, 63, 70, 72, 71, 76, 80, 79, 84, 86, 88];
$step = $width / (count($scores) - 1);

foreach ($scores as $index => $score) {
    if ($index === 0) {
        continue;
    }

    $flow->line(
        $left + ($index - 1) * $step,
        $top + $height - ($scores[$index - 1] / 100) * $height,
        $left + $index * $step,
        $top + $height - ($score / 100) * $height,
        1.2,
        $navy,
    );
}

$flow->moveTo(15.0, $top + $height + 4.0);
$flow->paragraph(
    $flow->contentWidth(),
    'Scores are the weighted mean of all controls in force that month.',
    $body->with(sizePt: 8.0, color: $muted),
);

$flow->saveToFile(__DIR__ . '/output/16-a-report-with-the-layout-layer.pdf');

echo "Wrote a {$flow->pageCount()}-page report.\n";
