<?php

/**
 * AcroForm fields: text fields, checkboxes, and radio groups.
 *
 * Fields use /NeedsAppearances so readers regenerate the text-field
 * visuals themselves from /DA + /V; checkboxes and radio buttons carry
 * their own small on/off appearance streams (drawn with ContentStream)
 * since those appearances are less consistently reader-regenerated.
 *
 * Run: php examples/06-form-fields.php
 */

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use MightyPDF\Assembler\Document;
use MightyPDF\Content\Font\StandardFont;
use MightyPDF\Content\PageBuilder;

$document = new Document();
$page = $document->newPage();
$content = new PageBuilder($document, $page);

$content->drawText(StandardFont::HelveticaBold, 24.0, 72, 720, 'Form fields');

$content->drawText(StandardFont::Helvetica, 11.0, 72, 670, 'First name:');
$content->addTextField('first_name', x: 200, y: 665, width: 250, height: 20, value: 'Jane');

$content->drawText(StandardFont::Helvetica, 11.0, 72, 635, 'Comments:');
$content->addTextField('comments', x: 200, y: 630, width: 250, height: 20, maxLength: 200);

$content->drawText(StandardFont::Helvetica, 11.0, 72, 595, 'Subscribe to our newsletter:');
$content->addCheckbox('subscribe', x: 280, y: 592, size: 14, checked: true);

$content->drawText(StandardFont::Helvetica, 11.0, 72, 565, 'I accept the terms:');
$content->addCheckbox('accept_terms', x: 280, y: 562, size: 14, checked: false);

$content->drawText(StandardFont::Helvetica, 11.0, 72, 525, 'Preferred plan:');
$content->drawText(StandardFont::Helvetica, 9.0, 218, 528, 'Basic');
$content->drawText(StandardFont::Helvetica, 9.0, 278, 528, 'Pro');
$content->addRadioGroup('plan', [
    ['exportValue' => 'Basic', 'x' => 200, 'y' => 522, 'size' => 12],
    ['exportValue' => 'Pro', 'x' => 260, 'y' => 522, 'size' => 12],
], checkedExportValue: 'Pro');

@mkdir(__DIR__ . '/output', recursive: true);
$document->saveToFile(__DIR__ . '/output/06-form-fields.pdf');

echo 'Wrote ' . __DIR__ . "/output/06-form-fields.pdf\n";
