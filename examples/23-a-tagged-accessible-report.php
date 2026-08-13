<?php

declare(strict_types=1);

/**
 * A tagged report -- the same layout code as any other, plus one call.
 *
 * A tagged PDF says what each piece of content *is* and what order it is
 * meant to be read in. Neither is recoverable from the page, which is why
 * tagging a document built out of raw drawing calls means restating, item
 * by item, what everything is: a canvas genuinely does not know.
 *
 * A Flow does. From tagged() onwards a paragraph tags itself /P, a
 * table's rows and cells become /TR and /TH//TD, a wrapped run is one
 * /Span however many lines it takes, and everything drawn through
 * onEachPage() becomes an artifact -- outside the structure entirely,
 * which is what stops a screen reader announcing "Page 2 of 3" in the
 * middle of a sentence.
 *
 * What is left for the caller is exactly what the layout cannot infer:
 * which paragraphs are headings, where a section begins, and what a
 * picture depicts.
 */

require __DIR__ . '/../vendor/autoload.php';

use MightyPDF\Assembler\Document;
use MightyPDF\Assembler\PageLabelStyle;
use MightyPDF\Assembler\Structure\StructureRole;
use MightyPDF\Content\Color;
use MightyPDF\Content\Font\StandardFont;
use MightyPDF\Content\Text\HorizontalAlign;
use MightyPDF\Layout\Border;
use MightyPDF\Layout\Flow;
use MightyPDF\Layout\Style;

$document = new Document();

$document->info()->setTitle('Annual Report 2026');
$document->info()->setAuthor('Acme Ltd');
$document->info()->setSubject('Results for the year to 31 March 2026');

// XMP, restated from /Info at save so the two cannot drift apart.
$document->metadata()->setRights('© 2026 Acme Ltd');

$flow = new Flow($document);

// The whole of turning tagging on. The language is not a formality: it
// is what tells a screen reader which voice to read the document in.
$flow->tagged('en-GB');

$heading = new Style(font: StandardFont::HelveticaBold, sizePt: 20.0);
$subheading = new Style(font: StandardFont::HelveticaBold, sizePt: 13.0);
$body = new Style(font: StandardFont::TimesRoman, sizePt: 11.0);
$caption = new Style(font: StandardFont::HelveticaOblique, sizePt: 9.0, color: new Color(0.35, 0.35, 0.35));

$tableHeader = new Style(
    font: StandardFont::HelveticaBold,
    sizePt: 10.0,
    fill: new Color(0.93, 0.93, 0.95),
    border: Border::bottom(0.6),
);

// Page furniture. Nothing has to be said about it being furniture --
// what onEachPage() draws is an artifact by definition.
$flow->onEachPage(function (Flow $flow, int $page, int $total): void {
    $flow->line(15.0, 283.0, 195.0, 283.0);
    $flow->textAt(15.0, 289.0, 'Acme Ltd — Annual Report 2026', new Style(sizePt: 8.0));
    $flow->textAt(195.0, 289.0, "Page $page of $total", new Style(sizePt: 8.0, align: HorizontalAlign::Right));
});

$flow->inside(StructureRole::Section, function (Flow $flow) use ($heading, $body, $subheading, $caption, $tableHeader): void {
    // tag() names what the layout cannot work out for itself. paragraph()
    // would otherwise be a /P, which for a title is true and useless.
    $flow->tag(StructureRole::Heading1, fn (Flow $f) => $f->paragraph(180.0, 'Annual Report 2026', $heading));
    $flow->newLine(4.0);

    $flow->paragraph(
        180.0,
        'Revenue rose twelve per cent over the year, driven by the new product line and '
        . 'a full twelve months of the Fenwick acquisition. Margins held steady despite '
        . 'input costs, and the board is recommending an unchanged dividend.',
        $body,
    );
    $flow->newLine(6.0);

    $flow->inside(StructureRole::Section, function (Flow $flow) use ($subheading, $tableHeader, $caption): void {
        $flow->tag(StructureRole::Heading2, fn (Flow $f) => $f->paragraph(180.0, 'Revenue by region', $subheading));
        $flow->newLine(3.0);

        // Rows and cells tag themselves. The header row's cells become
        // /TH, which is what lets a screen reader say which column a
        // number is in rather than reading an unattached grid.
        $flow->table([70.0, 40.0, 40.0], headerStyle: $tableHeader)
            ->header(['Region', 'Revenue', 'Growth'])
            ->row(['United Kingdom', '£2.4m', '+14%'])
            ->row(['Europe', '£1.8m', '+9%'])
            ->row(['Rest of world', '£0.6m', '+21%'])
            ->end();

        $flow->newLine(3.0);
        $flow->tag(
            StructureRole::Caption,
            fn (Flow $f) => $f->paragraph(150.0, 'Table 1: revenue by region, year to 31 March 2026.', $caption),
        );
    });

    $flow->newLine(6.0);

    $flow->inside(StructureRole::Section, function (Flow $flow) use ($subheading, $body, $caption): void {
        $flow->tag(StructureRole::Heading2, fn (Flow $f) => $f->paragraph(180.0, 'Outlook', $subheading));
        $flow->newLine(3.0);

        // A run inside a sentence. The whole run is one /Span, however
        // many lines it wraps over.
        $flow->write('Trading since the year end has been in line with expectations. Our ', $body)
            ->write('detailed guidance', $body, link: 'https://example.com/guidance')
            ->write(' is published quarterly and supersedes anything said here.', $body)
            ->newLine(8.0);

        $bars = [[24.0, new Color(0.2, 0.35, 0.7)], [18.0, new Color(0.3, 0.5, 0.8)], [6.0, new Color(0.55, 0.7, 0.9)]];
        $baseline = $flow->y() + 40.0;

        $flow->tag(StructureRole::Figure, function (Flow $flow) use ($bars, $baseline): void {
            // A figure needs alternate text: without it a reader has
            // nothing at all to say about the picture, and this is the
            // single commonest accessibility failure there is.
            //
            // currentElement() inside tag() is the element being drawn
            // into, so the description lands on the figure rather than on
            // a second one beside it.
            $flow->currentElement()?->setAlternateText(
                'A bar chart comparing revenue by region: the United Kingdom highest at £2.4m, '
                . 'then Europe at £1.8m, then the rest of the world at £0.6m.',
            );

            $x = 20.0;

            foreach ($bars as [$height, $colour]) {
                $flow->rect($x, $baseline - $height, 22.0, $height, $colour);
                $x += 30.0;
            }
        });

        $flow->moveTo($flow->margins()->left, $baseline + 4.0);
        $flow->tag(
            StructureRole::Caption,
            fn (Flow $f) => $f->paragraph(150.0, 'Figure 1: revenue by region (£m).', $caption),
        );
    });
});

$flow->finish();

// What the reader calls each page, which is a separate business from
// anything printed on it.
$document->pageLabels()->from(0, PageLabelStyle::Decimal);

$flow->saveToFile(__DIR__ . '/output/23-a-tagged-accessible-report.pdf');

echo "Wrote a {$flow->pageCount()}-page tagged document.\n";
