<?php

/**
 * A more realistic document combining everything: text, a logo (SVG),
 * a photo (JPEG), shapes, and form fields, across two pages -- showing
 * how the PageBuilder methods you've seen individually in the other
 * examples work together in one document.
 *
 * Run: php examples/07-combined-document.php
 */

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use MightyPDF\Assembler\Document;
use MightyPDF\Content\Font\StandardFont;
use MightyPDF\Content\PageBuilder;

$fixtureImages = __DIR__ . '/../tests/fixtures/images';
$fixtureSvg = __DIR__ . '/../tests/fixtures/svg/sample.svg';

$document = new Document();

// --- Page 1: a simple letterhead with a photo and a divider line ---
$page1 = $document->newPage();
$content1 = new PageBuilder($document, $page1);

$content1->drawSvg($fixtureSvg, 72, 730, 40, 40);
$content1->drawText(StandardFont::HelveticaBold, 20.0, 120, 745, 'Acme Corporation');
$content1->drawLine(x1: 72, y1: 715, x2: 540, y2: 715, lineWidthPt: 1.0);

$content1->drawText(StandardFont::TimesRoman, 12.0, 72, 680, 'Dear customer,');
$content1->drawText(StandardFont::TimesRoman, 12.0, 72, 660, 'Thank you for your continued business. Please find your photo below.');

$content1->drawJpeg("$fixtureImages/sample.jpg", 72, 520, 120, 120);
$content1->strokeRectangle(x: 72, y: 520, width: 120, height: 120, lineWidthPt: 0.5);

// --- Page 2: a short registration form ---
$page2 = $document->newPage();
$content2 = new PageBuilder($document, $page2);

$content2->drawText(StandardFont::HelveticaBold, 18.0, 72, 740, 'Registration Form');
$content2->drawLine(x1: 72, y1: 730, x2: 540, y2: 730, lineWidthPt: 1.0);

$content2->drawText(StandardFont::Helvetica, 11.0, 72, 690, 'Full name:');
$content2->addTextField('full_name', x: 200, y: 685, width: 250, height: 20);

$content2->drawText(StandardFont::Helvetica, 11.0, 72, 655, 'Email:');
$content2->addTextField('email', x: 200, y: 650, width: 250, height: 20);

$content2->drawText(StandardFont::Helvetica, 11.0, 72, 620, 'I agree to the terms and conditions:');
$content2->addCheckbox('agree', x: 320, y: 617, size: 14);

@mkdir(__DIR__ . '/output', recursive: true);
$document->saveToFile(__DIR__ . '/output/07-combined-document.pdf');

echo 'Wrote ' . __DIR__ . "/output/07-combined-document.pdf\n";
