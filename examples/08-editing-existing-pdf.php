<?php

/**
 * Editing an existing PDF with PdfEditor.
 *
 * Opening writes nothing: PdfEditor::open()/fromBytes() just reads the
 * file. Nothing is appended to the output until you register() a changed
 * or newly-built object and call save() -- and if you never register
 * anything, save() returns the original bytes byte-for-byte.
 *
 * This builds a small two-page document first (so the example has no
 * external file to depend on), then reopens those bytes exactly as you
 * would open a file from disk, and rotates the second page.
 *
 * Run: php examples/08-editing-existing-pdf.php
 */

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use MightyPDF\Assembler\Document;
use MightyPDF\Assembler\Types\PdfInteger;
use MightyPDF\Content\Font\StandardFont;
use MightyPDF\Content\PageBuilder;
use MightyPDF\Editor\PdfEditor;

// --- Stand in for "a PDF someone hands you": build one in memory. ---
$source = new Document();

$page1 = $source->newPage();
(new PageBuilder($source, $page1))->drawText(StandardFont::HelveticaBold, 24.0, 72, 720, 'Page one');

$page2 = $source->newPage();
(new PageBuilder($source, $page2))->drawText(StandardFont::HelveticaBold, 24.0, 72, 720, 'Page two');

$sourceBytes = $source->save();

// --- The part that matters: open existing PDF bytes and edit them. ---
$editor = PdfEditor::fromBytes($sourceBytes);
// A real file would be: $editor = PdfEditor::open('/path/to/document.pdf');

$pages = $editor->resolveDictionary($editor->catalog()->get('Pages'));
$kids = $pages->get('Kids')->items();

$secondPage = $editor->resolveDictionary($kids[1]);
$secondPage->set('Rotate', new PdfInteger(90));
$editor->register($secondPage);

@mkdir(__DIR__ . '/output', recursive: true);
$editor->saveToFile(__DIR__ . '/output/08-editing-existing-pdf.pdf');

echo 'Wrote ' . __DIR__ . "/output/08-editing-existing-pdf.pdf\n";
echo "(An incremental update: the original bytes are preserved verbatim, with only the rotated page's object appended.)\n";
