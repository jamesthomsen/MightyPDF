<?php

/**
 * Combining pages from several existing PDFs into one with PdfMerger.
 *
 * Each source page's visual content (text, drawing, images) and its
 * annotations -- form fields included -- come across, renumbered into the
 * merged document's own object space; a shared font or image used by
 * several pages of the same source is copied once, not once per page.
 *
 * Merging forms means combining each file's into the one /AcroForm a
 * document may have, so two files that each have a "signature" field
 * cannot both keep the name: the second is renamed, since a PDF field's
 * value lives on the field itself and two fields sharing a name would
 * share a value. This prints what the merged form's fields ended up
 * called.
 *
 * Bookmarks are combined the same way -- each file's appear in the order
 * the files were merged, pointing at the pages they arrived with.
 *
 * Run: php examples/13-merging-documents.php
 */

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use MightyPDF\Assembler\Destination;
use MightyPDF\Assembler\Document;
use MightyPDF\Content\Font\StandardFont;
use MightyPDF\Content\PageBuilder;
use MightyPDF\Editor\Form\FormFiller;
use MightyPDF\Editor\PdfEditor;
use MightyPDF\Editor\PdfMerger;

@mkdir(__DIR__ . '/output', recursive: true);

// --- Stand in for "two PDFs someone hands you": build them in memory. ---
$cover = new Document();
(new PageBuilder($cover, $cover->newPage()))
    ->drawText(StandardFont::HelveticaBold, 24.0, 72, 720, 'Cover Page');
$cover->saveToFile(__DIR__ . '/output/13-source-cover.pdf');

$report = new Document();
$reportPages = [];

foreach ([1, 2] as $number) {
    $reportPages[$number] = $report->newPage();
    (new PageBuilder($report, $reportPages[$number]))
        ->drawText(StandardFont::Helvetica, 14.0, 72, 720, "Report, page $number");
}

// A source with bookmarks of its own, to be carried into the merge.
$chapter = $report->outline()->add('Report', Destination::of($reportPages[1]));
$chapter->add('First half', Destination::of($reportPages[1], top: 700));
$chapter->add('Second half', Destination::of($reportPages[2], top: 700));

$report->saveToFile(__DIR__ . '/output/13-source-report.pdf');

// Two forms that both call their field "signature" -- the collision a
// merged form has to resolve.
foreach (['a', 'b'] as $which) {
    $form = new Document();
    $content = new PageBuilder($form, $form->newPage());
    $content->drawText(StandardFont::Helvetica, 14.0, 72, 720, "Form $which");
    $content->addTextField('signature', x: 72, y: 660, width: 220, height: 20, value: "signed by $which");
    $form->saveToFile(__DIR__ . "/output/13-source-form-$which.pdf");
}

// --- The part that matters: combine every file's pages into one. ---
$merged = PdfMerger::merge(
    __DIR__ . '/output/13-source-cover.pdf',
    __DIR__ . '/output/13-source-report.pdf',
    __DIR__ . '/output/13-source-form-a.pdf',
    __DIR__ . '/output/13-source-form-b.pdf',
);

// The report's own bookmarks arrived pointing at the pages they came
// with -- pages 2 and 3 of the merged document, since the cover went
// first -- without being touched here. A bookmark added now joins them
// at the end, in the order it was added.
$merged->outline()->add('Cover', Destination::of($merged->pages()[0]));

$merged->saveToFile(__DIR__ . '/output/13-merging-documents.pdf');

echo 'Wrote ' . __DIR__ . '/output/13-merging-documents.pdf with ' . count($merged->pages()) . " pages\n";

// What the merged form's fields are called, and what they hold. Read
// these back before filling a merged form: the second "signature" had to
// be renamed to keep the two apart.
$filler = new FormFiller(PdfEditor::fromBytes($merged->save()));

foreach ($filler->values() as $name => $value) {
    echo "  $name = $value\n";
}
