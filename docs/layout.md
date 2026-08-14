# The layout layer

## Multi-page documents

Call `newPage()` again and build a new `PageBuilder` for each page — see
[`examples/07-combined-document.php`](../examples/07-combined-document.php)
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
[`examples/16-a-report-with-the-layout-layer.php`](../examples/16-a-report-with-the-layout-layer.php)
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

See [`examples/22-runs-columns-and-a-landscape-page.php`](../examples/22-runs-columns-and-a-landscape-page.php)
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

See [`examples/18-a-table-that-breaks-across-pages.php`](../examples/18-a-table-that-breaks-across-pages.php).

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
`Paint` — RGB, CMYK or a named ink (see [Colour](drawing.md#colour-rgb-cmyk-and-named-inks));
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
