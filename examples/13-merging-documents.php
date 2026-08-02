<?php

/**
 * Combining pages from several existing PDFs into one with PdfMerger.
 *
 * Each source page's visual content (text, drawing, images) and non-form
 * annotations (links, notes) come across, renumbered into the merged
 * document's own object space; a shared font or image used by several
 * pages of the same source is copied once, not once per page. Form field
 * widgets are not carried over -- see the README's "Merging PDFs" section
 * for why.
 *
 * Run: php examples/13-merging-documents.php
 */

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use MightyPDF\Assembler\Document;
use MightyPDF\Content\Font\StandardFont;
use MightyPDF\Content\PageBuilder;
use MightyPDF\Editor\PdfMerger;

@mkdir(__DIR__ . '/output', recursive: true);

// --- Stand in for "two PDFs someone hands you": build them in memory. ---
$cover = new Document();
(new PageBuilder($cover, $cover->newPage()))
    ->drawText(StandardFont::HelveticaBold, 24.0, 72, 720, 'Cover Page');
$cover->saveToFile(__DIR__ . '/output/13-source-cover.pdf');

$report = new Document();
(new PageBuilder($report, $report->newPage()))
    ->drawText(StandardFont::Helvetica, 14.0, 72, 720, 'Report, page 1');
(new PageBuilder($report, $report->newPage()))
    ->drawText(StandardFont::Helvetica, 14.0, 72, 720, 'Report, page 2');
$report->saveToFile(__DIR__ . '/output/13-source-report.pdf');

// --- The part that matters: combine both files' pages into one. ---
$merged = PdfMerger::merge(
    __DIR__ . '/output/13-source-cover.pdf',
    __DIR__ . '/output/13-source-report.pdf',
);

$merged->saveToFile(__DIR__ . '/output/13-merging-documents.pdf');

echo 'Wrote ' . __DIR__ . '/output/13-merging-documents.pdf with ' . count($merged->pages()) . " pages\n";
