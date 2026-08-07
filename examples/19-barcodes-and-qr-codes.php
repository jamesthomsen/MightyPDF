<?php

declare(strict_types=1);

/**
 * The four linear symbologies and the 2D one, each with the
 * human-readable line underneath that a real label carries -- which is
 * the caller's to draw, since the barcode primitives draw bars and
 * nothing else.
 *
 * Note the quiet zones. Every symbol here reserves its own clear space
 * inside the box it was given, which is what quietZone: true does; a
 * barcode printed hard against other content does not scan, and that is
 * invisible on the page.
 */

require __DIR__ . '/../vendor/autoload.php';

use MightyPDF\Assembler\Document;
use MightyPDF\Assembler\PageSize;
use MightyPDF\Content\Barcode\Ean13;
use MightyPDF\Content\Barcode\QrEccLevel;
use MightyPDF\Content\Barcode\Symbology;
use MightyPDF\Content\Color;
use MightyPDF\Content\Font\StandardFont;
use MightyPDF\Content\Text\HorizontalAlign;
use MightyPDF\Layout\Flow;
use MightyPDF\Layout\Margins;
use MightyPDF\Layout\Style;
use MightyPDF\Layout\Unit;

$ink = Color::fromHex('#1f2933');

$flow = new Flow(
    new Document(),
    PageSize::A4,
    new Margins(18.0, 15.0, 20.0, 15.0),
    Unit::Millimetres,
);

$caption = new Style(StandardFont::Helvetica, 8.0, $ink, align: HorizontalAlign::Center);
$note = new Style(StandardFont::Helvetica, 8.0, Color::gray(0.4));

$flow->paragraph($flow->contentWidth(), 'Barcodes', new Style(StandardFont::HelveticaBold, 18.0, $ink, paddingPt: 0.0));
$flow->newLine(6.0);

// -- Linear ------------------------------------------------------------

$symbols = [
    ['MIGHTYPDF-2026', Symbology::Code39, 'Code 39 — 43 characters, and verbose with it'],
    ['MightyPDF v2.0.0', Symbology::Code128, 'Code 128 — the whole of ASCII, at two-thirds the width'],
    ['4006381333931', Symbology::Ean13, 'EAN-13 — retail, thirteen digits, check digit computed'],
    ['03600029145', Symbology::UpcA, 'UPC-A — the same symbol with a leading zero'],
];

$y = 32.0;

foreach ($symbols as [$value, $symbology, $description]) {
    $flow->cellAt(15.0, $y, 180.0, 5.0, $description, $note);

    $flow->barcode($value, 15.0, $y + 6.0, 90.0, 16.0, $symbology, quietZone: true);

    // The human-readable line. EAN-13 and UPC-A print the full thirteen
    // digits including the check digit the library worked out, which is
    // why it is asked for rather than restated.
    $printed = match ($symbology) {
        Symbology::Ean13 => Ean13::normalize($value),
        Symbology::UpcA => Ean13::normalize('0' . $value),
        default => $value,
    };

    $flow->cellAt(15.0, $y + 23.0, 90.0, 5.0, $printed, $caption);

    $y += 34.0;
}

// -- QR ----------------------------------------------------------------

$flow->cellAt(15.0, $y + 4.0, 180.0, 5.0, 'QR — a few thousand characters, readable at any rotation', $note);

$y += 12.0;

$codes = [
    ['https://github.com/jamesthomsen/mightypdf', QrEccLevel::Low, 'Level L'],
    ['https://github.com/jamesthomsen/mightypdf', QrEccLevel::Medium, 'Level M'],
    ['https://github.com/jamesthomsen/mightypdf', QrEccLevel::Quartile, 'Level Q'],
    ['https://github.com/jamesthomsen/mightypdf', QrEccLevel::High, 'Level H'],
];

foreach ($codes as $index => [$value, $level, $label]) {
    $x = 15.0 + $index * 46.0;

    $flow->qrCode($value, $x, $y, 38.0, $level);
    $flow->cellAt($x, $y + 39.0, 38.0, 5.0, $label, $caption);
}

$y += 50.0;

// A payment string, which is what most printed QR codes actually are.
$flow->cellAt(15.0, $y, 180.0, 5.0, 'A SEPA credit transfer, at level M', $note);

$flow->qrCode(
    implode("\n", [
        'BCD', '002', '1', 'SCT', 'BANKDEFFXXX',
        'MightyPDF Ltd', 'DE71110220330123456789', 'EUR248.50', '', '', 'Invoice 2026-0417',
    ]),
    15.0,
    $y + 6.0,
    38.0,
);

// cellAt() leaves the cursor alone, so it is put where this paragraph
// should start rather than left where the title finished.
$flow->moveTo(60.0, $y + 6.0);

$flow->paragraph(
    120.0,
    'The module count follows the data and the error-correction level, so a longer '
    . 'string in the same box comes out denser. Pass minVersion to pin the density '
    . 'across a run of labels.',
    new Style(StandardFont::Helvetica, 8.0, $ink, paddingPt: 0.0),
);

$flow->saveToFile(__DIR__ . '/output/19-barcodes-and-qr-codes.pdf');

echo "Wrote output/19-barcodes-and-qr-codes.pdf\n";
