<?php

/**
 * Links and bookmarks: a contents page whose entries jump to their
 * chapters, a link out to the web, and the bookmark tree a reader shows
 * beside the page.
 *
 * A link draws nothing of its own -- it is a rectangle laid over
 * whatever is already there -- so the blue text below is drawn
 * separately, exactly as it would be for text that is not a link.
 *
 * Run: php examples/15-links-and-bookmarks.php
 */

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use MightyPDF\Assembler\Destination;
use MightyPDF\Assembler\Document;
use MightyPDF\Content\Font\StandardFont;
use MightyPDF\Content\PageBuilder;

$linkBlue = ['r' => 0.10, 'g' => 0.30, 'b' => 0.80];

$document = new Document();

$contentsPage = $document->newPage();
$chapter1 = $document->newPage();
$chapter2 = $document->newPage();

$contents = new PageBuilder($document, $contentsPage);
$contents->drawText(StandardFont::HelveticaBold, 24.0, 72, 720, 'Contents');

$entries = [
    ['1. Where paper sizes come from', $chapter1],
    ['2. What a point is', $chapter2],
];

$y = 670;
foreach ($entries as [$title, $page]) {
    $width = StandardFont::Helvetica->widthOfPt($title, 13.0);

    $contents->drawText(StandardFont::Helvetica, 13.0, 72, $y, $title, ...$linkBlue);
    // The rectangle covers the text: a couple of points below the
    // baseline for descenders, and the font size above it.
    $contents->addInternalLink(72, $y - 3, $width, 16, Destination::of($page, top: 792));

    $y -= 26;
}

$contents->drawText(StandardFont::Helvetica, 11.0, 72, $y - 20, 'The PDF specification (ISO 32000-2)', ...$linkBlue);
$contents->addLink(
    72,
    $y - 23,
    StandardFont::Helvetica->widthOfPt('The PDF specification (ISO 32000-2)', 11.0),
    14,
    'https://www.iso.org/standard/75839.html',
);

$first = new PageBuilder($document, $chapter1);
$first->drawText(StandardFont::HelveticaBold, 18.0, 72, 720, '1. Where paper sizes come from');
$first->drawParagraph(
    StandardFont::Helvetica,
    11.0,
    72,
    560,
    440,
    140,
    'A4 is the sheet whose sides are in the ratio of one to the square root of two, so that halving it '
    . 'gives the same shape again. US Letter is 8.5 by 11 inches because it is.',
);
$first->drawText(StandardFont::Helvetica, 11.0, 72, 520, 'Back to contents', ...$linkBlue);
$first->addInternalLink(72, 517, 90, 14, Destination::fitPage($contentsPage));

$second = new PageBuilder($document, $chapter2);
$second->drawText(StandardFont::HelveticaBold, 18.0, 72, 720, '2. What a point is');
$second->drawParagraph(
    StandardFont::Helvetica,
    11.0,
    72,
    560,
    440,
    140,
    'A PDF point is exactly 1/72 of an inch, which makes a Letter page 612 by 792 of them. Every '
    . 'coordinate in this library is one, measured from the bottom-left corner of the page.',
);
$second->drawText(StandardFont::Helvetica, 11.0, 72, 520, 'Back to contents', ...$linkBlue);
$second->addInternalLink(72, 517, 90, 14, Destination::fitPage($contentsPage));

// The bookmark tree. Adding an item returns it, so sections go under
// chapters; the second chapter starts folded away.
$outline = $document->outline();
$outline->add('Contents', Destination::of($contentsPage));

$one = $outline->add('1. Where paper sizes come from', Destination::of($chapter1));
$one->add('The square-root-of-two sheet', Destination::of($chapter1, top: 600));
$one->add('Letter', Destination::of($chapter1, top: 480));

$outline->add('2. What a point is', Destination::of($chapter2), open: false)
    ->add('Coordinates', Destination::of($chapter2, top: 600));

@mkdir(__DIR__ . '/output', recursive: true);
$document->saveToFile(__DIR__ . '/output/15-links-and-bookmarks.pdf');

echo 'Wrote ' . __DIR__ . "/output/15-links-and-bookmarks.pdf\n";
