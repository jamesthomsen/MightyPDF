<?php

/**
 * Drawing text with the 14 standard PDF fonts -- no font embedding
 * needed, every conforming reader has these built in.
 *
 * Run: php examples/02-text-and-fonts.php
 */

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use MightyPDF\Assembler\Document;
use MightyPDF\Assembler\Types\WinAnsiEncoding;
use MightyPDF\Content\Font\StandardFont;
use MightyPDF\Content\PageBuilder;

$document = new Document();
$page = $document->newPage();
$content = new PageBuilder($document, $page);

$content->drawText(StandardFont::HelveticaBold, 24.0, 72, 720, 'Text and fonts');

$y = 680;
$fonts = [
    [StandardFont::Helvetica, 'Helvetica'],
    [StandardFont::HelveticaBold, 'Helvetica Bold'],
    [StandardFont::HelveticaOblique, 'Helvetica Oblique'],
    [StandardFont::TimesRoman, 'Times Roman'],
    [StandardFont::TimesBold, 'Times Bold'],
    [StandardFont::TimesItalic, 'Times Italic'],
    [StandardFont::Courier, 'Courier (monospace)'],
];

foreach ($fonts as [$font, $label]) {
    $content->drawText($font, 12.0, 72, $y, "The quick brown fox -- $label");
    $y -= 24;
}

// FontMetrics lets you measure text -- handy for centering or wrapping.
$metrics = StandardFont::Helvetica->metrics();
$text = 'Centered using FontMetrics::widthOf()';
$sizePt = 16.0;
$encoded = WinAnsiEncoding::encode($text);
$width = $metrics->widthOf($encoded, $sizePt);
$pageWidth = 612.0;
$content->drawText(StandardFont::Helvetica, $sizePt, ($pageWidth - $width) / 2, $y - 40, $text);

@mkdir(__DIR__ . '/output', recursive: true);
$document->saveToFile(__DIR__ . '/output/02-text-and-fonts.pdf');

echo 'Wrote ' . __DIR__ . "/output/02-text-and-fonts.pdf\n";
