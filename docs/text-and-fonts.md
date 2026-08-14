# Text and fonts

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

See [`examples/14-embedding-a-font.php`](../examples/14-embedding-a-font.php)
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
