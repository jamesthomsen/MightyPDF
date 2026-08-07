<?php

declare(strict_types=1);

/**
 * A document specified the way a press wants it: process colour in
 * CMYK, a brand colour as a named ink with its own plate, and a dieline
 * on a separation of its own.
 *
 * On screen this looks much like the RGB version. The difference is in
 * the file: the four numbers go through untouched rather than being
 * converted, and the two named inks are /Separation colour spaces that a
 * RIP will output as their own plates.
 */

require __DIR__ . '/../vendor/autoload.php';

use MightyPDF\Assembler\Document;
use MightyPDF\Assembler\PageSize;
use MightyPDF\Assembler\PrintScaling;
use MightyPDF\Content\CmykColor;
use MightyPDF\Content\Color;
use MightyPDF\Content\Dash;
use MightyPDF\Content\Font\StandardFont;
use MightyPDF\Content\SpotColor;
use MightyPDF\Content\Stroke;
use MightyPDF\Content\Text\HorizontalAlign;
use MightyPDF\Layout\Border;
use MightyPDF\Layout\Flow;
use MightyPDF\Layout\Margins;
use MightyPDF\Layout\Style;
use MightyPDF\Layout\Unit;

$document = new Document();

$flow = new Flow($document, PageSize::A4, new Margins(20.0, 18.0, 20.0, 18.0), Unit::Millimetres);

// A named ink, with the CMYK a device without it should use instead.
$brand = SpotColor::named('PANTONE 300 C', CmykColor::fromPercentages(100, 44, 0, 0));

// A separation for the cut line, which is not a colour at all: it marks
// where the finished piece is trimmed, and prints on its own plate.
$dieline = SpotColor::named('Dieline', CmykColor::fromPercentages(0, 100, 100, 0));

$plainBlack = CmykColor::black();
$richBlack = CmykColor::richBlack();

$flow->paragraph(
    $flow->contentWidth(),
    'Print specification',
    new Style(StandardFont::HelveticaBold, 20.0, $brand, paddingPt: 0.0),
);
$flow->newLine(6.0);

// -- Two blacks --------------------------------------------------------

$flow->paragraph(
    $flow->contentWidth(),
    'Both panels below are #000000 in RGB. On a press they are not the same colour: '
    . 'the second lays three more inks under the black so it does not read as grey '
    . 'beside a photograph. A library holding only RGB cannot carry the distinction.',
    new Style(StandardFont::Helvetica, 9.0, Color::gray(0.25), paddingPt: 0.0),
);
$flow->newLine(4.0);

$label = new Style(StandardFont::Helvetica, 8.0, Color::white(), align: HorizontalAlign::Center);

$flow->rect(18.0, $flow->y(), 82.0, 26.0, $plainBlack);
$flow->cellAt(18.0, $flow->y() + 9.0, 82.0, 8.0, 'K100 — plain black', $label);

$flow->rect(110.0, $flow->y(), 82.0, 26.0, $richBlack);
$flow->cellAt(110.0, $flow->y() + 9.0, 82.0, 8.0, 'C60 M40 Y40 K100 — rich black', $label);

$flow->newLine(34.0);

// -- Tints of one ink ---------------------------------------------------

$flow->paragraph(
    $flow->contentWidth(),
    'One ink at five tints. Every one of these is the same plate — the tint is an '
    . 'operand of the paint operator, not a colour space of its own, so the page '
    . 'declares a single /Separation for all five.',
    new Style(StandardFont::Helvetica, 9.0, Color::gray(0.25), paddingPt: 0.0),
);
$flow->newLine(4.0);

$top = $flow->y();

foreach ([1.0, 0.75, 0.5, 0.25, 0.1] as $index => $tint) {
    $x = 18.0 + $index * 35.0;

    $flow->rect($x, $top, 30.0, 22.0, $brand->withTint($tint));
    $flow->cellAt(
        $x,
        $top + 23.0,
        30.0,
        6.0,
        number_format($tint * 100) . '%',
        new Style(StandardFont::Helvetica, 8.0, Color::gray(0.3), align: HorizontalAlign::Center),
    );
}

$flow->newLine(38.0);

// -- A dieline ----------------------------------------------------------

$flow->paragraph(
    $flow->contentWidth(),
    'A cut line on a separation of its own, dashed so a person can see it and named '
    . 'so a finishing machine can find it.',
    new Style(StandardFont::Helvetica, 9.0, Color::gray(0.25), paddingPt: 0.0),
);
$flow->newLine(4.0);

$cardTop = $flow->y();

$flow->roundedRect(18.0, $cardTop, 85.0, 54.0, 3.0, fill: $brand->withTint(0.08));

$flow->cellAt(
    24.0,
    $cardTop + 18.0,
    73.0,
    10.0,
    'MightyPDF',
    new Style(StandardFont::HelveticaBold, 14.0, $brand),
);

$flow->cellAt(
    24.0,
    $cardTop + 28.0,
    73.0,
    6.0,
    'A PDF library for PHP',
    new Style(StandardFont::Helvetica, 8.0, $plainBlack),
);

$flow->roundedRect(
    18.0,
    $cardTop,
    85.0,
    54.0,
    3.0,
    stroke: new Stroke($dieline, 0.5, Dash::dashed(4.0)),
);

$flow->cellAt(
    108.0,
    $cardTop + 22.0,
    80.0,
    8.0,
    'Trim here — plate "Dieline"',
    new Style(StandardFont::Helvetica, 8.0, Color::gray(0.4)),
);

// Anything measured should say so: without this a reader shrinks the page
// by a few percent to clear its printer's margin, and the card comes out
// the wrong size.
$document->viewerPreferences()->printScaling(PrintScaling::None);

$flow->saveToFile(__DIR__ . '/output/20-print-colours.pdf');

echo "Wrote output/20-print-colours.pdf\n";
