# MightyPDF

A from-scratch PDF library for PHP: raw PDF assembly plus a content layer
for text, drawing, images, SVG, barcodes and AcroForm fields, a layout
layer with cells and tables on top of it — and a reader that can open an
existing PDF and edit it in place.

Requires **PHP 8.3+**, `ext-iconv`, `ext-openssl`, and `ext-zlib`.

## Installation

```bash
composer require jamesthomsen/mightypdf
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
| [08-editing-existing-pdf.php](examples/08-editing-existing-pdf.php) | Opening a PDF with `PdfEditor` and editing an object (page rotation) |
| [09-encrypting-a-document.php](examples/09-encrypting-a-document.php) | AES-256 encryption with `Document::encrypt()` and `Permissions` |
| [10-filling-an-existing-form.php](examples/10-filling-an-existing-form.php) | Filling an existing PDF's form fields with `FormFiller` |
| [11-stamping-an-existing-page.php](examples/11-stamping-an-existing-page.php) | Stamping a page with `PageOverlay`, plus adding a field to an existing form |
| [12-document-metadata.php](examples/12-document-metadata.php) | Setting Title/Author/Subject/Keywords/Creator/Producer/CreationDate |
| [13-merging-documents.php](examples/13-merging-documents.php) | Combining pages from multiple PDFs into one with `PdfMerger` |
| [14-embedding-a-font.php](examples/14-embedding-a-font.php) | Embedding a TrueType or OpenType font, and pointing a form field at one |
| [15-links-and-bookmarks.php](examples/15-links-and-bookmarks.php) | Links out of and inside a document, and a bookmark tree |
| [16-a-report-with-the-layout-layer.php](examples/16-a-report-with-the-layout-layer.php) | A business report through `Flow`: cells, a table, a chart, a footer on every page |
| [17-shapes-transforms-and-transparency.php](examples/17-shapes-transforms-and-transparency.php) | Paths, ellipses, polygons, dashes, and scoped transforms, clips and alpha |
| [18-a-table-that-breaks-across-pages.php](examples/18-a-table-that-breaks-across-pages.php) | `Table`: wrapping cells, a repeating header, colspans, striping |
| [19-barcodes-and-qr-codes.php](examples/19-barcodes-and-qr-codes.php) | Code 39, Code 128, EAN-13, UPC-A, and QR at all four levels |
| [20-print-colours.php](examples/20-print-colours.php) | CMYK, a spot colour at five tints, and a dieline separation |
| [21-attachments-and-viewer-preferences.php](examples/21-attachments-and-viewer-preferences.php) | An invoice carrying its own XML, viewer preferences, a rotated page |
| [24-a-print-ready-flyer-with-bleed.php](examples/24-a-print-ready-flyer-with-bleed.php) | Bleed and trim boxes, artwork off the edge, margins from the cut |
| [25-form-data-in-and-out.php](examples/25-form-data-in-and-out.php) | Exporting a filled form as XFDF and JSON, and importing both back |

## Core concepts

**`Document`** is the top-level object. It owns every page and every
indirect object in the file, and is responsible for producing final PDF
bytes.

- `new Document()` — start a new, empty document.
- `$document->newPage(PageSize|PdfRectangle|null $mediaBox = null): Page`
  — append a page and return it. Defaults to US Letter (612×792pt); pass
  a `PageSize` (`PageSize::A4`) or a `PdfRectangle` for anything else.
- `$document->pages(): array` — all pages added so far.
- `$document->save(): string` — serialize the whole document to a PDF
  byte string.
- `$document->saveToFile(string $path): void` — serialize and write to
  disk in one step.
- `$document->writeTo($handle): void` — serialize straight to an open
  stream. See [large documents](docs/output.md#large-documents).

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
Colors are RGB with each channel in the `0.0`–`1.0` range; `Color`
converts from hex and 0–255.

If you would rather work in millimetres from the top-left, with a
cursor, cells and automatic page breaks, see [the layout
layer](docs/layout.md#the-layout-layer) — it is built on everything below and replaces
none of it.

## Documentation

The reference used to live in this file. It outgrew it, so it is now
next door in [`docs/`](docs/) — one page per thing you might be trying
to do.

**Putting marks on a page**

- [Text and fonts](docs/text-and-fonts.md) — the standard 14, embedding
  TrueType and OpenType, wrapping, alignment, Unicode
- [Drawing](docs/drawing.md) — lines, shapes, paths, transforms,
  clipping, transparency, and colour in RGB, CMYK or a named ink
- [Images](docs/images.md) — JPEG, PNG, GIF and TIFF
- [SVG](docs/svg.md) — paths, gradients, patterns, text, CSS
- [Barcodes](docs/barcodes.md) — Code 128, EAN-13, QR, Data Matrix

**Laying a document out**

- [The layout layer](docs/layout.md) — millimetres from the top-left,
  cells, tables, columns, automatic page breaks, headers and footers
- [Forms](docs/forms.md) — creating AcroForm fields, filling them in,
  reading the data back, flattening
- [Document features](docs/document-features.md) — links, bookmarks,
  metadata, attachments, how the file asks to be opened
- [Print production](docs/print-production.md) — bleed, trim and the
  other page boxes
- [Accessibility and tagging](docs/accessibility.md) — a structure tree,
  reading order, alternate text

**Producing and reworking files**

- [Producing the file](docs/output.md) — compression, documents larger
  than memory, encryption, and what this library throws when it fails
- [Editing existing PDFs](docs/editing.md) — opening, stamping, merging,
  splitting, extracting text, signing

**Before you file a bug**

- [Known limitations](docs/limitations.md) — what this does not do, and
  which of those are deliberate
- [Upgrading](docs/upgrading.md) — what changed between versions

## Development

```bash
composer install
vendor/bin/phpunit
```

Tests live in [`tests/`](tests/), mirroring the `src/` structure.
