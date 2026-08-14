# Print production

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

See [`examples/24-a-print-ready-flyer-with-bleed.php`](../examples/24-a-print-ready-flyer-with-bleed.php).
