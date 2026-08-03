<?php

/**
 * Placing an SVG vector image on a page. Supports paths (lines, curves,
 * arcs), basic shapes (rect/circle/ellipse/line/polyline/polygon),
 * fill/stroke in flat colours, gradients or patterns, opacity, text
 * (including text on a path), embedded images and simple transforms --
 * see src/Content/Svg/SvgDocument.php for the exact scope (no filters
 * or animation).
 *
 * Run: php examples/05-svg.php
 */

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use MightyPDF\Assembler\Document;
use MightyPDF\Content\Font\StandardFont;
use MightyPDF\Content\PageBuilder;

$svgFile = __DIR__ . '/../tests/fixtures/svg/sample.svg';
$gradientFile = __DIR__ . '/../tests/fixtures/svg/gradient.svg';

$document = new Document();
$page = $document->newPage();
$content = new PageBuilder($document, $page);

$content->drawText(StandardFont::HelveticaBold, 24.0, 72, 720, 'SVG vector graphics');

// The same SVG placed at two different sizes -- it's vector data, so it
// scales cleanly with no loss of quality (unlike a raster image).
$content->drawSvg($svgFile, 72, 590, 110, 110);
$content->drawSvg($svgFile, 220, 530, 180, 180);

// Gradients become PDF shading patterns. A gradient in the default
// "objectBoundingBox" units is measured across the shape it paints, so
// the same definition fits each shape it is used on -- and follows them
// through whatever transforms they sit under.
$content->drawText(StandardFont::Helvetica, 12.0, 72, 490, 'Gradients, at two sizes:');
$content->drawSvg($gradientFile, 72, 350, 130, 130);
$content->drawSvg($gradientFile, 215, 350, 145, 130);

// A <pattern> becomes a PDF tiling pattern: the tile is drawn once and
// repeated across the shape, still as vector artwork rather than a
// rasterised swatch.
$content->drawText(StandardFont::Helvetica, 12.0, 400, 490, 'A pattern fill:');
$content->drawSvg(__DIR__ . '/../tests/fixtures/svg/pattern.svg', 400, 350, 130, 130);

// Text in a drawing is drawn as text -- searchable and selectable, not
// outlines. The font families an SVG names map onto the standard 14
// unless a resolver says otherwise (see the README).
$content->drawText(StandardFont::Helvetica, 12.0, 72, 320, 'Text inside the drawing:');
$content->drawSvg(__DIR__ . '/../tests/fixtures/svg/label.svg', 72, 170, 280, 140);

// Styling written as CSS rather than as attributes -- which is how
// drawing tools export: classes on the shapes, rules in a <style> block.
$content->drawSvg(__DIR__ . '/../tests/fixtures/svg/styled.svg', 380, 195, 180, 108);

// <textPath>: text laid along a path, a glyph at a time, each turned to
// face the direction the path is going at that point.
$content->drawSvg(__DIR__ . '/../tests/fixtures/svg/text-on-a-path.svg', 330, 40, 230, 150);

// stop-opacity: a gradient that fades out rather than changing colour.
// PDF colours carry no transparency, so this is drawn twice -- once in
// colour, once in greyscale as a soft mask (see the README).
$content->drawText(StandardFont::Helvetica, 12.0, 72, 140, 'Gradients that fade out:');
$content->drawSvg(__DIR__ . '/../tests/fixtures/svg/fading-gradient.svg', 72, 60, 220, 70);

@mkdir(__DIR__ . '/output', recursive: true);
$document->saveToFile(__DIR__ . '/output/05-svg.pdf');

echo 'Wrote ' . __DIR__ . "/output/05-svg.pdf\n";
