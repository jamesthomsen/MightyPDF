<?php

declare(strict_types=1);

/**
 * A page laid out for a press rather than for a screen.
 *
 * Three things separate the two:
 *
 * - **The sheet is bigger than the finished piece.** A guillotine is not
 *   precise, so artwork meant to reach the edge is printed past it and
 *   cut through. PageSize::withBleed() sizes the sheet; Flow::setBleed()
 *   says how much of it is bleed.
 * - **The file says where to cut.** /TrimBox is the finished page and
 *   /BleedBox is how far the ink runs. A shop's preflight check looks
 *   for exactly that pair, and a file with neither is one where nothing
 *   in the document says how big the piece is.
 * - **Margins mean a distance from the cut**, not from the edge of the
 *   sheet -- which is what setBleed() moves them to mean, since the page
 *   origin sits at the sheet's corner and 15mm from there is 12mm from
 *   the finished edge.
 *
 * The colours are CMYK throughout, for the reason 20-print-colours.php
 * goes into: a press mixes ink, and stating coverage is the only way to
 * say what it should mix.
 */

require __DIR__ . '/../vendor/autoload.php';

use MightyPDF\Assembler\Document;
use MightyPDF\Assembler\PageSize;
use MightyPDF\Content\CmykColor;
use MightyPDF\Content\Font\StandardFont;
use MightyPDF\Content\Text\HorizontalAlign;
use MightyPDF\Layout\Flow;
use MightyPDF\Layout\Margins;
use MightyPDF\Layout\Style;
use MightyPDF\Layout\Unit;

const BLEED_MM = 3.0;

$ink = new CmykColor(0.0, 0.0, 0.0, 0.92);
$paper = new CmykColor(0.04, 0.02, 0.0, 0.0);
$accent = new CmykColor(0.86, 0.42, 0.0, 0.0);

$document = new Document();

// The sheet: A5 plus 3mm on every side. Flow takes a media box, and this
// is the one call that makes it the right size for a bled job.
$flow = new Flow(
    $document,
    PageSize::A5->withBleed(Unit::Millimetres->toPoints(BLEED_MM)),
    Margins::uniform(14.0),
);

// Boxes on every page, and the margins in by 3mm so that "14mm" below
// means 14mm from the cut. Settable once -- a press trims every sheet
// the same.
$flow->setBleed(BLEED_MM);

$body = new Style(font: StandardFont::Helvetica, sizePt: 10.0, color: $ink);
$heading = $body->with(font: StandardFont::HelveticaBold, sizePt: 26.0);

// -- Artwork that runs off the edge -----------------------------------

// Drawn from -3mm to 3mm past the far edge, which is what bleed is for:
// cut anywhere in that band and the panel still reaches the paper's
// edge. Negative coordinates are outside the margins like any others.
$flow->rect(
    -BLEED_MM,
    -BLEED_MM,
    $flow->pageWidth() + BLEED_MM * 2,
    62.0,
    fill: $accent,
);

$flow->rect(
    -BLEED_MM,
    $flow->pageHeight() - 18.0,
    $flow->pageWidth() + BLEED_MM * 2,
    18.0 + BLEED_MM,
    fill: $paper,
);

// -- Content, measured from the trim edge ------------------------------

$flow->moveTo($flow->margins()->left, 22.0);
$flow->paragraph($flow->contentWidth(), 'Open Studio', $heading->with(color: new CmykColor(0, 0, 0, 0)));

$flow->moveTo($flow->margins()->left, 40.0);
$flow->paragraph(
    $flow->contentWidth(),
    'Saturday 14 September, 10am until late',
    $body->with(sizePt: 12.0, color: new CmykColor(0, 0, 0, 0)),
);

$flow->moveTo($flow->margins()->left, 76.0);
$flow->paragraph(
    $flow->contentWidth(),
    'Twelve makers, one building, and the machines they use. Printmaking '
    . 'demonstrations run hourly; the letterpress will be inked and open '
    . 'to anyone who wants a go. Everything on the walls is for sale, and '
    . 'most of it is cheaper than the frame.',
    $body,
);

$flow->newLine(4.0);

$flow->paragraph(
    $flow->contentWidth(),
    'Nothing needs booking. Bring nothing. The dog is welcome.',
    $body->with(sizePt: 9.0),
);

$flow->cellAt(
    $flow->margins()->left,
    $flow->pageHeight() - 14.0,
    $flow->contentWidth(),
    6.0,
    'The Old Dyeworks, Water Lane — open-studio.example',
    $body->with(sizePt: 8.0, align: HorizontalAlign::Center),
);

$flow->saveToFile(__DIR__ . '/output/24-a-print-ready-flyer-with-bleed.pdf');

$page = $document->pages()[0];

printf(
    "Sheet %.1f x %.1fmm, trimming to %.1f x %.1fmm.\n",
    Unit::Millimetres->fromPoints($page->mediaBox()->width()),
    Unit::Millimetres->fromPoints($page->mediaBox()->height()),
    Unit::Millimetres->fromPoints($page->trimBox()->width()),
    Unit::Millimetres->fromPoints($page->trimBox()->height()),
);
