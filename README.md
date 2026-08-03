# MightyPDF

A from-scratch PDF library for PHP: raw PDF assembly plus a content layer
for text, drawing, images, SVG, and AcroForm fields — and a reader that
can open an existing PDF and edit it in place.

Requires **PHP 8.3+**, `ext-iconv`, `ext-openssl`, and `ext-zlib`.

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
| [08-editing-existing-pdf.php](examples/08-editing-existing-pdf.php) | Opening a PDF with `PdfEditor` and editing an object (page rotation) |
| [09-encrypting-a-document.php](examples/09-encrypting-a-document.php) | AES-256 encryption with `Document::encrypt()` and `Permissions` |
| [10-filling-an-existing-form.php](examples/10-filling-an-existing-form.php) | Filling an existing PDF's form fields with `FormFiller` |
| [11-stamping-an-existing-page.php](examples/11-stamping-an-existing-page.php) | Stamping a page with `PageOverlay`, plus adding a field to an existing form |
| [12-document-metadata.php](examples/12-document-metadata.php) | Setting Title/Author/Subject/Keywords/Creator/Producer/CreationDate |
| [13-merging-documents.php](examples/13-merging-documents.php) | Combining pages from multiple PDFs into one with `PdfMerger` |

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
Characters outside that repertoire are transliterated to the nearest
ASCII equivalent (e.g. curly quotes → straight quotes) rather than
failing. For text that has to keep its own characters, embed a font.

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

Scope: TrueType outlines, i.e. a `.ttf` file with a `glyf` table.
OpenType/CFF fonts (`.otf` with PostScript outlines) and font
collections (`.ttc`) are refused by name — both are a different
embedding path in PDF, not a variation on this one.

See [`examples/14-embedding-a-font.php`](examples/14-embedding-a-font.php)
for a runnable version.

### Measuring text

Every font measures its own text, so layout math (centering, fitting a
box) is the same for both kinds:

```php
$width = $font->widthOfPt($text, $sizePt);  // width in points
$x = ($pageWidth - $width) / 2;
```

`StandardFont::metrics()` additionally exposes the standard-14 width
tables directly, keyed by WinAnsi code, for callers working in encoded
bytes.

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
  PNGs of any color type are de-interlaced first. Sub-byte bit depths
  (1/2/4, grayscale or indexed) relay verbatim when not interlaced, and
  are widened to one byte per pixel when they are.
- **GIF**: decoded to indexed color; transparency is supported.

## SVG vector graphics

```php
$content->drawSvg($path, x: 72, y: 560, width: 120, height: 120);
```

The SVG is placed and scaled to fit the given rectangle. Because it's
vector data (not a raster image), it stays crisp at any size — the same
file can be drawn small and large with no quality loss.

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
drawn by the same renderer as the rest of the document. A pattern
painted with itself paints nothing on the inner reference rather than
tiling forever.

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

`addSignatureField(string $name, float $x, float $y, float $width, float $height)` — reserves a `/Rect` and an `/AcroForm` entry for a signature to be added later by some other process. This library does not sign documents (hashing a byte range, embedding a certificate, and validating trust are a different feature this project doesn't touch anywhere else), so the field is always created unsigned, with no `/V`.

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

## Known limitations

- **Fonts**: the standard 14, plus any TrueType (`.ttf`) file, embedded
  and subset. OpenType/CFF (`.otf`) and font collections (`.ttc`) are
  refused rather than half-embedded. Text drawn in a *standard* font is
  still limited to WinAnsi/CP1252 and transliterated outside it; text in
  an embedded font is not. One gap in a font embedded whole (`subset:
  false`): characters past the Basic Multilingual Plane render correctly
  but do not reliably copy out of the page, because such a font is
  addressed by UTF-16 code and a `/ToUnicode` map takes one code width
  throughout. Drawn in a subset font — the default — they copy out fine.
- **Form fields**: a field's font must be one of the standard 14 or a
  TrueType file embedded whole; a subset is refused, since it holds only
  the characters this document already drew. Filling a field falls back
  to `/NeedAppearances` where the form says too little to draw with — no
  `/DA`, a font with no widths in the file, or a predefined CJK CMap
  encoding, whose character-id mapping is not in the document to be
  read.
- **SVG**: see the "not supported" list above. Two of those are
  deliberate rather than pending: **filters** are a pixel operation, and
  supporting them would mean rasterizing the drawing — the opposite of
  what placing vector artwork is for — and **animation** has no meaning
  in a page that does not move. Within what *is* supported, one known
  gap: CSS pseudo-classes and attribute selectors are not matched
  (combinators are).
- **Signing**: `addSignatureField()` only reserves an unsigned placeholder
  — this library has no code anywhere to hash a byte range, embed a
  certificate, or validate one, so nothing in it can actually sign a
  document.
- **Merging**: the merged document always gets a fresh, flat page tree
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
