<?php

/**
 * The simplest possible MightyPDF program: create a document, add a
 * couple of blank pages, save it.
 *
 * Run: php examples/01-blank-document.php
 */

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use MightyPDF\Assembler\Document;
use MightyPDF\Assembler\Types\PdfRectangle;

$document = new Document();

// Letter size (612x792pt) by default.
$document->newPage();

// You can also pass a custom MediaBox -- here's an A4 page.
$document->newPage(new PdfRectangle(0, 0, 595.28, 841.89));

@mkdir(__DIR__ . '/output', recursive: true);
$document->saveToFile(__DIR__ . '/output/01-blank-document.pdf');

echo 'Wrote ' . __DIR__ . "/output/01-blank-document.pdf\n";
