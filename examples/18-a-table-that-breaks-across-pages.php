<?php

declare(strict_types=1);

/**
 * A table with a header that comes back on every page it runs onto,
 * cells that wrap, rows that size themselves to their tallest cell, a
 * right-aligned figures column, zebra striping, and a total row that
 * spans three of the four columns.
 *
 * Compare with example 16, which draws its table as a run of cell()
 * calls: that one restates its column widths on every row and loses its
 * header the moment an automatic page break lands in the middle of it.
 */

require __DIR__ . '/../vendor/autoload.php';

use MightyPDF\Assembler\Document;
use MightyPDF\Assembler\PageSize;
use MightyPDF\Content\Color;
use MightyPDF\Content\Font\StandardFont;
use MightyPDF\Content\Text\HorizontalAlign;
use MightyPDF\Content\Text\VerticalAlign;
use MightyPDF\Layout\Border;
use MightyPDF\Layout\Cell;
use MightyPDF\Layout\Flow;
use MightyPDF\Layout\Margins;
use MightyPDF\Layout\Style;
use MightyPDF\Layout\Unit;

$ink = Color::fromHex('#1f2933');
$navy = Color::fromHex('#334155');

$flow = new Flow(
    new Document(),
    PageSize::A4,
    new Margins(18.0, 15.0, 20.0, 15.0),
    Unit::Millimetres,
);

$flow->paragraph(
    $flow->contentWidth(),
    'Licence register',
    new Style(StandardFont::HelveticaBold, 18.0, $ink, valign: VerticalAlign::CapMiddle, paddingPt: 0.0),
);
$flow->newLine(4.0);

$heading = new Style(StandardFont::HelveticaBold, 8.5, Color::white(), fill: $navy, paddingPt: 3.0);
$body = new Style(StandardFont::Helvetica, 8.5, $ink, border: Border::bottom(0.1), paddingPt: 3.0);

// Four columns summing to 180mm -- the content width of A4 at these
// margins, which contentWidth() would also tell you.
$table = $flow->table([52.0, 68.0, 30.0, 30.0], $body, $heading, minRowHeight: 7.0)
    ->align(3, HorizontalAlign::Right)
    ->striped(Color::gray(0.965))
    ->header(['Component', 'Licence', 'Version', 'Seats']);

// Deliberately mixed: some descriptions wrap to two or three lines, and
// the rows around them stay the height they need.
$components = [
    ['Ingest pipeline', 'Apache-2.0', '4.2.1', 120],
    ['Reporting engine', 'Commercial, per-seat, renewed annually on the anniversary of first deployment', '2.9.0', 45],
    ['Charting toolkit', 'MIT', '11.4.3', 120],
    ['PDF generation', 'BSD-3-Clause', '2.0.0', 120],
    ['Message broker', 'Apache-2.0 with a field-of-use restriction covering resale to third parties', '3.1.7', 12],
    ['Identity provider', 'Commercial', '7.0.4', 300],
    ['Search index', 'Elastic Licence 2.0', '8.13.0', 60],
    ['Object storage client', 'Apache-2.0', '1.34.2', 120],
];

// Repeated to force the break the header is here to survive.
$rows = [];
for ($page = 0; $page < 5; ++$page) {
    foreach ($components as $component) {
        $rows[] = $component;
    }
}

$table->rows($rows, static fn (array $row): array => [
    $row[0],
    $row[1],
    $row[2],
    number_format((float) $row[3]),
]);

$total = array_sum(array_column($rows, 3));

$table->row([
    new Cell('Total seats', new Style(StandardFont::HelveticaBold, 8.5, $ink, border: Border::top(0.6), paddingPt: 3.0), colspan: 3),
    new Cell(
        number_format($total),
        new Style(
            StandardFont::HelveticaBold,
            8.5,
            $ink,
            border: Border::top(0.6),
            align: HorizontalAlign::Right,
            paddingPt: 3.0,
        ),
    ),
]);

$table->end()->onEachPage(static function (Flow $flow, int $page, int $of): void {
    $flow->cellAt(
        15.0,
        285.0,
        180.0,
        5.0,
        "Licence register — page $page of $of",
        new Style(StandardFont::Helvetica, 7.0, Color::gray(0.45), align: HorizontalAlign::Center),
    );
});

$flow->saveToFile(__DIR__ . '/output/18-a-table-that-breaks-across-pages.pdf');

echo "Wrote output/18-a-table-that-breaks-across-pages.pdf ({$flow->pageCount()} pages)\n";
