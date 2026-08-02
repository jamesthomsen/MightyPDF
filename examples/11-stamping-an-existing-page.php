<?php

/**
 * Drawing onto a page of an existing document with PageOverlay -- a
 * logo, a stamp, a watermark -- using the same PageBuilder that draws on
 * a fresh page, and adding a new form field to a document that already
 * has one.
 *
 * Everything drawn goes into a form XObject invoked once from the page,
 * never appended to the page's own content stream, so it can't collide
 * with resource names the page already uses or leak into a graphics
 * state the original content left unbalanced. Nothing is written until
 * apply() is called.
 *
 * Run: php examples/11-stamping-an-existing-page.php
 */

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use MightyPDF\Assembler\Document;
use MightyPDF\Content\Font\StandardFont;
use MightyPDF\Content\PageBuilder;
use MightyPDF\Editor\Form\FormFiller;
use MightyPDF\Editor\PageOverlay;
use MightyPDF\Editor\PdfEditor;

$fixtureImages = __DIR__ . '/../tests/fixtures/images';

// --- Stand in for "a contract someone hands you": build one in memory. ---
$source = new Document();
$page = $source->newPage();
(new PageBuilder($source, $page))->drawText(StandardFont::TimesRoman, 12.0, 72, 700, 'This agreement is entered into by the parties below.');

$sourceBytes = $source->save();

// --- The part that matters: stamp an existing page and add a field to it. ---
$editor = PdfEditor::fromBytes($sourceBytes);
// A real file would be: $editor = PdfEditor::open('/path/to/contract.pdf');

$pages = $editor->resolveDictionary($editor->catalog()->get('Pages'));
$firstPage = $editor->resolveDictionary($pages->get('Kids')->items()[0]);

$overlay = new PageOverlay($editor, $firstPage);
$overlay->content()
    ->drawPng("$fixtureImages/sample.png", x: 460, y: 700, width: 60, height: 60)
    ->drawText(StandardFont::HelveticaBold, 13.0, 52, 72, 'DRAFT');

// Adding a field takes over the document's existing /AcroForm rather than
// building a second one beside it -- the catalog has room for only one.
$overlay->content()->addTextField('signed_on', x: 200, y: 560, width: 200, height: 20);
$overlay->apply();

(new FormFiller($editor))->set('signed_on', '31 July 2026');

@mkdir(__DIR__ . '/output', recursive: true);
$editor->saveToFile(__DIR__ . '/output/11-stamping-an-existing-page.pdf');

echo 'Wrote ' . __DIR__ . "/output/11-stamping-an-existing-page.pdf\n";
