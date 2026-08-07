<?php

declare(strict_types=1);

/**
 * An invoice that carries its own machine-readable copy: a PDF a person
 * reads, with the XML a system reads attached to it and marked as being
 * the same invoice rather than an unrelated file that came along.
 *
 * That marking is the whole point of AttachmentRelationship::Data, and it
 * is what the EU e-invoicing formats -- Factur-X, ZUGFeRD, XRechnung --
 * are built on. This example is not a conforming Factur-X file (that
 * needs PDF/A-3 as well, which this library does not do); it is the
 * attachment half of one.
 *
 * Also here: the two viewer preferences worth setting on almost any
 * document, and a page turned by /Rotate.
 */

require __DIR__ . '/../vendor/autoload.php';

use MightyPDF\Assembler\Annotation\AttachmentIcon;
use MightyPDF\Assembler\Attachment\AttachmentRelationship;
use MightyPDF\Assembler\Document;
use MightyPDF\Assembler\PageSize;
use MightyPDF\Assembler\PrintScaling;
use MightyPDF\Content\Color;
use MightyPDF\Content\Font\StandardFont;
use MightyPDF\Content\Text\HorizontalAlign;
use MightyPDF\Layout\Border;
use MightyPDF\Layout\Flow;
use MightyPDF\Layout\Margins;
use MightyPDF\Layout\Style;
use MightyPDF\Layout\Unit;

$document = new Document();
$flow = new Flow($document, PageSize::A4, new Margins(20.0, 18.0, 20.0, 18.0), Unit::Millimetres);

$ink = Color::fromHex('#1f2933');
$muted = Color::gray(0.4);

$document->info()->setTitle('Invoice 2026-0417 — MightyPDF Ltd');
$document->info()->setAuthor('MightyPDF Ltd');

// -- The document a person reads ---------------------------------------

$flow->paragraph(
    $flow->contentWidth(),
    'Invoice 2026-0417',
    new Style(StandardFont::HelveticaBold, 20.0, $ink, paddingPt: 0.0),
);
$flow->newLine(8.0);

$body = new Style(StandardFont::Helvetica, 9.0, $ink, border: Border::bottom(0.1), paddingPt: 3.0);

$lines = [
    ['Licence renewal, 12 months', '1', '2,400.00'],
    ['Onboarding and migration', '1', '850.00'],
    ['Priority support, 12 months', '1', '1,200.00'],
];

$flow->table([100.0, 30.0, 44.0], $body, new Style(StandardFont::HelveticaBold, 9.0, Color::white(), fill: Color::fromHex('#334155'), paddingPt: 3.0))
    ->align(1, HorizontalAlign::Right)
    ->align(2, HorizontalAlign::Right)
    ->header(['Description', 'Quantity', 'Amount (EUR)'])
    ->rows($lines, static fn (array $line): array => $line)
    ->row(
        ['Total', '', '4,450.00'],
        new Style(StandardFont::HelveticaBold, 9.0, $ink, border: Border::top(0.6), paddingPt: 3.0),
    )
    ->end();

$flow->newLine(6.0);

// -- The copy a system reads --------------------------------------------

$xml = <<<'XML'
    <?xml version="1.0" encoding="UTF-8"?>
    <Invoice>
      <ID>2026-0417</ID>
      <IssueDate>2026-08-07</IssueDate>
      <DocumentCurrencyCode>EUR</DocumentCurrencyCode>
      <LegalMonetaryTotal><PayableAmount currencyID="EUR">4450.00</PayableAmount></LegalMonetaryTotal>
    </Invoice>
    XML;

$invoiceData = $document->attach(
    'invoice-2026-0417.xml',
    $xml,
    'The same invoice, machine-readable',
    'application/xml',
    AttachmentRelationship::Data,
);

// And the workings behind one of the lines, which is a different kind of
// attachment: related, but not the same document.
$workings = $document->attach(
    'migration-hours.csv',
    "date,hours,rate\n2026-07-02,6.5,95.00\n2026-07-03,2.5,95.00\n",
    'Hours behind the migration line',
    'text/csv',
    AttachmentRelationship::Supplement,
);

$flow->paragraph(
    $flow->contentWidth(),
    'This invoice carries its own XML. Both files are in the reader\'s attachments '
    . 'panel; the workings are also pinned to the page beside the line they explain.',
    new Style(StandardFont::Helvetica, 8.5, $muted, paddingPt: 0.0),
);

// The visible half, placed in page coordinates through the escape hatch.
$flow->content()->addFileAttachment(
    $workings,
    $flow->toPointsX(170.0),
    $flow->toPointsY($flow->y() + 8.0),
    18.0,
    AttachmentIcon::Paperclip,
    'Hours behind the migration line',
);

// -- How it asks to be opened and printed --------------------------------

$document->viewerPreferences()
    // So a file received as "invoice_final_v3(2).pdf" still says what it is.
    ->displayDocumentTitle()
    // So the amounts print where they were laid out.
    ->printScaling(PrintScaling::None);

// -- A page that arrived the wrong way up --------------------------------

$landscapeScan = $document->newPage(PageSize::A4, rotation: 90);

// Drawn in the page's own coordinates, which /Rotate does not touch --
// the reader turns the finished page, so this comes out reading up the
// side of a portrait sheet.
(new \MightyPDF\Content\PageBuilder($document, $landscapeScan))->drawTextInBox(
    StandardFont::Helvetica,
    12.0,
    72.0,
    360.0,
    450.0,
    24.0,
    'This page carries /Rotate 90 — the reader turns it, the coordinates stay put.',
    color: $ink,
);

$flow->saveToFile(__DIR__ . '/output/21-attachments-and-viewer-preferences.pdf');

echo "Wrote output/21-attachments-and-viewer-preferences.pdf ("
    . count($document->attachments()) . " attachments)\n";
