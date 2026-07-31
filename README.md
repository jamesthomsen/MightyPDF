# MightyPDF

A from-scratch PDF writer for PHP: raw PDF assembly plus a content layer
for text, drawing, images, SVG, and AcroForm fields. There is no reader
or parser (yet) — MightyPDF only *creates* PDFs.

Requires **PHP 8.3+**, `ext-iconv`, and `ext-zlib`.

## Installation

```bash
composer require jthomsen/mightypdf
```

## Quick start

```php
require 'vendor/autoload.php';

use MightyPDF\Assembler\Document;
use MightyPDF\Content\Font\StandardFont;
use MightyPDF\Content\PageBuilder;

$document = new Document();
$page = $document->newPage();
$content = new PageBuilder($document, $page);

$content->drawText(StandardFont::HelveticaBold, 24.0, 72, 720, 'Hello, MightyPDF!');

$document->saveToFile('hello.pdf');
```

Runnable, more elaborate versions of every example below live in
[`examples/`](examples/) — run any of them with `php examples/0X-name.php`;
output is written to `examples/output/`.

| File | Demonstrates |
|---|---|
| [01-blank-document.php](examples/01-blank-document.php) | Minimal document, custom page size |
| [02-text-and-fonts.php](examples/02-text-and-fonts.php) | All 14 standard fonts, measuring text |
| [03-lines-and-shapes.php](examples/03-lines-and-shapes.php) | Lines, filled/stroked rectangles, method chaining |
| [04-images.php](examples/04-images.php) | JPEG, PNG, GIF |
| [05-svg.php](examples/05-svg.php) | SVG vector graphics |
| [06-form-fields.php](examples/06-form-fields.php) | Text fields and checkboxes |
| [07-combined-document.php](examples/07-combined-document.php) | Everything together, across multiple pages |

## Core concepts

**`Document`** is the top-level object. It owns every page and every
indirect object in the file, and is responsible for producing final PDF
bytes.

- `new Document()` — start a new, empty document.
- `$document->newPage(?PdfRectangle $mediaBox = null): Page` — append a
  page and return it. Defaults to US Letter (612×792pt); pass a
  `PdfRectangle` for a different size (e.g. A4 is `new PdfRectangle(0, 0,
  595.28, 841.89)`).
- `$document->pages(): array` — all pages added so far.
- `$document->save(): string` — serialize the whole document to a PDF
  byte string.
- `$document->saveToFile(string $path): void` — serialize and write to
  disk in one step.

**`PageBuilder`** is where you actually draw. Each page gets its own
builder:

```php
$page = $document->newPage();
$content = new PageBuilder($document, $page);
```

Every draw call appends to that page's single content stream and returns
`$this`, so calls can be chained:

```php
$content
    ->fillRectangle(72, 440, 40, 40, 1.0, 0.0, 0.0)
    ->fillRectangle(122, 440, 40, 40, 0.0, 1.0, 0.0)
    ->fillRectangle(172, 440, 40, 40, 0.0, 0.0, 1.0);
```

**Coordinates and units.** Everything is in PDF points (1/72 inch),
measured from the page's **bottom-left corner**, X increasing right and
Y increasing up — standard PDF convention, not screen/CSS convention.
Colors are RGB with each channel in the `0.0`–`1.0` range.

## Text and fonts

MightyPDF uses PDF's 14 standard fonts (`Helvetica`, `Times`, `Courier`
in their regular/bold/italic/bold-italic variants, plus `Symbol` and
`ZapfDingbats`). These are built into every conforming PDF reader, so no
font files are embedded — text just works, with no extra setup.

```php
use MightyPDF\Content\Font\StandardFont;

$content->drawText(StandardFont::HelveticaBold, 24.0, 72, 720, 'Text and fonts');
```

`drawText(StandardFont $font, float $sizePt, float $x, float $y, string $text)`
draws `$text` with its baseline at `($x, $y)`.

Available cases: `Helvetica`, `HelveticaBold`, `HelveticaOblique`,
`HelveticaBoldOblique`, `TimesRoman`, `TimesBold`, `TimesItalic`,
`TimesBoldItalic`, `Courier`, `CourierBold`, `CourierOblique`,
`CourierBoldOblique`, `Symbol`, `ZapfDingbats`.

Text is transcoded to WinAnsiEncoding (≈ CP1252) internally. Characters
outside that repertoire are transliterated to the nearest ASCII
equivalent (e.g. curly quotes → straight quotes) rather than failing —
full Unicode text would require embedding a font program, which is out
of scope for v1.

**Measuring text.** `StandardFont::metrics()` returns a `FontMetrics`
object for layout math (e.g. centering):

```php
use MightyPDF\Assembler\Types\WinAnsiEncoding;

$metrics = StandardFont::Helvetica->metrics();
$encoded = WinAnsiEncoding::encode($text);
$width = $metrics->widthOf($encoded, $sizePt); // width in points
$x = ($pageWidth - $width) / 2;
```

## Lines and shapes

