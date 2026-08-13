<?php

declare(strict_types=1);

/**
 * The three things a flowed document needs that a cursor and a cell do
 * not cover on their own:
 *
 * - **Runs.** write() puts text where the cursor is and leaves the
 *   cursor at the end of it, so a phrase in a second colour, or behind a
 *   link, sits inside a sentence rather than beside one. paragraph() is
 *   the block; this is the other half of the pair.
 * - **Columns.** onPageBreak() takes over the decision an automatic
 *   break makes. A column is a left edge and a right edge -- which is to
 *   say a pair of margins -- so the hook moves those and says it has
 *   dealt with it.
 * - **A page of another size.** newPage() takes one, and every
 *   measurement afterwards follows the page being drawn on, so a wide
 *   table gets a landscape sheet in the middle of an upright report.
 */

require __DIR__ . '/../vendor/autoload.php';

use MightyPDF\Assembler\Document;
use MightyPDF\Assembler\PageSize;
use MightyPDF\Content\Color;
use MightyPDF\Content\Font\StandardFont;
use MightyPDF\Content\Text\HorizontalAlign;
use MightyPDF\Layout\Border;
use MightyPDF\Layout\Flow;
use MightyPDF\Layout\Margins;
use MightyPDF\Layout\Style;
use MightyPDF\Layout\Unit;

$ink = Color::fromHex('#1f2933');
$muted = Color::fromHex('#6b7280');
$blue = Color::fromHex('#1d4ed8');

$flow = new Flow(
    new Document(),
    PageSize::A4,
    new Margins(18.0, 15.0, 20.0, 15.0),
    Unit::Millimetres,
);

$body = new Style(StandardFont::Helvetica, 9.5, $ink, paddingPt: 0.0);
$heading = $body->with(font: StandardFont::HelveticaBold, sizePt: 15.0);

$flow->document()->info()->setTitle('Runs, columns and a landscape page');

// -- A sentence made of runs ------------------------------------------

$flow->paragraph($flow->contentWidth(), 'Terms of supply', $heading);
$flow->newLine(4.0);

$flow->write('These goods are supplied under the ', $body)
    ->write(
        'standard conditions of sale',
        $body->with(color: $blue),
        link: 'https://example.com/conditions',
    )
    ->write(', which are incorporated into this agreement by reference. ', $body)
    ->write('Nothing here varies them.', $body->with(font: StandardFont::HelveticaBoldOblique));

$flow->newLine(12.0);

// -- Two columns ------------------------------------------------------

$flow->paragraph($flow->contentWidth(), 'Schedule 1: definitions', $heading);
$flow->newLine(4.0);

$gutter = 8.0;
$columnWidth = ($flow->contentWidth() - $gutter) / 2;
$rightColumnLeft = $flow->margins()->left + $columnWidth + $gutter;
$column = 0;

// Where the second column starts on *this* page, which is below the
// heading rather than at the top margin -- the columns share the first
// page with something that is not in them. Every page after this one
// begins with the columns and nothing else.
$columnTop = $flow->y();

// The hook is the whole of the multi-column layout. Note that it moves
// the *margins* and not only the cursor: newLine() returns to the left
// margin, so a hook that moved the cursor alone would get one correct
// line and then drift back to the page edge.
$firstColumn = fn (Flow $flow): Flow => $flow->setMargins(
    $flow->margins()->with(left: 15.0, right: 15.0 + $columnWidth + $gutter),
);
$secondColumn = fn (Flow $flow): Flow => $flow->setMargins(
    $flow->margins()->with(left: $rightColumnLeft, right: 15.0),
);

$flow->onPageBreak(function (Flow $flow) use (&$column, $firstColumn, $secondColumn, $rightColumnLeft, $columnTop): bool {
    $column = 1 - $column;

    // Back in the first column, so let the page turn as it wanted to --
    // and land in the first column when it does.
    if ($column === 0) {
        $firstColumn($flow);

        return true;
    }

    $secondColumn($flow);
    $flow->moveTo(
        $rightColumnLeft,
        $flow->pageNumber() === 1 ? $columnTop : $flow->margins()->top,
    );

    return false;
});

$firstColumn($flow);

