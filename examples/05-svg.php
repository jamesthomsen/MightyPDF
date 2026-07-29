<?php

/**
 * Placing an SVG vector image on a page. Supports paths (lines, curves,
 * arcs), basic shapes (rect/circle/ellipse/line/polyline/polygon), solid
 * fill/stroke colors, opacity, and simple transforms -- see
 * src/Content/Svg/SvgDocument.php for the exact scope (no gradients,
 * patterns, filters, embedded images, text, or animation).
 *
 * Run: php examples/05-svg.php
 */

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use MightyPDF\Assembler\Document;
use MightyPDF\Content\Font\StandardFont;
use MightyPDF\Content\PageBuilder;

$svgFile = __DIR__ . '/../tests/fixtures/svg/sample.svg';

$document = new Document();
$page = $document->newPage();
$content = new PageBuilder($document, $page);

$content->drawText(StandardFont::HelveticaBold, 24.0, 72, 720, 'SVG vector graphics');

// The same SVG placed at two different sizes -- it's vector data, so it
// scales cleanly with no loss of quality (unlike a raster image).
$content->drawSvg($svgFile, 72, 560, 120, 120);
$content->drawSvg($svgFile, 240, 480, 240, 240);

@mkdir(__DIR__ . '/output', recursive: true);
$document->saveToFile(__DIR__ . '/output/05-svg.pdf');

echo 'Wrote ' . __DIR__ . "/output/05-svg.pdf\n";
