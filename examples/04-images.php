<?php

/**
 * Embedding JPEG, PNG, and GIF images. Uses the small fixture images
 * from the test suite so this script has no external dependencies --
 * point drawJpeg()/drawPng()/drawGif() at any real file of that type in
 * your own code.
 *
 * Run: php examples/04-images.php
 */

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use MightyPDF\Assembler\Document;
use MightyPDF\Content\Font\StandardFont;
use MightyPDF\Content\PageBuilder;

$fixtures = __DIR__ . '/../tests/fixtures/images';

$document = new Document();
$page = $document->newPage();
$content = new PageBuilder($document, $page);

$content->drawText(StandardFont::HelveticaBold, 24.0, 72, 720, 'Images: JPEG / PNG / GIF');

$content->drawText(StandardFont::Helvetica, 10.0, 72, 660, 'JPEG (original file bytes embedded verbatim, no re-encoding):');
$content->drawJpeg("$fixtures/sample.jpg", 72, 560, 100, 100);

$content->drawText(StandardFont::Helvetica, 10.0, 220, 660, 'PNG (IDAT relayed verbatim, no decompress/recompress):');
$content->drawPng("$fixtures/sample.png", 220, 560, 100, 100);

$content->drawText(StandardFont::Helvetica, 10.0, 368, 660, 'GIF (decoded to indexed color; supports transparency):');
$content->drawGif("$fixtures/sample-transparent.gif", 368, 560, 100, 100);

@mkdir(__DIR__ . '/output', recursive: true);
$document->saveToFile(__DIR__ . '/output/04-images.pdf');

echo 'Wrote ' . __DIR__ . "/output/04-images.pdf\n";
