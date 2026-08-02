<?php

/**
 * Setting document metadata with Document::info().
 *
 * Fully opt-in, the same way /AcroForm is: nothing is allocated or written
 * to /Info until something here is actually set.
 *
 * Run: php examples/12-document-metadata.php
 */

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use MightyPDF\Assembler\Document;
use MightyPDF\Content\Font\StandardFont;
use MightyPDF\Content\PageBuilder;

$document = new Document();
$page = $document->newPage();
$content = new PageBuilder($document, $page);

$content->drawText(StandardFont::HelveticaBold, 24.0, 72, 720, 'Quarterly Report');

$document->info()->setTitle('Quarterly Report');
$document->info()->setAuthor('Jane Doe');
$document->info()->setSubject('Q2 2026 results');
$document->info()->setKeywords('quarterly, report, finance');
$document->info()->setCreator('MightyPDF example 12');
$document->info()->setProducer('MightyPDF');
$document->info()->setCreationDate(new DateTimeImmutable());

@mkdir(__DIR__ . '/output', recursive: true);
$document->saveToFile(__DIR__ . '/output/12-document-metadata.pdf');

echo 'Wrote ' . __DIR__ . "/output/12-document-metadata.pdf\n";
