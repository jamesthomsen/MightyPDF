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
  stream. See [large documents](#large-documents).

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
layer](#the-layout-layer) — it is built on everything below and replaces
none of it.

## Text and fonts

Two kinds of font: PDF's 14 standard ones, which need nothing embedded,
and any TrueType file you point at, which does. Both are drawn with the
same calls.

### Standard fonts

`Helvetica`, `Times` and `Courier` in their
regular/bold/italic/bold-italic variants, plus `Symbol` and
`ZapfDingbats`. These are built into every conforming PDF reader, so no
font files are embedded — text just works, with no extra setup.

```php
use MightyPDF\Content\Font\StandardFont;

$content->drawText(StandardFont::HelveticaBold, 24.0, 72, 720, 'Text and fonts');
```

`drawText(Font $font, float $sizePt, float $x, float $y, string $text)`
draws `$text` with its baseline at `($x, $y)`.

Available cases: `Helvetica`, `HelveticaBold`, `HelveticaOblique`,
`HelveticaBoldOblique`, `TimesRoman`, `TimesBold`, `TimesItalic`,
`TimesBoldItalic`, `Courier`, `CourierBold`, `CourierOblique`,
`CourierBoldOblique`, `Symbol`, `ZapfDingbats`.

Text drawn in one of these is transcoded to WinAnsiEncoding (≈ CP1252).
That covers more than Latin-1 — the euro sign, en and em dashes, curly
quotes and the `œ` ligature are all in it. Characters outside it are
transliterated to the nearest equivalent (`Ł` → `L`, `ﬁ` → `fi`), and a
character with no transliteration at all is drawn as `?`. Encoding never
fails and never silently drops text. For text that has to keep its own
characters, embed a font.

To find out before drawing rather than after, ask the font:

```php
StandardFont::Helvetica->supports('Ταβέρνα');          // false
StandardFont::Helvetica->missingCharacters('Łódź');    // ['Ł', 'ź']
```

Both are on the `Font` interface, so code that draws in whatever font it
was handed can ask either kind. The two answer the same question — which
characters this font cannot draw as themselves — but the consequence
differs: a standard font approximates them, an embedded font refuses to
draw at all (see below).

### Embedding a TrueType font

```php
use MightyPDF\Content\Font\EmbeddedFont;

$font = EmbeddedFont::load('/path/to/Inter-Regular.ttf');

$content->drawText($font, 14.0, 72, 700, 'Ελληνικά · Русский · Größe — ∑ €');
```

An `EmbeddedFont` goes anywhere a `StandardFont` does. Text drawn with
one can hold any character the font itself contains, and the document
carries a `/ToUnicode` map, so selecting and copying the text in a
reader gives back the characters rather than glyph numbers.

**Only the glyphs you draw are embedded.** The font is subset as the
document is written and the program is built at save time, when the used
set is finally known — a page of text typically costs a few kilobytes
against a font file of several hundred. Pass `subset: false` to embed
the file whole, which is what a form field's font has to be (see below):
a whole font is addressed by character rather than by glyph number, so a
reader can draw text in it that this document never drew.

**A font contains what it contains.** Drawing a character the font has
no glyph for throws rather than drawing an empty box, since a box is
invisible in review and obvious in print. Ask first where the text is
not known in advance:

```php
$missing = $font->missingCharacters($text); // ['字', '한'] — each one once
$font->supports($text);                     // or just: can it draw this?
```

**OpenType/CFF (`.otf`) works too, embedded whole.** A font with
PostScript outlines goes into the document as a `CIDFontType0` with the
whole OpenType file under `/FontFile3` — so it must be loaded with
`subset: false`, since subsetting one means taking its charstrings
apart. A *CID-keyed* CFF (the shape a CJK OpenType font usually takes)
is refused by name: its glyphs are addressed through a character
collection of its own rather than by index, and embedding one anyway
would draw the wrong glyphs rather than fail. Font collections (`.ttc`)
are refused too — the file holds several fonts and nothing here would
say which one was meant.

See [`examples/14-embedding-a-font.php`](examples/14-embedding-a-font.php)
for a runnable version.

### Wrapped text

`drawParagraph()` word-wraps into a box. `($x, $y)` is the box's
**bottom-left** corner, matching `fillRectangle()` and images:

```php
$content->drawParagraph($font, 11.0, 72, 600, 200, 120, $text,
    align: 'J', valign: 'T', lineHeightPt: 14.0);
```

`align` is `'L'` (default), `'C'`, `'R'` or `'J'`; `valign` is `'T'`
(default), `'M'` or `'B'`; `lineHeightPt` defaults to 1.15 × the size.

The box is not auto-sized — measure first and pass the height in:

```php
use MightyPDF\Content\Text\TextWrapper;

$lines  = TextWrapper::wrapUtf8($text, $font, 11.0, 200);
$height = count($lines) * 14.0;
```

### Text in a box

`drawTextInBox()` places one line inside a rectangle — the single call
that a fill, a width measurement and a piece of vertical arithmetic used
to be:

```php
use MightyPDF\Content\Text\{HorizontalAlign, VerticalAlign};

$content->drawTextInBox($font, 12.0, 72, 600, 200, 24, 'Total due',
    HorizontalAlign::Right, VerticalAlign::Middle);
```

`VerticalAlign` has two middles, and picking the right one matters more
as the type gets bigger:

| | centres | use for |
|---|---|---|
| `Top` | ascent hung from the top edge | text that grows downwards |
| `Middle` | the em box, ascent to descent | running prose, mixed case |
| `CapMiddle` | the capitals, baseline to cap height | labels, headings, figures, a single large letter |
| `Bottom` | the descent on the bottom edge | last lines of unequal columns |

`Middle` keeps a line in place when the wording gains or loses a
descender. `CapMiddle` is what the eye reads as centred for anything
with nothing below the baseline — centred on the em box, a lone capital
sits high by half the descent, which is a point at 10pt and most of a
centimetre at 270pt.

`drawParagraph()` takes the same two enums (and still takes its original
`'L'`/`'C'`/`'R'`/`'J'` and `'T'`/`'M'`/`'B'` strings).

#### Lining wrapped text up with single-line text

Both methods place text through `TextPlacement`, so **a single line in a
box lands in exactly the same place whichever you use** — for every
alignment and every line height. A label beside a wrapped cell is just
the same box:

```php
$content->drawParagraph($font, 11.0, 200, 600, 300, 120, $body);
$content->drawTextInBox($font, 11.0, 72, 600, 100, 120, 'Notes');
```

If you are placing a baseline yourself, `valign: Top` puts the first one
at exactly `$y + $height - $font->ascentPt($sizePt)`.

One trap: that offset is the *font's* ascent, not a fraction of the
size. Helvetica rises 0.718 × size and Times 0.683, while an embedded
font reports what its `hhea` table says — often nearer 0.95. A row
mixing two kinds of font needs its baselines placed from one
`ascentPt()` call rather than one per cell.

### Measuring text

Every font measures its own text, so layout math is the same for both
kinds:

```php
$width = $font->widthOfPt($text, $sizePt);      // width in points
$x = ($pageWidth - $width) / 2;

$ascent    = $font->ascentPt($sizePt);          // rise above the baseline
$descent   = $font->descentPt($sizePt);         // drop below it, positive
$capHeight = $font->capHeightPt($sizePt);       // baseline to cap
```

`descentPt()` reports a **positive** distance, the opposite sign to
AFM's `Descender` and the PDF descriptor's `/Descent`. Every placement
formula wants `ascent + descent`, and writing that as `ascent - descent`
is a slip worth a fraction of a point in body copy and centimetres in a
headline.

Prefer `TextPlacement` (or `drawTextInBox()`) to doing this arithmetic
yourself — a hardcoded fraction of the type size, which is how FPDF and
TCPDF centre text, cannot be right for fourteen fonts at once: Helvetica
needs 0.359 of the size and Courier 0.281.

`StandardFont::metrics()` additionally exposes the standard-14 width
tables directly, keyed by WinAnsi code, for callers working in encoded
bytes.

## Drawing

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

### The general shapes

Those three take float triples and draw one thing each. The rest take a
`Paint` and a `Stroke`, and cover everything else:

```php
use MightyPDF\Content\{Color, Stroke};

$content->drawEllipse(cx: 200, cy: 600, radiusX: 60, radiusY: 30, fill: Color::fromHex('#2563eb'));
$content->drawCircle(320, 600, 30, stroke: new Stroke(Color::black(), 2.0));
$content->drawRoundedRectangle(72, 480, 200, 80, radius: 12,
    fill: Color::gray(0.94), stroke: Stroke::hairline());
$content->drawRegularPolygon(cx: 400, cy: 480, radius: 40, sides: 6, fill: Color::black());
$content->drawPolyline([[72.0, 400.0], [140.0, 440.0], [210.0, 380.0]], Stroke::dashed());
```

Both `fill` and `stroke` are optional, and a shape asked for neither
draws nothing rather than raising — which is what lets the layout layer
hand a style straight through without first asking whether there is
anything to paint.

`drawPath()` is the general form. The closure is handed a `PathSink` —
`moveTo`, `lineTo`, `curveTo`, `closePath`, which is everything PDF can
draw:

```php
use MightyPDF\Content\PathSink;

$content->drawPath(
    fn (PathSink $path) => $path->moveTo(72, 72)->lineTo(172, 172)
        ->curveTo(200, 200, 240, 160, 272, 172)->closePath(),
    fill: Color::fromHex('#334155'),
    stroke: Stroke::hairline(),
);
```

Pass `evenOdd: true` to switch the fill rule — the default nonzero rule
fills a subpath drawn inside another one in the same direction, while
even-odd leaves it as a hole. That is the whole difference between a
washer and a disc.

### Strokes

A `Stroke` gathers the five bits of graphics state an outline needs:

```php
use MightyPDF\Content\{Dash, LineCap, LineJoin, Stroke};

new Stroke(Color::black(), widthPt: 2.0, dash: new Dash([6.0, 2.0]),
    cap: LineCap::Round, join: LineJoin::Bevel);

Stroke::hairline();                    // 0.25pt, the weight a style guide means
Stroke::dashed(1.0, lengthPt: 3.0);
Stroke::dotted(1.0, spacingPt: 2.0);   // sets the round cap too -- see below
```

`Dash` has named constructors for what documents actually ask for:
`solid()`, `dashed()`, `dotted()`, `dashDot()`. One trap it removes: a
dotted line is zero-length "on" segments, which have no area under the
default butt cap and draw *nothing at all* — `Stroke::dotted()` sets
`LineCap::Round` with them, which is why it exists.

### Transforms, clipping and transparency

Each of these draws whatever the closure draws under some change to the
graphics state, and puts the state back afterwards:

```php
$content->rotated(90.0, originX: 300, originY: 400, draw: function (PageBuilder $content) {
    $content->drawText(StandardFont::Helvetica, 10.0, 300, 400, 'Reads bottom-to-top');
});

$content->translated(50, 0, fn (PageBuilder $c) => $c->drawSvg($logo, 0, 0, 60, 60));
$content->scaled(2.0, 2.0, 100, 100, fn (PageBuilder $c) => /* ... */);
$content->transformed([$a, $b, $c, $d, $e, $f], fn (PageBuilder $content) => /* ... */);

$content->clippedToRectangle(72, 400, 200, 100, function (PageBuilder $content) {
    $content->drawCircle(172, 450, 200, fill: Color::black());   // cut to the box
});

$content->faded(0.15, function (PageBuilder $content) {
    $content->drawText(StandardFont::HelveticaBold, 90.0, 100, 400, 'DRAFT');
});
```

**The closure is the point.** There is no way to leave a transform, a
clip or an alpha in effect by forgetting to close it, which is exactly
what a paired `startTransform()`/`stopTransform()` invites — and the
restore is in a `finally`, so a closure that throws does not leave the
page's content stream with an unbalanced `q`. That would corrupt
everything drawn afterwards, on a page a caller may well go on using
after catching. They nest, and the closure is handed the same
`PageBuilder`, so anything drawable is drawable inside one.

Positive rotation is **counter-clockwise** here, following PDF's Y-up
axes: 90 degrees reads bottom-to-top. (The layout layer measures down
from the top-left, so there it is positive-clockwise — see below.)

`drawTextRotated()` is the common case, turning one line about its own
baseline origin so the caller does not have to work out where the
rotation moved it to:

```php
$content->drawTextRotated(StandardFont::Helvetica, 9.0, x: 40, y: 300,
    degrees: 90.0, text: 'Revenue');
```

For anything still not covered, drop down to `ContentStream` directly and
hand it to the page with `drawCustom()`:

```php
use MightyPDF\Content\ContentStream;

$ops = (new ContentStream())->setLineWidth(2.0)->setStrokeColorRgb(0, 0, 0)
    ->moveTo(100, 100)->lineTo(200, 200)->stroke();
$content->drawCustom($ops);
```

See [`examples/17-shapes-transforms-and-transparency.php`](examples/17-shapes-transforms-and-transparency.php).

## Colour: RGB, CMYK and named inks

A colour is not always three numbers, and the three kinds are the
`Paint` interface:

```php
use MightyPDF\Content\{CmykColor, Color, SpotColor};

Color::fromHex('#334155');                          // RGB -- what a screen wants
CmykColor::fromPercentages(60, 40, 40, 100);        // ink coverage -- what a press wants
SpotColor::named('PANTONE 300 C', $alternate);      // a plate of its own
```

**Why CMYK is not just RGB with more steps.** Rich black
(`C60 M40 Y40 K100`) prints as a deep black; plain black (`K100`) prints
noticeably washed out beside a photograph. Both are `#000000` in RGB, so
a library holding only RGB cannot tell a press which one was meant.
`CmykColor` writes its four numbers into the file untouched.

**A spot colour is a named ink**, and becomes a PDF `/Separation`: a
press mounts that ink and prints a plate for it, so the colour comes out
right by construction rather than by approximation. It is also how a
varnish, a die-cut line or a white underprint is marked up — none of
which are colours at all.

```php
$brand = SpotColor::named('PANTONE 300 C', CmykColor::fromPercentages(100, 44, 0, 0));

$content->drawRectangle($x, $y, $w, $h, fill: $brand);
$content->drawRectangle($x, $y2, $w, $h, fill: $brand->withTint(0.15));
```

Every separation carries an **alternate** — the CMYK a device without
that ink should use instead — written as a linear tint transform from
bare paper to the full colour. So the document still looks right on a
screen and in an office printer, and prints from the correct plate on a
press. The tint is an operand rather than part of the colour space, so
**every tint of one ink shares one `/Separation` resource**: one plate,
as a press would see it.

Two reserved names pass through unchanged: `All` marks every plate at
once (registration marks), `None` marks none of them.

`toRgb()` converts any of the three, and is only ever a preview: a real
conversion needs the destination profile, and the answer moves visibly
between one press and another. Nothing here attempts colour management —
`DeviceCMYK` is uncalibrated by design and this library writes it
through as given, which is what a caller specifying ink coverage wants.

**Where paints are taken.** The general shapes above, `Layout\Style` and
`Layout\Border`, and the text calls through their `paint:` argument. The
original float-triple primitives are unchanged: `fillRectangle($x, $y,
$w, $h, ...$color->rgb())` still means what it always did.

```php
$content->drawText($font, 12.0, $x, $y, 'Brand', paint: $brand);
$flow->cell(50.0, 8.0, 'Heading', new Style(color: $brand));
```

See [`examples/20-print-colours.php`](examples/20-print-colours.php).

## Barcodes

```php
use MightyPDF\Content\Barcode\Symbology;

$content->drawBarcode('MightyPDF v2.0.0', x: 72, y: 600, width: 200, height: 40,
    symbology: Symbology::Code128, quietZone: true);
```

Four linear symbologies, and only the bars are drawn — the
human-readable line underneath is the caller's, via `drawText()`.

| | Carries | Notes |
|---|---|---|
| `Symbology::Code39` | 43 characters | Simple and verbose: 12–16 modules per character, no lowercase |
| `Symbology::Code128` | all of ASCII | Two-thirds the width, and digits packed two to a symbol |
| `Symbology::Ean13` | 13 digits | Retail packaging; check digit computed or verified |
| `Symbology::UpcA` | 12 digits | The same symbol with a leading zero |

**Code 128 chooses its own code sets** — start in C for four leading
digits, switch in for a run of six, shift for a single character out of
set and switch for two — and always carries the check symbol the
standard requires, which is not the caller's to supply.

**EAN-13 computes its check digit** from twelve digits, or verifies a
thirteenth against it and refuses a mismatch rather than correcting it: a
wrong check digit is a barcode that scans as a different product.
`Ean13::normalize()` gives back the full thirteen digits for the printed
line, so it says what the symbol actually encodes.

### QR codes

```php
use MightyPDF\Content\Barcode\QrEccLevel;

$content->drawQrCode('https://example.com/invoice/2026-0417',
    x: 400, y: 600, size: 100, level: QrEccLevel::Medium);
```

Versions 1 to 40, all four error-correction levels, and numeric,
alphanumeric or byte mode chosen as whichever is compact enough to hold
the whole string. Byte mode is UTF-8.

The module count follows the data and the level, so a long string and a
short one come out at different densities in the same box. `minVersion`
pins it, which is what a run of labels or a sheet of tickets wants.

`QrEccLevel` trades capacity for damage tolerance: `Low` ≈ 7%
recoverable, `Medium` ≈ 15% (the default and the usual choice),
`Quartile` ≈ 25%, `High` ≈ 30%. Reach for the higher two when the code
will be printed small, on something that creases, or with a logo over
the middle.

### Data Matrix

The 2D symbology of small things — a component, a vial, a postal item, a
form field that has to survive a fax. It packs more into a small area than
QR and, unlike QR, needs only a one-module quiet zone.

```php
$content->drawDataMatrix('LOT-4471/A', x: 72, y: 560, size: 60);
```

Square by default. The six rectangular sizes exist for marking things that
are themselves long and thin, and are a choice rather than an optimisation
— a rectangle almost never comes out smaller:

```php
use MightyPDF\Content\Barcode\DataMatrixShape;

$content->drawDataMatrix('LOT-4471/A', 72, 560, 60, DataMatrixShape::Rectangular);
```

Encoding is ASCII mode throughout, which puts **two digits in one
codeword** — the case that dominates, since the things Data Matrix is
printed on are mostly numbered rather than described. Long runs of letters
come out up to about a third larger than C40 would manage. The symbol
sizes were checked module-for-module against libdmtx, and every one of
them round-trips through it.

### Quiet zones

A barcode printed hard against other content does not scan, and that is
invisible on the page. `quietZone: true` reserves the clear space
*inside* the box you gave, so the bars shrink and the layout is
undisturbed.

It is **on by default for QR codes and everywhere in the layout layer**,
where a symbol sits against other content. It is **off by default for
`PageBuilder::drawBarcode()`**, which is what that method has always
done — a caller leaving it off is undertaking to leave the space itself.
`Symbology::quietZoneModules()` says how much (it is asymmetric for
EAN-13).

See [`examples/19-barcodes-and-qr-codes.php`](examples/19-barcodes-and-qr-codes.php).

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
  PNGs of any color type are de-interlaced first. Sub-byte bit depths
  (1/2/4, grayscale or indexed) relay verbatim when not interlaced, and
  are widened to one byte per pixel when they are.
- **GIF**: decoded to indexed color; transparency is supported.
- **TIFF**: the format scanners and fax gateways produce.

```php
$content->drawTiff($path, x: 72, y: 400, width: 200, height: 120);
$content->drawTiff($path, 72, 400, 200, 120, page: 2);   // a multi-page fax
```

**CCITT Group 3 and Group 4 strips are relayed, not decoded.** That is the
point rather than an optimisation: a G4 strip is already what PDF's
`/CCITTFaxDecode` expects, so a scan embeds as the same bytes it arrived
as — no decode, no re-encode, no generation loss, and a 30 MB batch of
scans that embeds in about 30 MB rather than swelling to hundreds.

Everything else is decoded and re-emitted as Flate: uncompressed, LZW,
PackBits and Deflate, in bilevel, grayscale, RGB or palette, with the
horizontal predictor undone. `PageBuilder::tiffPageCount($path)` says how
many images a file holds.

Refused rather than mis-rendered: JPEG-in-TIFF, tiled images, separated
planes (`/PlanarConfiguration 2`), CMYK and YCbCr. A fax split across
several strips is refused too — each strip is coded independently, so
concatenating them decodes correctly until the second strip and then to
noise.

## SVG vector graphics

```php
$content->drawSvg($path, x: 72, y: 560, width: 120, height: 120);
```

The SVG is placed and scaled to fit the given rectangle. Because it's
vector data (not a raster image), it stays crisp at any size — the same
file can be drawn small and large with no quality loss. Anything reaching
outside that rectangle is clipped, which is what SVG itself does: a root
viewport is `overflow: hidden` by default.

The drawing becomes a form XObject, so placing the same file the same way
more than once — a logo on every page — costs one drawing rather than
one per page. The file is read, parsed and rendered on the first
placement and reused on the rest, gradients and patterns included. Reuse
needs the placement to match as well as the file, since a gradient is
painted through a pattern whose matrix has the placement folded into it;
the same drawing placed elsewhere is genuinely a different one.

Supported: paths (lines, curves, arcs), basic shapes (`rect`, `circle`,
`ellipse`, `line`, `polyline`, `polygon`), fill/stroke in flat colours or
gradients and patterns, opacity, simple transforms
(`translate`/`scale`/`rotate`/`skew`/`matrix`), text — including text on
a path — embedded raster images, and styling from `<style>` blocks as
well as attributes.

**Gradients** — `<linearGradient>` and `<radialGradient>`, in either
`objectBoundingBox` (the default, measured across the shape) or
`userSpaceOnUse` units, with `gradientTransform`, and with
`href`/`xlink:href` inheritance so one gradient can borrow another's
stops. They become PDF shading patterns, which stay vector: a gradient
scales with the drawing rather than being rasterized into it.

`stop-opacity` is honoured, including the common "colour fading to
nothing" written as one colour with two opacities. A PDF colour carries
no transparency, so such a gradient is drawn twice — once in colour as
the shading, once in greyscale as a luminosity soft mask on the graphics
state, where white means opaque and black means invisible. A shape whose
fill *and* stroke both fade is painted under the fill's mask: the
graphics state has room for one.

**`spreadMethod="reflect"` and `"repeat"` are drawn as `pad`**, the
default: the end colours are held flat beyond the ends of the gradient
rather than bouncing or tiling.

**Patterns** — `<pattern>` becomes a PDF tiling pattern: the tile's
contents are drawn once and repeated across the shape, still as vector
artwork. `patternUnits` and `patternContentUnits` (each
`objectBoundingBox` or `userSpaceOnUse`), `patternTransform`, a
`viewBox` with `preserveAspectRatio`, and `href`/`xlink:href`
inheritance are all honoured — a pattern can borrow another's tile, its
contents, or both.

Anything can go in a tile, gradients and images included, since it is
drawn by the same renderer as the rest of the document.

A pattern is painted per shape, but shapes painted the *same* way share
one tile and one PDF pattern object — what a tile looks like depends on
the pattern and on the matrix its contents are drawn under and on
nothing else. Shapes of different sizes still get their own where the
pattern is measured in `objectBoundingBox` units, since then the tile
genuinely differs.

A pattern painted with itself paints nothing on the inner reference
rather than tiling forever, and a *chain* of patterns — each tile
painted with the next one along, which is not circular and so gets past
that check — stops at four deep or a thousand distinct tiles, whichever
comes first. Both are far past what a drawing uses and far below the
point where a few kilobytes of SVG turns into a document of hundreds of
megabytes.

**Embedded raster images** — an `<image>` element is drawn, with
`preserveAspectRatio` (`meet`, `slice` including the clip, `none`, and
the alignment keywords) honoured. PNG, JPEG and GIF are recognised from
the bytes rather than from the data URI's declared media type, which
tools get wrong.

**Only `data:` URIs are read.** An `<image href="/etc/passwd">` or an
`href` pointing at a URL is skipped, not fetched: an SVG may have
arrived from anywhere, and following a path in one would let a document
this library did not write name a file it then embeds in a document that
may be sent on. Inline is also how self-contained SVGs carry images in
practice.

**Text** — `<text>` and `<tspan>`, with `font-family`, `font-size`,
`font-weight`, `font-style`, `text-anchor`, `letter-spacing`, per-span
fill colours and the `x`/`y`/`dx`/`dy` positioning a span can carry.
Whitespace is collapsed the way SVG specifies, so indented markup does
not turn into indented text.

**`<textPath>`** lays a run of text along a path the drawing already
contains, with `startOffset` (a length or a percentage) and
`text-anchor`. Each glyph is placed at its own point along the path and
turned to face its own direction, which is what a curve requires — and
means text on a path is a glyph per operator, so copying it out of the
reader gives the characters without the word breaks. Glyphs that run off
the end of the path are not drawn, as SVG specifies.

A drawing names its font the way CSS does — a list of preferences ending
in a generic name — and there is no font catalogue here to look those up
in. By default they map onto the standard 14: anything serif-ish becomes
Times, anything monospace becomes Courier, everything else Helvetica,
with the bold and italic cuts chosen from `font-weight`/`font-style`.
Pass a resolver to use real fonts instead:

```php
$content->drawSvg($path, x: 72, y: 500, width: 200, height: 200,
    fontResolver: fn (string $family, bool $bold, bool $italic) => $bold ? $interBold : $inter);
```

**CSS in `<style>` blocks** — which is how drawing tools actually write
styling: a block of `.cls-1 { fill: #e74c3c }` rules and shapes carrying
`class="cls-1"` rather than fills of their own. Type, class, id and
universal selectors are matched, in any combination on a single element
(`rect.cls-1`) and in comma-separated groups, with the usual specificity
order and document order as the tiebreak. The cascade is honoured:
presentation attributes lose to style-block rules, which lose to the
inline `style` attribute.

All four combinators are matched too — descendant (`g .label`), child
(`g > rect`), adjacent sibling (`rect + text`) and general sibling
(`rect ~ text`) — so a sheet can style by where an element sits and not
only by what it is. Pseudo-classes and attribute selectors are still
ignored rather than approximated: they ask about state and about
attributes this renderer does not model, and a selector understood in
part matches the wrong elements confidently. An ignored selector
contributes nothing and the rest of the sheet still applies. At-rules
(`@media`, `@supports`, `@import`) are skipped whole:
the rules inside one look exactly like ordinary rules, and a drawing's
print styling is usually the opposite of its screen styling.

**Not supported** (elements are skipped, not mis-rendered): filters and
animation. A `fill="url(#…)"` naming anything that cannot be resolved
paints nothing, rather than failing the document. See
[`src/Content/Svg/SvgDocument.php`](src/Content/Svg/SvgDocument.php) for
the exact scope.

## Form fields (AcroForm)

Six field types are supported: single-line text fields, checkboxes, radio
groups, list boxes, dropdowns, and signature-field placeholders. Adding
any of them lazily creates the document's single shared `/AcroForm` the
first time it's needed — every field on every page ends up listed
together in that one form.

```php
$content->addTextField('first_name', x: 200, y: 665, width: 250, height: 20, value: 'Jane');
$content->addTextField('comments', x: 200, y: 630, width: 250, height: 20, maxLength: 200);

$content->addCheckbox('subscribe', x: 280, y: 592, size: 14, checked: true);

$content->addRadioGroup('plan', [
    ['exportValue' => 'Basic', 'x' => 200, 'y' => 560, 'size' => 12],
    ['exportValue' => 'Pro', 'x' => 260, 'y' => 560, 'size' => 12],
], checkedExportValue: 'Pro');

$content->addListBox('country', ['USA', 'Canada', 'Mexico'], x: 200, y: 470, width: 150, height: 60, value: 'Canada');

$content->addDropdown('shipping', ['Standard', 'Express'], x: 200, y: 440, width: 150, height: 20, value: 'Standard');

$content->addSignatureField('signature', x: 200, y: 390, width: 200, height: 40);
```

`addTextField(string $name, float $x, float $y, float $width, float $height, ?string $value = null, Font $font = StandardFont::Helvetica, float $fontSizePt = 10.0, ?int $maxLength = null)`

`addCheckbox(string $name, float $x, float $y, float $size, bool $checked = false)`

`addRadioGroup(string $name, array $options, ?string $checkedExportValue = null, MarkStyle $mark = MarkStyle::Dot)` — `$options` is a list of `['exportValue' => ..., 'x' => ..., 'y' => ..., 'size' => ...]`, one per button. At most one option's `exportValue` should match `$checkedExportValue`; the rest start unchecked, and the group enforces mutual exclusion natively (no JavaScript).

`addListBox(string $name, array $options, float $x, float $y, float $width, float $height, ?string $value = null, Font $font = StandardFont::Helvetica, float $fontSizePt = 10.0)` and `addDropdown(...)` (same signature) — `$options` is a plain list of strings; a dropdown is a list box with the spec's "Combo" flag set, which is the only difference between the two.

`addSignatureField(string $name, float $x, float $y, float $width, float $height)` — reserves a `/Rect` and an `/AcroForm` entry for a signature to be added later. The field is created unsigned, with no `/V` (which is what the spec says an unsigned field looks like). To actually put a signature in it, see [Signing](#signing-a-document): this library prepares and completes the signature, and an external signer holding the key produces the CMS.

### The font a field is filled in with

A field's `$font` may be one of the standard 14 or a TrueType file of
your own, which is how a form takes input a standard font cannot draw —
Greek, Cyrillic, CJK:

```php
$formFont = EmbeddedFont::load('/path/to/NotoSans-Regular.ttf', subset: false);

$content->addTextField('name', x: 72, y: 600, width: 300, height: 24, font: $formFont);
```

**`subset: false` is required here, and refused otherwise.** A field's
`/DA` names the font a *reader* lays out what someone types with, and a
subset holds only the glyphs this document already drew — pointing a
field at one gives the reader a font missing exactly the characters it
needs, a failure that surfaces when the form is filled in rather than
when it is written. The whole font is therefore embedded, described
character by character rather than glyph by glyph, and costs whatever
the file on disk weighs.

Text fields, list boxes and dropdowns rely on `/NeedAppearances` so the
PDF reader regenerates the visible text itself from the field's value and
appearance string — this is standard, reader-supported behavior.
Checkboxes and radio buttons instead carry their own small on/off
appearance streams (drawn with `ContentStream`, `MarkStyle::Check` and
`MarkStyle::Dot` by default respectively), since readers are less
consistent about regenerating button appearances automatically.

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

## The layout layer

Everything above is a **pure writer**: you say where things go, in
points, from the bottom-left. That is the right foundation and the wrong
altitude for a business document, where you want a cursor, a cell, a
page that breaks itself, and millimetres from the top-left.

`MightyPDF\Layout\Flow` is that layer, built entirely on `PageBuilder` —
it adds no capability to the content layer and takes none away. Mix the
two freely: `content()` hands back the `PageBuilder` for the current
page, and `toPointsX()`/`toPointsY()` convert a coordinate so custom
drawing lands in the same space as everything else.

```php
use MightyPDF\Assembler\{Document, PageSize};
use MightyPDF\Content\Color;
use MightyPDF\Content\Font\StandardFont;
use MightyPDF\Content\Text\{HorizontalAlign, VerticalAlign};
use MightyPDF\Layout\{Border, Flow, Margins, Style, Unit};

$flow = new Flow(new Document(), PageSize::A4, Margins::uniform(15.0));

$heading = new Style(StandardFont::HelveticaBold, 9.0, Color::white(),
    fill: Color::fromHex('#334155'), border: Border::box(0.3));
$row = new Style(StandardFont::Helvetica, 9.0, border: Border::bottom(0.1));

foreach (['Control', 'Owner', 'Status'] as $text) {
    $flow->cell(60.0, 7.0, $text, $heading);
}
$flow->newLine(7.0);

foreach ($controls as $i => $control) {
    $style = $i % 2 === 0 ? $row->with(fill: Color::gray(0.96)) : $row;

    $flow->cell(60.0, 6.0, $control->name, $style);
    $flow->cell(60.0, 6.0, $control->owner, $style);
    $flow->cell(60.0, 6.0, $control->status, $style->with(align: HorizontalAlign::Right));
    $flow->newLine(6.0);
}

$flow->saveToFile('scorecard.pdf');
```

Rows past the bottom margin start a new page by themselves. See
[`examples/16-a-report-with-the-layout-layer.php`](examples/16-a-report-with-the-layout-layer.php)
for the whole thing: a grade placard, a table that breaks across pages, a
line chart drawn through the primitives, and a footer on every page.

**Coordinates.** X runs right and Y runs **down**, from the page's
top-left corner, in whatever `Unit` you chose (`Millimetres` by default;
also `Points` and `Inches`). That is the opposite of PDF's own
convention, deliberately: every page description a person writes — a
margin, a header depth, a row height — is measured from the top of the
sheet. The flip happens in one method.

**The cursor.** `x()`, `y()`, `moveTo()`. `cell()` advances x by its
width; `newLine($height)` returns to the left margin and drops y. A
table row is a run of cells and a `newLine()`.

**Cells and paragraphs.** `cell()` is one line; `paragraph()` word-wraps
and auto-sizes its box unless you pass a height. Both place text through
the same `TextPlacement`, so a wrapped cell and an unwrapped one of the
same geometry sit on the same baselines. `paragraphHeight()` measures
without drawing. `write()` is the third shape — a run rather than a
block, for a phrase inside a sentence (see "Runs" below).

**Page breaks.** Automatic, and `willFit()`/`breakIfNeeded()` let custom
drawing take part in the same decision. An element taller than the page
body overflows one page rather than breaking forever. `onPageBreak()`
takes the decision over, which is how a page gets columns.

**Margins.** `margins()` reads them and `setMargins()` moves them. They
are cursor state rather than configuration: where a line starts and
ends, what `contentWidth()` is, and how far down the page runs before it
breaks.

### Runs: text inside a sentence

`paragraph()` is a block. It takes a width, starts a line of its own and
ends one — so a phrase in a second colour, or behind a link, in the
middle of a sentence has nowhere to go. `write()` is the other half of
the pair: it starts where the cursor is, wraps between the margins, and
leaves the cursor at the end of the last line rather than on the next
one.

```php
$flow->write('These goods are supplied under the ', $body)
     ->write('standard conditions of sale', $body->with(color: $blue),
         link: 'https://example.com/conditions')
     ->write(', which are incorporated by reference. ', $body)
     ->write('Nothing here varies them.', $body->with(font: StandardFont::HelveticaBoldOblique))
     ->newLine(6.0);
```

Between them there is no need for an inline layout engine. A run wraps,
breaks the page between its lines, and gets one link rectangle per line,
so a link broken across a line break is clickable on both halves.

A run is **not a box**, so the style's `fill`, `border`, `paddingPt` and
horizontal alignment do not apply — there is nothing to align a fragment
of a line within, and a background belongs to the block that holds the
run. Its font, size, colour and vertical alignment do apply, and the
last of those is what makes a run and a `cell()` of the same height sit
on one baseline.

The spaces at the ends of a run are kept. Wrapping normally discards
them, which is right inside a paragraph and would silently glue
`write('Visit ')` to `write('the site')`.

### Links, in millimetres

The content layer's `addLink()` has always taken points from the
bottom-left. `link()` and `linkTo()` are the same rectangles in the
layout layer's own coordinates, and `cell()` takes them as arguments —
because the target of a link in a table is usually the row, not the
eleven characters of blue text in it.

```php
$flow->link(20.0, 30.0, 60.0, 10.0, 'https://example.com/');
$flow->linkTo(20.0, 45.0, 60.0, 10.0, Destination::fitPage($appendix));

$flow->cell(60.0, 8.0, 'Terms of service', $body, link: 'https://example.com/tos');
$flow->cell(60.0, 8.0, 'Appendix A', $body, destination: Destination::of($appendix));
```

Neither draws anything. The blue underline that makes a link *look* like
one is yours, which is what lets a link cover an image, a table cell or
a whole panel just as easily.

### Columns

`onPageBreak()` is handed the `Flow` and the height that would not fit,
and returns `true` to let the page break or `false` to say it has dealt
with it. A column is a left edge and a right edge — which is to say a
pair of margins — so dealing with it is moving those:

```php
$column = 0;

$flow->onPageBreak(function (Flow $flow) use (&$column, $left, $right): bool {
    $column = 1 - $column;

    if ($column === 0) {
        $flow->setMargins($flow->margins()->with(left: $left, right: $right));

        return true;                    // second column full: turn the page
    }

    $flow->setMargins($flow->margins()->with(left: $right, right: 15.0));
    $flow->moveTo($right, $flow->margins()->top);

    return false;                       // first column full: move across
});
```

Move the **margins**, not only the cursor. `newLine()` returns to the
left margin and wrapping stops at the right, so a hook that moves the
cursor alone gets one correct line and then drifts back to the page
edge.

The hook governs automatic breaks only: `newPage()` is an instruction
rather than a question, including the hook's own call to it. Automatic
breaks are suppressed while the hook runs, so a hook that positions
itself by drawing cannot ask itself whether to break, forever.

### A page of another size

`newPage()` takes one, and every measurement afterwards follows the page
being drawn on — `pageWidth()`, `contentWidth()`, `bottomLimit()`, the
conversion to points, and the margins, which hold against that page's
edges. A portrait report with one landscape table in it is a document,
not two.

```php
$flow->newPage(PageSize::A4->landscape());
$flow->table([70.0, 45.0, 45.0, 45.0, 62.0]);   // 267mm of body, not 180mm
$flow->newPage();                               // back to the Flow's own size
```

Left out, the page is the size the `Flow` was built with rather than the
size of the page just finished. An **automatic** break goes the other
way and continues at the current size: a run of rows that started on a
wide sheet was measured against it, and continuing on a narrower one
would overflow columns that were correct when they were sized.

`onEachPage()` hooks see the geometry of the page they are drawing on,
so one footer expression puts a centred page number on both.

See [`examples/22-runs-columns-and-a-landscape-page.php`](examples/22-runs-columns-and-a-landscape-page.php)
for all three in one document.

### Tables

A table drawn as a run of `cell()` calls restates its column widths on
every row, takes three edits to add a column, and loses its header the
moment an automatic break lands in the middle of it — leaving a reader
on page four with no way to know what the columns are. `table()` owns
all three:

```php
use MightyPDF\Content\Text\HorizontalAlign;

$flow->table([70.0, 60.0, 30.0], $body, $heading)
    ->align(2, HorizontalAlign::Right)
    ->striped(Color::gray(0.96))
    ->header(['Control', 'Owner', 'Status'])
    ->rows($controls, fn (Control $c) => [$c->name, $c->owner, $c->status])
    ->end();
```

**The header comes back at the top of every page the table runs onto.**
Detected from the page number after asking `Flow` to break, rather than
by predicting what `Flow` would do — so a `Flow` built with
`autoPageBreak: false` does not get a break here either.

**Cells wrap and rows size themselves** to the tallest cell in the row,
plus the table's vertical padding, floored at `minRowHeight`. That is why
the measuring lives here: nothing that sees a single cell can know a
row's height.

```php
$table->row(['Component', 'A licence with enough wording to wrap over three lines', '4.2.1']);
$table->heightOf([...]);   // what that row would take, without drawing it
```

**Cells are strings, or a `Cell` where a string will not do** — a style
of its own, or a colspan:

```php
use MightyPDF\Layout\Cell;

$table->row([new Cell('Total seats', $bold, colspan: 2), new Cell('4,485', $boldRight)]);
```

A row with the wrong number of cells is **refused** rather than drawn
crooked: every column after the mistake would sit under the wrong
heading, which reads as data rather than as an error.

**Styling layers**, most specific last: the row's style, then
`columnStyle()`/`align()`, then the cell's own, then the stripe — which
is decoration, so a cell that names its own fill keeps it. Striping
counts body rows only and carries across a page break, so a row keeps its
shade wherever it lands.

`Flow::table()` also takes `minRowHeight` and `verticalPaddingPt`. The
padding lives on the table rather than on `Style` because it is a
property of how a row is *sized*, which `Style` deliberately has no say
in.

See [`examples/18-a-table-that-breaks-across-pages.php`](examples/18-a-table-that-breaks-across-pages.php).

### Shapes, transforms and barcodes in the same coordinates

Everything from the content layer, in this Flow's unit and measured from
the top-left:

```php
$flow->circle(30.0, 20.0, 5.0, fill: $brand);
$flow->ellipse(60.0, 20.0, 12.0, 6.0, stroke: Stroke::hairline());
$flow->roundedRect(10.0, 40.0, 60.0, 20.0, radius: 3.0, fill: Color::gray(0.95));
$flow->polygon([[10.0, 80.0], [40.0, 80.0], [25.0, 100.0]], fill: $ink);
$flow->polyline($series, new Stroke($blue, 1.2));
$flow->line(10.0, 120.0, 190.0, 120.0, 0.5, $ink, Dash::dashed());

$flow->barcode('MightyPDF v2.0.0', 15.0, 130.0, 90.0, 16.0);
$flow->qrCode('https://example.com', 120.0, 130.0, 38.0);
```

`path()` takes the same closure `drawPath()` does, through a `PathSink`
that converts as it goes — so a curve is not the one thing in this layer
measured the other way up:

```php
$flow->path(
    fn (PathSink $path) => $path->moveTo(20, 100)->curveTo(60, 60, 100, 140, 140, 100),
    stroke: new Stroke($blue, 1.2),
);
```

The scoped graphics states come across too, and take a `Flow`:

```php
$flow->faded(0.12, fn (Flow $flow) => $flow->rotatedTextAt(60.0, 200.0, -45.0, 'DRAFT', $huge));
$flow->clippedToBox(10.0, 10.0, 50.0, 20.0, fn (Flow $flow) => /* ... */);
$flow->rotated(90.0, 20.0, 100.0, fn (Flow $flow) => /* ... */);
```

**Rotation is positive-clockwise here**, the opposite of the content
layer and for the same reason the Y axis is: this layer measures down
from the top-left the way a screen does, and in that space a positive
angle turns clockwise, as it does in CSS and SVG. So `-90` reads
bottom-to-top up the left edge of the sheet.

`Flow::barcode()` defaults to **Code 128** rather than the Code 39
`PageBuilder::drawBarcode()` keeps for compatibility, and reserves its
quiet zone by default. New code should be printing Code 128.

### Something on every page

```php
$flow->onEachPage(function (Flow $flow, int $page, int $total): void {
    $flow->cellAt(15.0, 283.0, 180.0, 5.0, "Not legal advice. Page $page of $total.",
        new Style(StandardFont::Helvetica, 7.0, Color::gray(0.4),
            align: HorizontalAlign::Center));
});
```

This is the guarantee a per-page footer needs: it runs for **every**
page, including ones an automatic break created in the middle of a
table. Without it, a legal disclaimer is only as reliable as every
drawing function remembering to place one.

Hooks run at `finish()` rather than as each page closes, which is what
makes `of $total` simply true. FPDF substitutes a placeholder string
afterwards and TCPDF rewrites the page; both work around being a
streaming writer. MightyPDF appends to any page's content stream right
up until `save()`, so waiting costs nothing. The closure is handed the
same `Flow`, pointed at the page in question, so a footer is written in
millimetres like everything else.

`finish()` is idempotent, and `save()`/`saveToFile()` call it for you.
So does saving through `document()`: the hooks are registered with the
`Document` itself, so `$flow->document()->save()` decorates the pages
too rather than quietly producing a file with no footer on it.

Because the page count is final by the time hooks run, automatic page
breaks are suppressed for their duration. A footer sits below the bottom
margin, so `cell()` inside a hook would otherwise ask to break on every
page — and a hook that *adds* a page is refused, since the page it added
would be undecorated and every `of $total` already drawn would be wrong.
Both `cellAt()` and `cell()` are fine in a hook. Explicit `newPage()` is
still the mistake it was, and still throws.

### Measuring and placing by hand

```php
$flow->widthOf($text, $style);   // in the flow's unit, so you can size a column
$flow->remainingWidth();         // cursor to right margin — a cell that fills the line
$flow->textAt($x, $y, $text);    // a baseline outright, for a chart label or a rule
```

### Text a font cannot draw

An embedded font **throws** on the first character it has no glyph for.
That is right for a library — a blank box is invisible in review and
obvious in print — and wrong for a document assembled on demand from
names other people typed, where the character nobody anticipated turns
an imperfect report into a 500.

```php
use MightyPDF\Layout\MissingGlyphs;

$flow = new Flow($document, PageSize::A4, missingGlyphs: MissingGlyphs::Substitute);
```

Every character the font can't set becomes a transliteration it can, or
`?`. Text is never blanked — a name that couldn't be set is visibly
approximate rather than silently missing, which is the worse of the two.
Widths are measured on what will actually be drawn, so centring and
right-alignment aren't computed from characters that were replaced.

`GlyphFallback::apply($text, $font)` is the same thing for callers
working directly with `PageBuilder`. Both are portable: transliteration
is asked for one character at a time and non-ASCII results are refused,
so the answer doesn't change with which iconv PHP was built against.

### Fonts from configuration

```php
StandardFont::matching('Arial', bold: true);            // Helvetica-Bold
StandardFont::matching('Baskerville, Georgia, serif');  // Times-Roman
```

For code holding a font as data — a config value, a CSS-style family
list, a port of an API whose call was `setFont('Arial', 'B')`.

### Colours

Channels are `0.0`–`1.0` floats in PDF, and 0–255 or hex everywhere
else:

```php
use MightyPDF\Content\Color;

Color::fromHex('#334155');          // also '#abc', with or without the '#'
Color::fromRgb255(51, 65, 85);
Color::gray(0.96);
Color::black();  Color::white();
```

Out-of-range channels throw rather than clamp. The layout layer takes any
`Paint` — RGB, CMYK or a named ink (see [Colour](#colour-rgb-cmyk-and-named-inks));
the original drawing primitives keep their float triples, and `rgb()`
spreads into them:

```php
$content->fillRectangle($x, $y, $w, $h, ...$color->rgb());
```

### Page sizes

```php
use MightyPDF\Assembler\PageSize;

$document->newPage(PageSize::A4);          // or ::A3, ::A5, ::Letter, ::Legal, ::Tabloid
$document->newPage(PageSize::A4->landscape());
```

`Flow::newPage()` takes the same thing, and the whole layout layer
measures against the page it is drawing on — see "A page of another
size" above.

### Turning a page

```php
$document->newPage(PageSize::A4, rotation: 90);
$page->setRotation(270);                       // or afterwards
```

Multiples of 90 only, normalised into 0–270, and omitted from the file
when zero.

**This is not how to make a landscape page.** `/Rotate` turns the page as
displayed and printed while leaving the coordinate system underneath it
exactly as it was, so everything already drawn stays where it was drawn
and comes out sideways. That makes it the right tool for a scanned page
that arrived the wrong way up, and the wrong one for a landscape report —
which wants a landscape media box: `PageSize::A4->landscape()`.

### Serving a PDF over HTTP

```php
use MightyPDF\Output\PdfResponse;

PdfResponse::inline($document->save(), 'scorecard.pdf')->send();
PdfResponse::attachment($flow->save(), 'Rapport financier — 2026.pdf')->send();
```

Sets `Content-Type`, `Content-Disposition`, `Content-Length`, a
`Cache-Control` that stops a browser showing an hour-old invoice, and
`X-Content-Type-Options: nosniff` so that nothing downstream gets to
decide this is something other than a PDF. A
filename is usually taken from a record, so it is treated as untrusted:
CR/LF/NUL are refused (that is header injection, not a formatting
problem), quotes are escaped, and a non-ASCII name also goes out in RFC
5987 form. `headers()` returns them without sending, for testing.

## Links and bookmarks

A link is a rectangle of the page that goes somewhere when it is
clicked. It draws nothing — the underlined blue text that makes a link
*look* like one is yours to draw, which is the right way round: a link
over an image, a button or a whole table cell is just as ordinary.

```php
$content->drawText(StandardFont::Helvetica, 12.0, 72, 700, 'php.net', r: 0.1, g: 0.3, b: 0.8);
$content->addLink(x: 72, y: 697, width: 40, height: 14, uri: 'https://www.php.net/');

$content->addInternalLink(x: 72, y: 660, width: 200, height: 14,
    destination: Destination::of($chapterPage, top: 792));
```

Both work just as well on a page of a document you opened — `PageOverlay`
hands you the same `PageBuilder` (see "Drawing on an existing page"), and
`Destination::atPage($objectId, top: 700)` names a page of that document
rather than one you just made.

`Destination` is where a link or a bookmark points, and the same value
serves both:

- `Destination::of($page, ?float $top = null, ?float $left = null)` — a
  point on the page, scrolled to the top of the window. Left null, the
  reader keeps the position it had, which makes a link feel like a page
  turn rather than a jump. `$top` is a y coordinate in the page's own
  space, so it counts from the bottom: the top of a Letter page is 792.
- `Destination::fitPage($page)` — the whole page, fitted to the window.
- `Destination::fitWidth($page, ?float $top = null)` — its full width.

**Bookmarks** are the tree a reader shows beside the page. Adding one
returns it, so sections go under chapters:

```php
$outline = $document->outline();

$outline->add('Contents', Destination::of($contentsPage));

$one = $outline->add('1. The first chapter', Destination::of($chapter1));
$one->add('1.1 Background', Destination::of($chapter1, top: 600));
$one->add('1.2 Method', Destination::of($chapter1, top: 400));

// Closed: its sections are there, but the reader starts with them folded away.
$outline->add('2. The second chapter', Destination::of($chapter2), open: false)
    ->add('2.1 Results', Destination::of($chapter2));
```

`add(string $title, ?Destination $destination = null, bool $open = true)`
— the destination is optional, since an item with none is a heading that
groups the items under it and goes nowhere itself. Titles are text
strings, so they keep their own characters in any language.

Like `/AcroForm` and `/Info`, the outline is created the first time you
ask for it; a document that never calls `outline()` has none at all.
Asking also sets `/PageMode /UseOutlines`, so readers open with their
bookmark panel showing — an outline nobody can see is the same as no
outline for most of the people who open the file.

The tree's own wiring (`/First`, `/Last`, `/Next`, `/Prev`, `/Parent`
and the signed `/Count` a reader lays the panel out from) is written at
save time, since none of it is true until the whole tree exists.

See [`examples/15-links-and-bookmarks.php`](examples/15-links-and-bookmarks.php)
for a runnable version.

## Document metadata

```php
$document->info()->setTitle('Quarterly Report');
$document->info()->setAuthor('Jane Doe');
$document->info()->setSubject('Q2 2026 results');
$document->info()->setKeywords('quarterly, report, finance');
$document->info()->setCreator('My App');
$document->info()->setProducer('MightyPDF');
$document->info()->setCreationDate(new DateTimeImmutable());
```

`info()` allocates the `/Info` dictionary the first time it's called —
fully opt-in, the same way `/AcroForm` is: a document that never touches
`info()` gets no `/Info` entry at all. `Title`/`Author`/`Subject`/`Keywords`/
`Creator`/`Producer` accept plain text and are stored as ASCII when
possible, UTF-16BE otherwise — the same encoding rule already used for
form field names and values, so non-Latin text round-trips losslessly
rather than being transliterated or mangled. `setCreationDate()` takes any
`DateTimeInterface` and formats it per the PDF spec's own date syntax.

See [`examples/12-document-metadata.php`](examples/12-document-metadata.php)
for a runnable version.

### XMP

`/Info` is what a PDF reader shows in its properties box. **XMP** is what
asset managers, search indexes, print workflows and every conformance
level above plain PDF look at, and a file with only `/Info` is invisible
to the second group.

```php
$document->metadata();                              // that is all it takes
$document->metadata()->setRights('© 2026 Acme Ltd');
```

The packet is **generated from `info()`**, not set beside it. Two
hand-maintained copies of the same six fields disagree eventually, and a
document whose `/Info` says one title and whose XMP says another is worse
than one that says it once — which of the two a given tool believes is not
something the document gets to decide. So the flow is one way: set
metadata through `info()`, and this restates it in RDF/XML at save. Things
with no `/Info` equivalent (`dc:rights`, the asset ids) are set here and
live only here. `setPacket()` takes a complete packet of your own — for a
Factur-X profile, say — and then nothing is generated and nothing checked.

In an encrypted document the packet is enciphered like everything else,
which is the spec's default and the safe one: a title can be as revealing
as the page it describes. `encrypt(..., encryptMetadata: false)` leaves it
readable, so an indexer with no password still gets the title of a
document whose pages it cannot read.

### Page labels

What the reader calls each page — the number in its toolbar, its
thumbnails and its "go to page" box. Without them a reader counts from 1
and has no other idea, so a report with roman front matter shows "page 5
of 40" while the paper in your hand says 1.

```php
use MightyPDF\Assembler\PageLabelStyle;

$document->pageLabels()
    ->from(0, PageLabelStyle::LowercaseRoman)                    // i, ii, iii, iv
    ->from(4, PageLabelStyle::Decimal)                           // 1, 2, 3, …
    ->from(30, PageLabelStyle::Decimal, prefix: 'A-')            // A-1, A-2, …
    ->from(40, PageLabelStyle::None, prefix: 'Cover');           // the prefix alone
```

Runs may be declared in any order and are sorted on the way out, because
the number tree needs ascending keys and a reader handed them unsorted
does not search it — it gets the wrong answer. A tree that never says what
page 0 is called is refused at save, that being the one mistake here every
reader handles differently.

`labelFor(int $pageIndex)` says what a reader will show, so a table of
contents printing "see page A-4" uses the same string the toolbar will,
rather than working it out a second time and coming to disagree. The
letter styles are doubled — A…Z, then AA, BB — as Table 159 specifies,
and not the spreadsheet columns everyone reaches for.

## Attachments

A document can carry files inside it:

```php
use MightyPDF\Assembler\Attachment\AttachmentRelationship;

$document->attach(
    'invoice-2026-0417.xml',
    $xml,
    description: 'The same invoice, machine-readable',
    mediaType: 'application/xml',
    relationship: AttachmentRelationship::Data,
);
```

`$name` is what a reader shows and the key the file is filed under, so
two attachments cannot share one. The bytes go in as a deflated
`/EmbeddedFile` stream carrying the uncompressed size and an MD5
checksum, which is what the spec specifies for it — a file-identity
check with no security claim attached.

**The relationship is what makes an attachment machine-readable.** An
e-invoice is a PDF a person reads with an XML file inside it that a
system reads, and that the two are the *same invoice* is a claim the file
has to make rather than one a consumer can infer from a filename.
`AttachmentRelationship::Data` says so, and is what Factur-X, ZUGFeRD and
the rest of the EU e-invoicing formats are built on. The others are
`Source`, `Alternative`, `Supplement` and `Unspecified` (the default,
which writes no entry at all).

Note that a conforming Factur-X file also needs PDF/A-3, which this
library does not produce — see "Known limitations".

### An attachment on the page

`attach()` puts a file in the reader's attachments panel, which is where
a machine-readable companion belongs and where a person will never look
for it. To put it next to whatever it relates to:

```php
use MightyPDF\Assembler\Annotation\AttachmentIcon;

$workings = $document->attach('migration-hours.csv', $csv, mediaType: 'text/csv');

$content->addFileAttachment($workings, x: 500, y: 640, size: 18,
    icon: AttachmentIcon::Paperclip, note: 'Hours behind this line');
```

This takes the specification `attach()` returned rather than bytes of its
own, so the icon and the panel entry point at **one** embedded stream.
The file is therefore in both places — which is the intent, though it
means a tool that enumerates attachments naively (poppler's `pdfdetach
-list`, say) reports it twice.

The icon is drawn by the reader from `AttachmentIcon` (`PushPin`,
`Paperclip`, `Graph`, `Tag`) and they differ noticeably between readers,
so the rectangle is a hint at the size rather than a frame it is fitted
to.

## How the document asks to be opened

```php
use MightyPDF\Assembler\{Duplex, PageLayout, PageMode, PrintScaling};

$document->viewerPreferences()
    ->displayDocumentTitle()
    ->printScaling(PrintScaling::None);

$document->setPageLayout(PageLayout::TwoPageRight);
$document->setPageMode(PageMode::Thumbnails);
```

Every one of these is a request a reader may ignore, and most readers do
ignore the window-chrome ones. Two are worth setting on almost any
document:

- **`displayDocumentTitle()`** makes the window show the document's
  `/Title` instead of its filename, so a file received as
  `invoice_final_v3(2).pdf` still says what it is. Set the title too, or
  this asks a reader to display nothing.
- **`printScaling(PrintScaling::None)`** stops a reader shrinking the
  page by a few percent to clear its printer's margins. That is the
  default behaviour and it is wrong for anything measured: a form that
  has to line up with a pre-printed one, a drawing at a stated scale, a
  sheet of labels, a barcode whose module width was chosen for a scanner.

The rest: `hideToolbar()`, `hideMenubar()`, `hideWindowUi()`,
`fitWindow()`, `centerWindow()`, `nonFullScreenPageMode()`,
`duplex()`, `pickTrayByPageSize()`, `numberOfCopies()`. Nothing is
written unless set, so a document that asks for nothing carries no
`/ViewerPreferences` at all.

`PageMode` also drives the panel a document opens with — and both
`outline()` and `attach()` ask for their own panel *only* where the
document has not already said what it wants, so a document does not open
differently depending on the order of two unrelated calls.

See [`examples/21-attachments-and-viewer-preferences.php`](examples/21-attachments-and-viewer-preferences.php).

## Print production: bleed and trim

A page has more boxes than the one it is drawn on. `/MediaBox` is the
sheet, and it is the only one a screen reader cares about — but a
commercial printer wants to be told two more things: where the
guillotine cuts, and how far past that line the ink runs.

```php
use MightyPDF\Assembler\PageSize;
use MightyPDF\Layout\Unit;

$bleed = Unit::Millimetres->toPoints(3.0);

$page = $document->newPage(PageSize::A4->withBleed($bleed));
$page->setBleed($bleed);
```

`withBleed()` makes the sheet — A4 plus 3mm on every side, at the origin
— and `setBleed()` says how much of it is bleed: the trim box becomes
the A4 back out of the middle, and the bleed box becomes the whole
sheet. A shop's preflight check looks for exactly that pair, and a file
with neither is one where nothing in the document says how big the
finished piece is.

The four boxes can also be set outright, in points, when the geometry
isn't a uniform bleed:

```php
$page->setCropBox(new PdfRectangle(10, 10, 602, 782));
$page->setBleedBox(...);
$page->setTrimBox(...);
$page->setArtBox(...);
```

Only `/CropBox` changes what an ordinary reader shows; the other three
are messages to a print workflow and are ignored on screen. Each is
checked against the media box on the way in. §14.11.2 lets a reader
quietly reduce a box that hangs off the sheet to the overlap of the two,
which is the one behaviour worth refusing outright — a trim box a hair
too big does not announce itself, it silently becomes a *different* trim
box, and the first anyone hears of it is a print run cut to the wrong
size.

Reading them back resolves the spec's inheritance: `cropBox()` falls
back to the media box, and `bleedBox()`, `trimBox()` and `artBox()` fall
back to the crop box.

### Bleed in the layout layer

`Flow` takes the same number in its own unit, and does the part that
makes it usable:

```php
$flow = new Flow($document, PageSize::A4->withBleed($bleed));
$flow->setBleed(3.0);                       // millimetres
```

Every page gets the boxes, the ones already made and the ones still to
come. **And the margins move in by the bleed.** That is the point of
having it at this layer: a page's origin sits at the corner of the
*sheet*, so without the shift a 15mm margin is 12mm from the finished
edge and every page of the job is 3mm out. After `setBleed()`, a margin
means what a designer means by one — a distance from the cut — and
`contentWidth()` is still the finished page less its margins.

Artwork that runs off the edge is drawn from `-3.0`, like anything else
outside the margins. A document has one bleed, so `setBleed()` is
settable once.

See [`examples/24-a-print-ready-flyer-with-bleed.php`](examples/24-a-print-ready-flyer-with-bleed.php).

## Making the file smaller

```php
$document->compressObjects();
```

A PDF's dictionaries are the compressible part of it, and the writer has
never compressed them: individually they are far too small for a deflate
stream of their own to pay for itself, and there was nowhere to compress
them together. An **object stream** (§7.5.7) is that somewhere — one
stream holding the bodies of a few hundred objects, deflated as a block
— and a document that is mostly dictionaries by count is most documents:
a form has two objects per field, a tagged document one per structure
element, an outline one per bookmark. On a twelve-bookmark document it
is around a 60% saving; on a plain one-page invoice, nothing worth
having.

Streams stay outside, because a stream's data has to be findable by byte
offset without decoding anything first. So does the encryption
dictionary, which a reader must read before it can decrypt the object
stream that would otherwise contain it. Everything else goes in.

Off by default, for two reasons worth knowing before turning it on. The
file stops being greppable — `/Type /Page` is no longer findable with
`strings(1)`, which matters more than it sounds like it should when
something has gone wrong at three in the morning. And the
cross-reference section becomes a stream rather than a table, so the
file needs a PDF 1.5 reader: everything current, and not everything
embedded.

Nothing about the document changes, only how it is written. The same
calls produce the same pages either way, and saving twice still gives
the same bytes twice.

## Large documents

`save()` returns the document as a string, which means the whole file
has to exist in memory at once. For most documents that is fine — a
1,500-page text report is under a megabyte of output. For the ones where
it is not, usually because they carry scans or embedded originals, write
to a stream instead:

```php
$handle = fopen('report.pdf', 'wb');
$document->writeTo($handle);
fclose($handle);
```

`saveToFile()` does exactly this internally, so it costs nothing extra
to prefer it over `file_put_contents($path, $document->save())`.

The same call sends a document to the browser without buffering it
first:

```php
header('Content-Type: application/pdf');
header('Content-Disposition: inline; filename="invoice.pdf"');

$document->writeTo(fopen('php://output', 'wb'));
```

`PdfResponse` is still the better way to send a PDF over HTTP — it gets
the filename escaping and the caching headers right — but it takes the
bytes as a string, so it cannot be used with `writeTo()`. The header
that stands in the way is `Content-Length`, which is not known until the
last byte has been written. Streaming trades it (and the browser's
progress bar) for the memory.

What this changes is where the ceiling is. Building the document still
costs what it costs — the objects are in memory either way — but writing
it no longer adds a second copy of the whole file on top. Streaming, the
write costs about three times the *largest single object*, whatever the
document's total size:

| 64 MB of embedded payloads | peak memory to write |
| --- | --- |
| `save()` | +76 MB |
| `writeTo()`, largest object 16 MB | +48 MB |
| `writeTo()`, largest object 4 MB | +12 MB |
| `writeTo()`, largest object 1 MB | +6 MB |

So the way to keep the ceiling low is to keep individual embedded files
small, rather than to keep the document small. `Flow::writeTo()` is the
same call one layer up, and runs the per-page hooks first exactly as
`save()` does.

`PdfEditor` — the incremental-update writer used for editing an existing
PDF — still builds its output in memory. It holds the original file's
bytes regardless, so the shape of the problem there is different.

## Encrypting a document you create

```php
use MightyPDF\Crypt\Permissions;

$document->encrypt(
    ownerPassword: 'owner-secret',
    userPassword: '',                      // empty: opens without prompting
    permissions: Permissions::allowing(Permissions::PRINT | Permissions::FILL_FORMS),
);

$document->saveToFile('protected.pdf');
```

AES-256 only — there's no reason to write a broken cipher into a new file.
See [`examples/09-encrypting-a-document.php`](examples/09-encrypting-a-document.php)
for a runnable version.

Be clear about what the two passwords do, because it's easy to expect more
than PDF encryption gives you:

- The **user password** is needed to open the document at all, and is the
  only thing here that provides confidentiality. Leave it empty — the
  usual arrangement — and the file opens in every viewer without a prompt,
  because the key derives from the empty string, which anybody has.
- The **owner password** is what a reader asks for before disregarding the
  permissions.

And `Permissions` are a *request*, not enforcement. The file has already
been decrypted by the time the flags are read, so turning off "copy"
doesn't stop anyone copying anything — it stops Acrobat offering the menu
item. Real confidentiality comes from a real user password and nothing
else.

## Editing an existing PDF

`PdfEditor` opens a PDF, hands you its objects, and writes your changes
back as an **incremental update**: the original bytes are preserved
verbatim and only the objects you changed are appended after them.

```php
use MightyPDF\Editor\PdfEditor;
use MightyPDF\Assembler\Types\PdfInteger;

$editor = PdfEditor::open('contract.pdf');

$catalog = $editor->catalog();
$page = $editor->resolveDictionary(
    $editor->resolveDictionary($catalog->get('Pages'))->get('Kids')->items()[0]
);

$page->set('Rotate', new PdfInteger(90));
$editor->register($page);

$editor->saveToFile('contract-rotated.pdf');
```

Objects the reader hands you *are* writer objects, so editing is just
`Dictionary::set()`. `register()` marks an object to be written — it
serves both for objects you modified and for new ones you built with an
id from `allocate()`. Saving without registering anything returns the
original bytes unchanged, byte for byte.

Appending rather than rewriting is what makes this safe on files
MightyPDF didn't write: anything you don't touch is preserved because its
bytes were never regenerated, not because the library understood it. It
also leaves an existing signature over the original byte range intact.

See [`examples/08-editing-existing-pdf.php`](examples/08-editing-existing-pdf.php)
for a runnable version.

**Reading is lenient where real files are damaged** — stale or missing
xref offsets, wrong `/Length` values, junk between dictionary entries —
and strict where being wrong would be silent. Both classic
cross-reference tables and PDF 1.5+ cross-reference streams (with object
streams and `/Predictor`) are supported, and an update is written in
whichever format the source file uses.

**Encrypted PDFs open normally.** Most encrypted PDFs have an owner
password and *no* user password, so they open in any viewer without a
prompt — and are undecodable to a reader that hasn't implemented
decryption. Those need nothing from you:

```php
$editor = PdfEditor::open('statement.pdf');
```

For one that really is password-protected, pass the password — either the
user or the owner one will do:

```php
$editor = PdfEditor::open('statement.pdf', 'hunter2');
```

RC4 (40–128 bit) and AES (128 and 256 bit) are supported, covering
revisions 2 through 6 of the standard security handler. **An encrypted
document stays encrypted**: whatever you add or change is re-enciphered
with the file's own key, so the update is readable in the same way the
rest of the file is. There's no way here to encrypt a document that
wasn't already encrypted, or to strip encryption from one that was.

## Filling in an existing form

```php
use MightyPDF\Editor\PdfEditor;
use MightyPDF\Editor\Form\FormFiller;

$editor = PdfEditor::open('application.pdf');
$filler = new FormFiller($editor);

$filler->fill([
    'applicant.first_name' => 'Zoë',
    'applicant.email'      => 'zoe@example.com',
    'subscribe'            => true,
    'plan'                 => 'pro',
]);

$editor->saveToFile('application-filled.pdf');
```

Discover what a form contains before filling it:

```php
$filler->names();            // ['applicant.first_name', 'subscribe', 'plan', ...]
$filler->values();           // current values, keyed the same way
$filler->field('plan')->onStates;  // ['basic', 'pro', 'team']
```

Field names are **hierarchical** — a field's real name is its own `/T`
joined to every ancestor's with dots, so what looks like `first_name` in
a viewer may be `applicant.first_name` in the file. Ask for a name that
isn't there and the exception names the closest match.

Checkbox and radio states are **whatever the form's author chose**.
`/Yes` is a convention, not a rule, so `onStates` tells you what this
particular document calls "ticked". Passing `true` works when there's
exactly one state to choose; a radio group needs the export value.

Every failure is loud — an unknown field, a state the widget has no
appearance for, a value longer than `/MaxLen`. All of those would
otherwise produce a PDF that opens perfectly and is wrong in the one
place anyone looks.

**Filled values are drawn**, not just stored. Each text and choice field
gets a freshly generated appearance stream, so a filled form looks filled
in even in a reader that ignores `/NeedAppearances`. The field's own
`/DA` is replayed verbatim — colour and all — and alignment (`/Q`),
multiline wrapping, comb fields and auto-sizing (`0 Tf`) are all handled.
Checkboxes and radio buttons keep their existing appearance streams and
are switched with `/AS`.

The font can be either kind. A standard font, or any font in the file
with a `/Widths` array, is measured directly; a composite (`/Type0`)
font — what a field created with an embedded font points at — is read
back out of its own CMaps and `/W` array, mapping the value's characters
to codes by reading the document's `/ToUnicode` backwards. That is the
same route a reader takes to lay out a typed value, so the appearance
drawn here agrees with the one it would have drawn.

Where a form doesn't say enough to draw with — no `/DA`, a font whose
widths aren't in the file, or a value with a character the font cannot
write at all — the stale stream is removed and `/NeedAppearances` set
instead, so a good reader still renders it and a poor one shows an empty
box rather than the previous value.

The drawing is only a picture of the value. `/V` keeps the text exactly
as you gave it, in full Unicode; a standard font's appearance
transliterates what it can't represent, so `values()` round-trips
losslessly even when the rendering can't.

XFA forms are refused unless you pass `allowXfa: true` — Acrobat may
honour the XFA description instead of the AcroForm fields, so the fill
would look correct in every tool except the one most people use.

See [`examples/10-filling-an-existing-form.php`](examples/10-filling-an-existing-form.php)
for a runnable version.

### Getting the data back out, and putting it back in

Filling a form is half of what anyone does with one. The values have to
come from somewhere, and usually have to go somewhere afterwards.

```php
$filler = new FormFiller($editor);

file_put_contents('data.xfdf', $filler->toXfdf('invoice.pdf'));
file_put_contents('data.json', $filler->toJson());

$filler->fillFromXfdf(file_get_contents('data.xfdf'));
$filler->fillFromJson('{"first_name": "Ada", "subscribe": "Yes"}');
```

**XFDF** (ISO 19444-1) is the format the other end already speaks: it is
what Acrobat's "Export Data" writes and its "Import Data" reads, and
what a browser submits when a form's `/SubmitForm` action asks for one.
The `href` is a hint about which document the data belongs to, and
nothing checks it on the way back in.

Field names nest in XFDF. A form's `address.city` is one field with a
dotted full name, and the file writes it as a `<field name="city">`
inside a `<field name="address">`; both directions here deal in the flat
dotted names `FormFiller` uses and do the nesting on the way through.
Files that flatten it instead — which plenty of hand-rolled exporters do
— are read too.

**JSON** is for the ordinary case where the far end is an application
rather than a PDF reader. A field with no value comes out as `null`
rather than being left out, so the shape of the object is the shape of
the form and a consumer can tell an empty field from one that isn't on
the form at all. Values must be scalars: a nested object is refused
rather than flattened into dotted names, since a caller who sent
`{"address": {"city": …}}` is as likely to have sent the wrong
document's data as to have meant `address.city`.

Both go through `fill()`, so both get its checking — an unknown field
name is reported with the same nearest-match suggestion, an over-long
value against `/MaxLen` is still refused, and a checkbox is still
written to `/V` *and* `/AS`.

`Xfdf::export()` and `Xfdf::parse()` are public if you want the array
rather than the round trip.

See [`examples/25-form-data-in-and-out.php`](examples/25-form-data-in-and-out.php).

## Drawing on an existing page

`PageOverlay` gives you the same `PageBuilder` used for a fresh page,
pointed at a page of a document you opened — for a logo, a stamp, a
watermark.

```php
use MightyPDF\Editor\PdfEditor;
use MightyPDF\Editor\PageOverlay;

$editor = PdfEditor::open('contract.pdf');
$page = /* a page dictionary — see the editing section above */;

$overlay = new PageOverlay($editor, $page);
$overlay->content()
    ->drawPng('logo.png', x: 430, y: 690, width: 90, height: 90)
    ->drawText(StandardFont::HelveticaBold, 13.0, 52, 72, 'DRAFT');
$overlay->apply();

$editor->saveToFile('contract-stamped.pdf');
```

Everything drawn goes into a **form XObject** invoked once from the page,
rather than being appended to the page's own content stream. That removes
three ways of damaging a page you didn't write:

- Resource names can't collide — the overlay brings its own `/Resources`,
  so it doesn't matter what `/F1` already means on the page.
- Shared resources can't be disturbed — a page's `/Resources` is often
  inherited from an ancestor or shared with other pages, so it's copied
  onto the page before anything is added.
- Graphics state can't leak — existing content that leaves an unmatched
  `q`, a clip or a colour set is bracketed in `q`/`Q` first.

Nothing is written until `apply()`, so an overlay that draws nothing
leaves the file byte-identical.

Coordinates are the page's own, origin bottom-left, with `/MediaBox`
resolved through inheritance — `$overlay->mediaBox()` reports it. A page
with a `/Rotate` is displayed turned, and the overlay turns with it,
staying put relative to the page's content; `$overlay->rotation()` tells
you if you'd rather compensate.

Form fields can be **added** to an existing document too — the file's own
`/AcroForm` is taken over rather than a second one built beside it (the
catalog has room for exactly one, so a second is simply ignored):

```php
$overlay = new PageOverlay($editor, $page);
$overlay->content()->addTextField('signed_on', x: 200, y: 560, width: 200, height: 20);
$overlay->apply();

(new FormFiller($editor))->set('signed_on', '31 July 2026');
```

Everything the original form said — `/DA`, `/Q`, `/SigFlags`, entries this
library has never heard of — is carried forward, existing fields are kept,
and a font added for the new field's `/DA` gets a `/DR` name the document
isn't already using. `/NeedAppearances` is left exactly as found, since
turning it on would ask readers to redraw every field in the document and
not just the new one; fill a new field through `FormFiller` and its
appearance is drawn directly.

See [`examples/11-stamping-an-existing-page.php`](examples/11-stamping-an-existing-page.php)
for a runnable version covering both the stamp and the added field.

## Reading text back out

```php
use MightyPDF\Reader\Text\TextExtractor;

$extractor = new TextExtractor(PdfEditor::open('report.pdf'));

echo $extractor->page(0)->text();   // one page
echo $extractor->text();            // the whole document
```

A PDF does not contain text in any form a program can simply read; it
contains instructions for putting marks on paper. Extracting means running
those instructions far enough to know what was drawn and where — tracking
the graphics and text matrices, resolving each font's encoding — and then
inferring lines and words from geometry that never stated either. It is
reconstruction, and it is imperfect by construction. It is good for
searching, checking, indexing and testing; it is not a faithful round trip.

What a code means is a property of its font, and there are three sources
for the answer, trusted in this order: `/ToUnicode` (definitive, being the
writer stating what it meant), `/Encoding` with its `/Differences` glyph
names, and the standard-14 encoding. A font with none of them — a subset
with invented glyph names and no `/ToUnicode` — genuinely cannot be read
back; those codes come out as `U+FFFD` rather than being dropped, so you
can tell "text I could not decode" from "no text".

Text inside form XObjects is followed, so a stamped or flattened page
extracts properly. Text drawn as vector outlines or living in a scanned
image is not text and no amount of trying makes it so — a scanned page
yields nothing, which is the honest answer and the reason OCR exists.

Following those XObjects is also why there are limits on how much work one
page may cause: a page can invoke one as often as it likes, and a few
hundred bytes of file can otherwise ask for more work than there is time in
the day. A page that reaches a limit returns the text it did read and says
so, rather than throwing — extraction is forgiving everywhere else, and
refusing a page outright would be the one place it was not:

```php
$page = $extractor->page(0);

if ($page->isTruncated()) {
    // There was more of this page than the extractor would follow.
}
```

`text()` cannot report it, a string having nowhere to say "and there was
more of me", so ask page by page when it matters.

For anything needing better than the built-in line and word inference —
multi-column reading order, tables, right-to-left — `fragments()` hands
back every run with its position:

```php
foreach ($extractor->page(0)->fragments() as $run) {
    printf("%6.1f, %6.1f  %4.1fpt  %s\n", $run->x, $run->y, $run->fontSize, $run->text);
}
```

## Flattening a form

A filled form is still a form: the recipient's reader will happily let
them retype the numbers in it. Flattening turns the fields into ordinary
page content — what the form showed stays exactly where it was, and there
is no longer a form to edit.

```php
use MightyPDF\Editor\Form\FormFlattener;

$editor = PdfEditor::open('filled.pdf');

(new FormFlattener($editor))->flatten();          // every field
(new FormFlattener($editor))->flatten(['total']); // just this one; the rest stay fillable

$editor->saveToFile('flattened.pdf');
```

**Nothing is re-drawn.** A widget's appearance stream already contains
what a reader displays, so flattening *places that stream* rather than
reconstructing it from `/V` and `/DA`. A flattened form is therefore
pixel-identical to the form rather than a best-effort reproduction of it.

It follows that a field with no appearance stream flattens to blank paper
— permanently, and indistinguishably from a field left empty on purpose.
That is refused, naming the fields, before anything is written:

```
These fields have no appearance stream, so flattening would draw nothing
where they are … "first_name". The document has /NeedAppearances set, so
it is relying on the reader to draw these — fill them through FormFiller
first, which draws them for real.
```

Fill through `FormFiller` first (which draws them), or pass
`allowBlankFields: true`. A signature field holding a signature is refused
outright: flattening would delete the signature while leaving a picture of
one on the page.

## Taking pages apart

Extracting, reordering, deleting and splitting are one operation seen from
different angles, so they are one class.

```php
use MightyPDF\Editor\PageSelection;

PageSelection::from('report.pdf')->range(0, 4)->toFile('summary.pdf');
PageSelection::from('report.pdf')->except(0)->toFile('no-cover.pdf');
PageSelection::from('report.pdf')->pages(3, 0, 1)->toFile('reordered.pdf');
PageSelection::from('report.pdf')->reversed()->toFile('backwards.pdf');

foreach (PageSelection::from('report.pdf')->split() as $n => $page) {
    $page->saveToFile("page-$n.pdf");
}
```

A selection is a value: every method returns a new one, so the same
starting point can be narrowed twice without the first narrowing leaking
into the second. Nothing is read or written until it becomes a document.

**Page numbers are zero-based**, like every other index here and unlike a
reader's toolbar — so an out-of-range index says so in as many words:

```
This document has 3 pages, and page indexes are zero-based, so they run
0 to 2. You asked for 3 — if you meant page 3 as a reader numbers it,
that is 2 here.
```

Selections from different files combine, which is what a merge is:

```php
PageSelection::combine(
    PageSelection::from('cover.pdf')->pages(0),
    PageSelection::from('body.pdf')->range(2),
)->saveToFile('combined.pdf');
```

`PdfMerger::merge()` is exactly this with every page of every file.

## Signing a document

This library does not sign and holds no keys. Signing means a private key,
a CMS structure and a decision about whose certificates to trust — three
things belonging to a key store, an HSM or a signing service. What *is* a
PDF writer's job is the part around them, and getting it wrong is the
usual reason a signed PDF reports itself as altered.

```php
use MightyPDF\Editor\Signature\DeferredSignature;

$prepared = DeferredSignature::prepare(
    PdfEditor::open('contract.pdf'),
    signerName: 'James Thomsen',
    reason: 'I approve this document',
);

// Whatever holds the key signs these exact bytes, detached.
$cms = $signer->sign($prepared->signedBytes());   // or ->digest('sha256')

file_put_contents('signed.pdf', $prepared->complete($cms));
```

`prepare()` reserves a `/Contents` placeholder, computes a `/ByteRange`
covering the whole file except that placeholder, and hands you exactly the
bytes it covers. `complete()` splices the blob back **without moving a
single byte** — which is what makes the byte range it was measured against
still true.

The signature is an incremental update, so the original bytes are
untouched and a signature already over them stays valid; that is the only
way a second signature can be added at all. Pass `fieldName:` to sign an
existing empty signature field; without one, an invisible field is added.

Nothing here validates the CMS, the chain, or that the blob signs what it
was given — splice in the wrong thing and you get a document that says it
is signed and fails validation. Encrypted documents are refused: a
signature dictionary's `/Contents` is exempt from encryption while the
rest of the update is not, and that exemption is not implemented.

## Tagging: structure and accessibility

A tagged PDF says two things a plain one does not: what each piece of
content *is*, and what order it is meant to be read in. Neither is
recoverable from the page — text is drawn wherever the producer put it, and
a two-column page drawn column-by-column reads as columns to a person and
as interleaved nonsense to anything working from the stream.

This is where the layout layer earns its keep. Tagging a document built
from raw drawing calls means restating, element by element, what everything
is — because a canvas genuinely does not know. A `Flow` does:

```php
use MightyPDF\Assembler\Structure\StructureRole;

$flow = new Flow($document);
$flow->tagged('en-GB');          // that is the whole of turning it on

$flow->inside(StructureRole::Section, function (Flow $flow) use ($h1, $body) {
    $flow->tag(StructureRole::Heading1, fn (Flow $f) => $f->paragraph(180, 'Annual Report', $h1));
    $flow->paragraph(180, 'Revenue rose twelve per cent.', $body);

    $flow->table([60, 40])->header(['Region', 'Revenue'])->row(['UK', '2.4m'])->end();
});

$flow->finish();
```

From `tagged()` on, `paragraph()` tags itself `/P`, a table's rows and
cells become `/TR` and `/TH`/`/TD`, a wrapped `write()` run is one `/Span`
however many lines it takes, and everything drawn through `onEachPage()`
becomes an **artifact** — outside the structure entirely, which is what
stops a screen reader announcing "Page 3 of 7" in the middle of a
sentence. You only say what the layout cannot infer: which paragraphs are
headings, where a section begins, what a figure depicts.

Headings are checked as they are added. A document whose headings go H1,
H3 has an outline that every tool building one gets wrong, so skipping a
level is refused rather than written.

Below the layout layer the same thing is available directly, for drawing
that does not go through a `Flow`:

```php
$structure = $document->structure();
$figure = $structure->document()->child(StructureRole::Figure);
$figure->setAlternateText('A bar chart of revenue by region.');

$content->tagged($figure, fn (PageBuilder $b) => $b->drawSvg($chart, 60, 400, 200, 150));
$content->artifact(fn (PageBuilder $b) => $b->drawText($font, 9, 60, 40, 'Page 1'));
```

The closure form is not decoration: a marked-content sequence has to be
closed on the stream it was opened on, and an unmatched one makes every
mark after it belong to the wrong element — silently, and only for the
people who cannot see the page.

The `/ParentTree` is built for you. It is the index letting a reader go
back *up* from a mark to its element, both directions are required, and a
document with a correct structure and a missing one is rejected by
validators and ignored by assistive technology while looking perfectly
correct in a viewer.

Tagging an existing document is **not** supported: `PageOverlay` draws
untagged, because a file's structure tree is one this library did not
build, and adding marks without attaching them is worse than not claiming
to be tagged.

## Merging PDFs

```php
use MightyPDF\Editor\PdfMerger;

$merged = PdfMerger::merge('cover.pdf', 'report.pdf', 'appendix.pdf');
$merged->saveToFile('combined.pdf');
```

Every page from every file is copied, in order, into one new `Document`.
Each page's content, resources (fonts, images, everything it draws with)
and geometry (`/MediaBox`, `/Rotate`, inherited from the source's own page
tree if it lives there rather than on the page itself) come across intact;
an object shared by several pages of the *same* source file — a font used
throughout a report, say — is copied once and shared by the copies too,
not re-embedded per page.

Annotations are carried over along with the page they're on — links and
sticky notes, and **form fields** too.

**A link's destination is settled once the merge is finished**, not as
the link is copied. A contents page links forwards, so the page it names
has not been imported yet when its link is: resolving it then would copy
the *page* — content stream and all — into a duplicate that is in no
page tree, leaving a link that goes somewhere invisible and a file
carrying one page twice. Neither shows until someone clicks.

Two consequences worth knowing. A link whose page was left behind (when
importing a subset) keeps its rectangle and does nothing, rather than
dragging that page in to make itself right. And a *named* destination is
dropped: the name trees that resolve them are not imported, and a name
meaning one thing in one file may mean another in a document merged from
several.

**Bookmarks come across too.** Each file's top-level items are appended
in the order the files were merged — wrapping each one's bookmarks under
a heading named after the file would be adding structure the documents
never had. Destinations are remapped to the merged pages, and whether an
item was written open or folded away survives with it, as do its colour
and bold/italic flags.

An item survives if anything under it still points somewhere. A bookmark
whose page was left behind is a line that goes nowhere, and a subtree of
them is a table of contents for a document that is not here; an ancestor
kept only because a descendant survived loses its own destination and
becomes what it already looked like — a heading. A bookmark that opens a
*link* keeps it, since a URI is a value like any other; one that runs
JavaScript or opens another file does not, because a merge is no place
to decide that someone else's script should still fire.

Importing a subset by hand carries bookmarks the same way, with one
extra line — `PdfMerger` is doing exactly this:

```php
$outlines = new OutlineImporter($document);

foreach ($importer->pages() as $index => $page) { /* ... */ }

$outlines->take($source, $importer->importedPages());
```

Merging forms means combining every source file's `/AcroForm` into the
one a document is allowed to have, and two things about that are worth
knowing:

- **Fields that share a name are renamed.** Two files that each have a
  `signature` field are not describing one field, but a PDF field's
  value lives on the field itself, so left sharing a name they would
  share a value — filling either would fill both. The second becomes
  `signature_2`. Read the merged document's field names back with
  `FormFiller::names()` before filling it.
- **Fonts that two forms name differently are kept apart.** A field's
  `/DA` names a font from the form's `/DR` (`/Helv 9 Tf`), and two files
  may both call something `/Helv` and mean different fonts. Where the
  two resources say the same thing they're shared; where they don't, the
  incoming one is renamed and the `/DA` strings referring to it are
  rewritten to match.

Only what a merged page needs comes across: a field's other widgets, on
pages that were not imported, are left behind rather than dragging those
pages in with them. A radio group whose buttons are all on an imported
page arrives whole.

For anything short of "every page of every file, in order," `PdfMerger` is
sugar over the lower-level `PageImporter`, which copies one page at a time
from an already-open `PdfEditor` and stays available for picking out a
subset of pages, or importing from a source opened some other way:

```php
use MightyPDF\Editor\PageImporter;
use MightyPDF\Editor\PdfEditor;

$document = new Document();
$importer = new PageImporter(PdfEditor::open('report.pdf'), $document);

foreach ($importer->pages() as $index => $page) {
    if ($index === 0) {
        continue; // skip the cover page
    }

    $importer->import($page);
}
```

See [`examples/13-merging-documents.php`](examples/13-merging-documents.php)
for a runnable version.

## Upgrading

### From 2.0

**2.1.0** is additive: general shapes, scoped transforms and clipping,
CMYK and spot colour, `Table`, Code 128 / EAN-13 / QR, attachments,
viewer preferences and page rotation, and in the layout layer runs
(`write()`), links in millimetres, columns (`onPageBreak()`, `setMargins()`)
and a page size per page. Existing code keeps working — `Color` is now
one implementation of the new `Paint` interface, so everything that took
a `Color` still does, and `Flow::newPage()` and `cell()` only gained
optional arguments.

Three things to know:

1. **`Layout\Style` and `Layout\Border` widened from `Color` to
   `Paint`.** Reading `$style->color` back out and calling `rgb()` on it
   no longer type-checks, since it may be a `CmykColor` or a
   `SpotColor`. Call `toRgb()->rgb()`, or hand the paint to something
   that takes one.
2. **`Border` gained a `$dash` parameter**, positioned after `$color`.
   Only positional callers of the constructor with six arguments are
   affected; the named constructors are unchanged.
3. **A filled shape now goes out as one path and one fill operator**
   rather than one per rectangle, and the general primitives wrap
   themselves in `q`/`Q`. Snapshot tests of PDF bytes will diff.

### From 1.x

**2.0.0** adds the layout layer. Almost all of it is additive, but two
changes are breaking, which is why it is a major version.

**1. `Font` gained `descentPt()` and `capHeightPt()`.** If you implement
`Font` yourself, add them; nothing else changes. Both built-in fonts
already have them.

The interface previously exposed only `ascentPt()`, which is half of
what placing text in a box needs — so the only way to centre anything
was a magic fraction of the type size copied from FPDF. That is a defect
whose size grows with the type: invisible in a 10pt table, centimetres
out in a headline. See `TextPlacement`.

**2. Standard fonts report their real vertical metrics.**
`StandardFont::ascentPt()` used to return a flat `0.8 × size` for all
fourteen. It now returns what Adobe's Core 14 AFMs say — Helvetica
0.718, Times 0.683, Courier 0.629 — so **top-aligned text in a standard
font moves down slightly**: 0.082 × size for Helvetica, about 0.8pt at
10pt and 22pt at 270pt.

`drawParagraph()`'s `valign: 'M'` and `'B'` also move. Both now place a
block by its real ink extent (first ascent to last descent) rather than
by line count × line height, which is what makes wrapped and unwrapped
text line up at last. `'T'` is unchanged.

**3. Standard-font widths now cover the whole WinAnsi repertoire.** The
tables previously stopped at ASCII (32–126); every code above that fell
back to `FontMetrics`'s 500-unit default. So `é` measured 500 instead of
556, and an em dash 500 instead of 1000 — text still drew, it was just
measured wrong, which moves every centred, right-aligned, wrapped and
justified line containing one. A typical line of German or French was
about 3% out; a line with several dashes, more.

Anything set in English is unaffected. Anything else moves, and it moves
towards being correct.

**4. Characters WinAnsi has no glyph for now measure zero.** Codes
0x00–0x1F and 0x7F — the C0 controls and DEL — are *encodable*: CP1252
maps them to themselves, so a tab arriving in a name from a database
column reaches the content stream as a tab. A reader draws and advances
nothing for them; the width tables used to measure each one at the
500-unit default, charging half an em for ink that was never going to be
there. Text carrying one was measured too wide, so every centred,
right-aligned, wrapped and justified line containing it sat in the wrong
place — by an amount nothing on the page accounted for, the character
itself being invisible. Text without one is unaffected.

If you snapshot-test PDF bytes in CI, expect a diff in any document with
top- or middle-aligned text in a standard font, or with non-ASCII text in
one, and re-baseline once.
Output is still byte-deterministic — the same document saved twice a
second apart is still identical bytes, and there is still no automatic
`/CreationDate`.

## Known limitations

- **Fonts**: the standard 14, plus any TrueType (`.ttf`) file, embedded
  and subset, plus any OpenType/CFF (`.otf`) file embedded whole —
  subsetting PostScript outlines is not implemented, and a CID-keyed CFF
  or a font collection (`.ttc`) is refused rather than half-embedded.
  Text drawn in a *standard* font is still limited to WinAnsi/CP1252,
  and transliterated or drawn as `?` outside it — `supports()` says so
  in advance; text in an embedded font is not. One gap in a font
  embedded whole (`subset: false`): characters past the Basic
  Multilingual Plane render correctly but do not reliably copy out of
  the page, because such a font is addressed by UTF-16 code and a
  `/ToUnicode` map takes one code width throughout. Drawn in a subset
  font — the default — they copy out fine.
- **Form fields**: a field's font must be one of the standard 14 or a
  TrueType file embedded whole; a subset is refused, since it holds only
  the characters this document already drew. Filling a field falls back
  to `/NeedAppearances` where the form says too little to draw with — no
  `/DA`, a font with no widths in the file, or a predefined CJK CMap
  encoding, whose character-id mapping is not in the document to be
  read. A composite font's `/W` array is read only for the character ids
  that can exist (0 to 65535); anything it says outside that range is a
  width no code can reach, and reading it is how a small hostile file
  asks for a great deal of memory.
- **SVG**: see the "not supported" list above. Two of those are
  deliberate rather than pending: **filters** are a pixel operation, and
  supporting them would mean rasterizing the drawing — the opposite of
  what placing vector artwork is for — and **animation** has no meaning
  in a page that does not move. Within what *is* supported, one known
  gap: CSS pseudo-classes and attribute selectors are not matched
  (combinators are).
- **Runs**: `write()` is one style per call, and a run knows nothing
  about what precedes or follows it on the line. So there is no inline
  markup to parse, and no justification across runs — the last is not a
  gap that could be closed by trying harder, since stretching the spaces
  on a line means knowing every run on it, and a run is drawn as it is
  called. A hanging indent, a drop cap or text flowed around a figure is
  built from `setMargins()` and `moveTo()` rather than declared.
- **Signing**: this library does not sign, and holds no keys. What it
  does is the half around signing — `DeferredSignature` reserves the
  `/Contents` placeholder, computes the `/ByteRange`, hands you exactly
  the bytes to be signed, and splices the result back without moving one
  of them. An external signer (an HSM, `openssl`, a signing service)
  produces the CMS. Nothing here validates that blob, the certificate
  chain, or that it signs what it was given: splice in the wrong thing
  and you get a document that says it is signed and fails validation.
  There is also no *verification* of signatures already in a file.
- **Colour management**: none. `DeviceRGB` and `DeviceCMYK` are
  uncalibrated by design and this library writes both through as given,
  which is what a caller specifying ink coverage wants and is also all
  there is: no ICC profiles, no output intent, and `toRgb()` on a
  `CmykColor` is the naive conversion rather than a managed one. A
  spot colour's alternate is stated as CMYK and nothing else.
- **PDF/A**: not produced. That matters for one thing in particular —
  a conforming Factur-X or ZUGFeRD e-invoice needs PDF/A-3. Two of the
  three pieces are now here: the attachment (an embedded file with
  `AFRelationship::Data`, listed in `/AF`) and the XMP packet, which
  `metadata()->setPacket()` will take whole if you have a profile of your
  own. What is missing is the output intent and its embedded ICC profile,
  and the restrictions that go with the conformance level. **PDF/UA** is
  closer: `structure()` produces the tagged structure, `/MarkInfo` and
  `/Lang` it requires, but nothing here runs a conformance check, so a
  document is tagged rather than certified.
- **Barcodes**: four linear symbologies (Code 39, Code 128, EAN-13,
  UPC-A), QR and Data Matrix. No EAN-8, ITF, Codabar or the postal
  symbologies, and **no PDF417** — its low-level codeword table is a
  929-of-1002 subset per cluster whose selection rule could not be
  derived, and the alternatives were copying 2 787 constants out of an
  LGPL implementation or shipping patterns nothing was checked against.
  Data Matrix encodes in ASCII mode throughout, which is optimal for
  digits (two per codeword) and up to about a third larger than it need
  be for long runs of letters; its 144×144 symbol is not produced, being
  the one size whose error-correction blocks are not all the same shape.
  Code 128 encodes ASCII only, and its code-set
  choice is the standard's greedy strategy rather than an optimal search
  — it produces the shortest symbol for ordinary input and is
  occasionally a symbol or two longer than the theoretical minimum. A QR
  code is encoded in one mode throughout rather than segmented into runs
  of different modes, which is likewise conforming and occasionally one
  version larger than optimal.
- **Form data**: XFDF and JSON, in both directions. **FDF is not
  read or written** — it is the same data in PDF syntax rather than XML,
  and Acrobat offers both from the same menu, so the gap is a real one
  for anyone handed a `.fdf`; what it is not is a different capability.
  A multi-select list box does not round-trip: XFDF gives such a field
  one `<value>` per selection, and `fill()` sets a single value per
  field, so an import takes the first and drops the rest.
- **Print boxes**: declared, not verified. `setTrimBox()` and the rest
  write what §14.11.2 asks for and refuse a box that hangs off the
  sheet, but nothing here checks that the artwork actually *reaches* the
  bleed box — a page laid out to the trim edge with 3mm of bleed
  declared around it is a conforming file and a bad print job, and only
  a rasterizer could tell the difference. There are no crop marks,
  registration marks or colour bars either; those are drawn, and
  `PageBuilder` will draw them.
- **Object streams**: written, never read back into one. `compressObjects()`
  packs a document this library assembles; the editor's incremental
  updates append plain objects whatever the file it opened was doing, so
  editing a packed document leaves the original packed and adds
  uncompressed objects after it. Correct, and larger than it needed to be.
  There is also no *repacking* of an existing file — no `qpdf
  --object-streams=generate` equivalent, which would mean rewriting the
  whole file rather than appending to it.
- **Merging**: named destinations on imported links are dropped, and a
  bookmark whose pages were all left behind goes with them (see "Merging
  PDFs" for both). The merged document always gets a fresh, flat page
  tree
  regardless of how the source files' own page trees were shaped — which
  matches how `Document` builds every page tree already. Form fields
  come across (see "Merging PDFs" above); the form's `/CO` calculation
  order does not, since it lists fields that renaming may have moved.
  A field's own actions — JavaScript among them — are copied unchanged,
  so a script that addresses another field *by name* will not find a
  field a name collision renamed.

## Development

```bash
composer install
vendor/bin/phpunit
```

Tests live in [`tests/`](tests/), mirroring the `src/` structure.
