<?php

/**
 * Lines, filled rectangles, and stroked rectangles, with custom colors
 * and line widths.
 *
 * Run: php examples/03-lines-and-shapes.php
 */

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use MightyPDF\Assembler\Document;
use MightyPDF\Content\Font\StandardFont;
use MightyPDF\Content\PageBuilder;

$document = new Document();
$page = $document->newPage();
$content = new PageBuilder($document, $page);

$content->drawText(StandardFont::HelveticaBold, 24.0, 72, 720, 'Lines and shapes');

// A filled rectangle (r, g, b each 0.0-1.0).
$content->fillRectangle(x: 72, y: 600, width: 150, height: 80, r: 0.2, g: 0.4, b: 0.9);

// A stroked (outline-only) rectangle with a thicker border.
$content->strokeRectangle(x: 260, y: 600, width: 150, height: 80, lineWidthPt: 3.0, r: 0.9, g: 0.1, b: 0.1);

// Lines with different widths and colors.
$content->drawLine(x1: 72, y1: 560, x2: 410, y2: 560, lineWidthPt: 1.0);
$content->drawLine(x1: 72, y1: 540, x2: 410, y2: 500, lineWidthPt: 5.0, r: 0.0, g: 0.6, b: 0.2);

// You can chain calls, since every drawing method returns $this.
$content
    ->fillRectangle(72, 440, 40, 40, 1.0, 0.0, 0.0)
    ->fillRectangle(122, 440, 40, 40, 0.0, 1.0, 0.0)
    ->fillRectangle(172, 440, 40, 40, 0.0, 0.0, 1.0);

@mkdir(__DIR__ . '/output', recursive: true);
$document->saveToFile(__DIR__ . '/output/03-lines-and-shapes.pdf');

echo 'Wrote ' . __DIR__ . "/output/03-lines-and-shapes.pdf\n";