```php
// Filled rectangle (r, g, b each 0.0-1.0)
$content->fillRectangle(x: 72, y: 600, width: 150, height: 80, r: 0.2, g: 0.4, b: 0.9);

// Stroked (outline-only) rectangle
$content->strokeRectangle(x: 260, y: 600, width: 150, height: 80, lineWidthPt: 3.0, r: 0.9, g: 0.1, b: 0.1);

// A line
$content->drawLine(x1: 72, y1: 560, x2: 410, y2: 560, lineWidthPt: 1.0, r: 0.0, g: 0.6, b: 0.2);
```

All three accept named arguments; color and line width default to black
and `1.0`pt.

For anything not covered by these convenience methods, drop down to
`ContentStream` directly and hand it to the page with `drawCustom()`:

```php
use MightyPDF\Content\ContentStream;

$ops = (new ContentStream())->setLineWidth(2.0)->setStrokeColorRgb(0, 0, 0)
    ->moveTo(100, 100)->lineTo(200, 200)->stroke();
$content->drawCustom($ops);
```

## Images

```php
$content->drawJpeg($path, x: 72,  y: 560, width: 100, height: 100);
$content->drawPng ($path, x: 220, y: 560, width: 100, height: 100);
$content->drawGif ($path, x: 368, y: 560, width: 100, height: 100);
```

All three take a file path and a placement rectangle (`x`, `y` = bottom-left
corner, `width`/`height` in points — the image is scaled to fit, not
cropped).

- **JPEG**: original file bytes are embedded verbatim (no re-encoding).
- **PNG**: non-interlaced, no-alpha `IDAT` data is relayed verbatim (no
  decompress/recompress). PNGs with a baked-in alpha channel (color types
  4/6) are split into a color image plus a `/SMask`; interlaced (Adam7)
  PNGs of any color type are de-interlaced first. Both are decode/re-encode
  paths supported at 8 or 16 bits per channel; sub-byte bit depths
  (1/2/4, grayscale/indexed only) aren't supported combined with
  interlacing.
- **GIF**: decoded to indexed color; transparency is supported.

## SVG vector graphics

```php
$content->drawSvg($path, x: 72, y: 560, width: 120, height: 120);
```

The SVG is placed and scaled to fit the given rectangle. Because it's
vector data (not a raster image), it stays crisp at any size — the same
file can be drawn small and large with no quality loss.

Supported: paths (lines, curves, arcs), basic shapes (`rect`, `circle`,
`ellipse`, `line`, `polyline`, `polygon`), solid fill/stroke colors,
opacity, and simple transforms (`translate`/`scale`/`rotate`/`skew`/`matrix`).

**Not supported** (elements are skipped, not mis-rendered): gradients,
patterns, filters, embedded raster images, text, CSS cascading beyond a
flat `style` attribute, and animation. See
[`src/Content/Svg/SvgDocument.php`](src/Content/Svg/SvgDocument.php) for
the exact scope.

## Form fields (AcroForm)

Two field types are supported: single-line text fields and checkboxes.
Adding either one lazily creates the document's single shared `/AcroForm`
the first time it's needed — every field on every page ends up listed
together in that one form.

```php
$content->addTextField('first_name', x: 200, y: 665, width: 250, height: 20, value: 'Jane');
$content->addTextField('comments', x: 200, y: 630, width: 250, height: 20, maxLength: 200);

$content->addCheckbox('subscribe', x: 280, y: 592, size: 14, checked: true);
```

`addTextField(string $name, float $x, float $y, float $width, float $height, ?string $value = null, StandardFont $font = StandardFont::Helvetica, float $fontSizePt = 10.0, ?int $maxLength = null)`

`addCheckbox(string $name, float $x, float $y, float $size, bool $checked = false)`

Text fields rely on `/NeedsAppearances` so the PDF reader regenerates
the visible text itself from the field's value and appearance string —
this is standard, reader-supported behavior. Checkboxes instead carry
their own small on/off appearance streams (a simple checkmark drawn with
`ContentStream`), since readers are less consistent about regenerating
checkbox appearances automatically.

## Multi-page documents

Call `newPage()` again and build a new `PageBuilder` for each page — see
[`examples/07-combined-document.php`](examples/07-combined-document.php)
for a full example combining text, an SVG logo, a photo, shapes, and
form fields across two pages.

```php
$page1 = $document->newPage();
$content1 = new PageBuilder($document, $page1);
// ... draw page 1 ...

$page2 = $document->newPage();
$content2 = new PageBuilder($document, $page2);
// ... draw page 2 ...

$document->saveToFile('output.pdf');
```

## Known limitations

- **Writer only** — there is no PDF parser/reader; MightyPDF cannot open
  or edit an existing PDF.
- **Text**: standard 14 fonts only, WinAnsi/CP1252 repertoire (no custom
  font embedding, no full Unicode).
- **SVG**: see the "not supported" list above.
- **Forms**: text fields and checkboxes only — no radio buttons, list
  boxes, dropdowns, or signature fields.

## Development

```bash
composer install
vendor/bin/phpunit
```

Tests live in [`tests/`](tests/), mirroring the `src/` structure.