$definitions = [
    'Agreement' => 'this document and every schedule to it, as amended in writing from time to time.',
    'Business Day' => 'a day other than a Saturday, Sunday or public holiday on which clearing banks are open.',
    'Deliverable' => 'anything the Supplier is required to provide under a Statement of Work.',
    'Force Majeure' => 'an event beyond a party\'s reasonable control which it could not have avoided by taking reasonable steps.',
    'Good Industry Practice' => 'the standard of skill and care a competent supplier in the same field would apply.',
    'Intellectual Property' => 'patents, copyright, database rights, trade marks and all equivalent rights anywhere in the world.',
    'Losses' => 'all losses, liabilities, costs and expenses, including reasonable legal fees.',
    'Personal Data' => 'has the meaning given to it in applicable data protection law.',
    'Statement of Work' => 'a document signed by both parties describing Deliverables, timing and fees.',
    'Supplier Materials' => 'anything the Supplier owned or licensed before the Agreement and brings to it.',
    'Acceptance' => 'written confirmation that a Deliverable meets the acceptance criteria for it.',
    'Acceptance Criteria' => 'the tests a Deliverable must pass, as set out in the relevant Statement of Work.',
    'Affiliate' => 'any entity controlling, controlled by, or under common control with a party.',
    'Change Request' => 'a written proposal to vary a Statement of Work, signed by both parties before it takes effect.',
    'Charges' => 'the fees payable under Schedule 2, exclusive of value added tax.',
    'Confidential Information' => 'information marked confidential, or which a reasonable recipient would treat as confidential.',
    'Customer Data' => 'data the Customer or its users supply to the Supplier in connection with the Agreement.',
    'Defect' => 'a failure of a Deliverable to conform to its specification in any material respect.',
    'Documentation' => 'the manuals and technical materials the Supplier provides with a Deliverable.',
    'Effective Date' => 'the date the last party signed the Agreement.',
    'Escalation' => 'the process in Schedule 3 for referring a dispute to each party\'s senior representative.',
    'Initial Term' => 'the twelve months from the Effective Date.',
    'Insolvency Event' => 'the appointment of an administrator or liquidator, or any analogous step in any jurisdiction.',
    'Milestone' => 'a date in a Statement of Work by which named Deliverables are due.',
    'Notice' => 'a communication given in writing under clause 21, and not by electronic mail alone.',
    'Renewal Term' => 'each successive twelve months after the Initial Term, unless either party gives notice.',
    'Service Levels' => 'the availability and response targets in Schedule 4.',
    'Service Credits' => 'the sums set against the Charges when a Service Level is missed.',
    'Sub-processor' => 'a third party engaged by the Supplier to process Personal Data on the Customer\'s behalf.',
    'Term' => 'the Initial Term together with every Renewal Term.',
    'Third Party Software' => 'software licensed to the Supplier by another and supplied under its own terms.',
    'Working Hours' => '09:00 to 17:30 on a Business Day, in the Customer\'s local time.',
];

foreach ($definitions as $term => $meaning) {
    // Runs again, at column width: the term in bold, the definition
    // flowing on from it in the same sentence.
    $flow->write("$term ", $body->with(font: StandardFont::HelveticaBold))
        ->write($meaning, $body)
        ->newLine(7.0);
}

// -- A landscape sheet for a wide table -------------------------------

$flow->onPageBreak(null);
$flow->newPage(PageSize::A4->landscape());
$flow->setMargins(new Margins(18.0, 15.0, 20.0, 15.0));

$flow->paragraph($flow->contentWidth(), 'Schedule 2: charges', $heading);
$flow->newLine(4.0);

// 267mm of body rather than 180mm, and contentWidth() says so without
// being told which page it is on.
$widths = [70.0, 45.0, 45.0, 45.0, $flow->contentWidth() - 205.0];

$table = $flow->table(
    $widths,
    $body->with(paddingPt: 3.0, border: Border::box(0.25, Color::fromHex('#cbd5e1'))),
    $body->with(font: StandardFont::HelveticaBold, fill: Color::fromRgb255(241, 245, 249), paddingPt: 3.0, border: Border::box(0.25, Color::fromHex('#cbd5e1'))),
);

$table->header(['Deliverable', 'Rate', 'Units', 'Charge', 'Notes']);

foreach (range(1, 26) as $n) {
    $table->row([
        "Deliverable $n",
        '£' . number_format(450 + $n * 25),
        (string) (10 + $n),
        '£' . number_format((450 + $n * 25) * (10 + $n)),
        $n % 3 === 0 ? 'Subject to acceptance testing.' : '',
    ]);
}

// -- Furniture on every page, whatever size it is ---------------------

$flow->onEachPage(function (Flow $flow, int $page, int $total) use ($muted, $body): void {
    $flow->cellAt(
        $flow->margins()->left,
        $flow->pageHeight() - 14.0,
        $flow->pageWidth() - 30.0,
        5.0,
        "Conditions of sale — page $page of $total",
        $body->with(sizePt: 7.5, color: $muted, align: HorizontalAlign::Center),
    );
});

$flow->saveToFile(__DIR__ . '/output/22-runs-columns-and-a-landscape-page.pdf');

echo "Wrote a {$flow->pageCount()}-page document.\n";
