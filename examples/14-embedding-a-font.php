<?php

/**
 * Embedding a TrueType font, which is what text outside the WinAnsi
 * repertoire needs: Greek, Cyrillic, CJK, or simply a typeface that
 * isn't one of the standard 14.
 *
 * Only the glyphs this document draws are embedded, so the font costs a
 * few kilobytes rather than the few hundred the file on disk weighs.
 *
 * Run: php examples/14-embedding-a-font.php
 */

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use MightyPDF\Assembler\Document;
use MightyPDF\Content\Font\EmbeddedFont;
use MightyPDF\Content\Font\StandardFont;
use MightyPDF\Content\PageBuilder;

// Any .ttf will do -- this looks for one of the fonts a Linux, macOS or
// Windows machine is likely to already have, so the example runs
// anywhere without shipping a font of its own.
$candidates = [
    '/usr/share/fonts/truetype/dejavu/DejaVuSans.ttf',
    '/usr/share/fonts/truetype/liberation/LiberationSans-Regular.ttf',
    '/usr/share/fonts/TTF/DejaVuSans.ttf',
    '/Library/Fonts/Arial Unicode.ttf',
    '/System/Library/Fonts/Supplemental/Arial Unicode.ttf',
    'C:\\Windows\\Fonts\\arial.ttf',
];

$path = null;
foreach ($candidates as $candidate) {
    if (is_file($candidate)) {
        $path = $candidate;
        break;
    }
}

if ($path === null) {
    fwrite(STDERR, "No TrueType font found. Edit \$candidates in this file to point at any .ttf.\n");
    exit(1);
}

$font = EmbeddedFont::load($path);
echo "Embedding {$font->name()} from $path\n";

$document = new Document();
$content = new PageBuilder($document, $document->newPage());

$content->drawText(StandardFont::HelveticaBold, 20.0, 72, 720, 'Embedding a font');

// The point of embedding: text a standard font would transliterate away.
$samples = [
    'Ünicode, naïve, Größe — em dashes and all',
    'Ελληνικά · Русский · ĲsBĳl',
    'Mathematics: ∑ ∫ ≈ ½ ¾ €',
];

$y = 680;
foreach ($samples as $sample) {
    // A font contains what it contains: ask before drawing rather than
    // finding out one character at a time.
    $missing = $font->missingCharacters($sample);

    if ($missing !== []) {
        echo 'Skipping a line: this font has no ' . implode(' ', $missing) . "\n";
        continue;
    }

    $content->drawText($font, 14.0, 72, $y, $sample);
    $y -= 26;
}

// Paragraphs work the same way. Justification is done with TJ
// adjustments here rather than the word-spacing operator, which has no
// effect on a font with two-byte character codes.
$content->drawParagraph(
    $font,
    11.0,
    72,
    $y - 120,
    440,
    110,
    "Only the glyphs actually drawn are embedded, so this paragraph and the lines above it "
    . "carry a font of a few kilobytes rather than the several hundred the file on disk weighs. "
    . "The document also carries a ToUnicode map, so selecting this text in a reader and copying "
    . "it gives back the characters, not glyph numbers.",
    align: 'J',
);

@mkdir(__DIR__ . '/output', recursive: true);
$document->saveToFile(__DIR__ . '/output/14-embedding-a-font.pdf');

printf(
    "Wrote %s (%.1f KB, from a %.0f KB font)\n",
    __DIR__ . '/output/14-embedding-a-font.pdf',
    filesize(__DIR__ . '/output/14-embedding-a-font.pdf') / 1024,
    filesize($path) / 1024,
);
