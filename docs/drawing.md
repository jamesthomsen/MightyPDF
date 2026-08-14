# Drawing

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

See [`examples/17-shapes-transforms-and-transparency.php`](../examples/17-shapes-transforms-and-transparency.php).

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

See [`examples/20-print-colours.php`](../examples/20-print-colours.php).
