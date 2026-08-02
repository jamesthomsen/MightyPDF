<?php

/**
 * Filling in the AcroForm fields of an existing PDF with FormFiller --
 * the reader-side counterpart to examples/06-form-fields.php, which
 * creates fields in a brand-new document.
 *
 * Filled values are drawn into a fresh appearance stream for each field,
 * so the form looks filled in even to a reader that ignores
 * /NeedAppearances, not just stored in /V.
 *
 * Run: php examples/10-filling-an-existing-form.php
 */

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use MightyPDF\Assembler\Document;
use MightyPDF\Content\Font\StandardFont;
use MightyPDF\Content\PageBuilder;
use MightyPDF\Editor\Form\FormFiller;
use MightyPDF\Editor\PdfEditor;

// --- Stand in for "a blank form someone hands you": build one in memory. ---
$source = new Document();
$page = $source->newPage();
$content = new PageBuilder($source, $page);

$content->drawText(StandardFont::HelveticaBold, 18.0, 72, 740, 'Registration Form');
$content->drawText(StandardFont::Helvetica, 11.0, 72, 690, 'Full name:');
$content->addTextField('full_name', x: 200, y: 685, width: 250, height: 20);
$content->drawText(StandardFont::Helvetica, 11.0, 72, 655, 'Email:');
$content->addTextField('email', x: 200, y: 650, width: 250, height: 20);
$content->drawText(StandardFont::Helvetica, 11.0, 72, 620, 'Subscribe to updates:');
$content->addCheckbox('subscribe', x: 280, y: 617, size: 14);

$sourceBytes = $source->save();

// --- The part that matters: open an existing form and fill it in. ---
$editor = PdfEditor::fromBytes($sourceBytes);
// A real file would be: $editor = PdfEditor::open('/path/to/application.pdf');
$filler = new FormFiller($editor);

echo 'Fields found: ' . implode(', ', $filler->names()) . "\n";

$filler->fill([
    'full_name' => 'Zoë Alvarez',
    'email' => 'zoe@example.com',
    'subscribe' => true,
]);

@mkdir(__DIR__ . '/output', recursive: true);
$editor->saveToFile(__DIR__ . '/output/10-filling-an-existing-form.pdf');

echo 'Wrote ' . __DIR__ . "/output/10-filling-an-existing-form.pdf\n";
