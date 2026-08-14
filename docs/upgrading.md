# Upgrading

## Upgrading

### From 2.1

**2.2.1** fixes one thing. A signature field created with
`addSignatureField()` now carries a blank appearance stream instead of
none at all. An unsigned signature field has no value for a reader to
lay out, so `/NeedAppearances` gives it nothing to work from, and
Ghostscript reported the gap (`AcroForm field 'Sig' with no AP not
implemented`) rather than drawing the field. Nothing in your code
changes.

**2.2.0** is additive. `Document::writeTo($handle)` and `Flow::writeTo()`
stream a document to an open stream instead of building it in memory —
see [large documents](output.md#large-documents) — and `saveToFile()` now uses
that path, so it no longer holds the file twice. `save()` is unchanged.

Two fixes change the bytes of documents you may already be producing,
both toward what readers expect:

1. **Checkboxes and radio buttons now describe their own mark.** Each
   carries `/MK /CA` naming the ZapfDingbats character equivalent to the
   `MarkStyle` you chose, and the form's `/DR` carries that font under
   the conventional name `/ZaDb`. Because these forms set
   `/NeedAppearances`, readers rebuild every widget's appearance and
   discard the vector mark this library drew — so before this, the mark
   a reader showed was its own default rather than the one you asked
   for, and poppler drew nothing at all and reported `Unknown font tag
   'ZaDb'`. Nothing in your code changes.
2. **The AES-256 `/Encrypt` dictionary now states `/Length 256`.** Table
   20 scopes that key to `/V` 2 and 3, so leaving it out was correct,
   but `qpdf --check` asks for it regardless and warned on every
   encrypted file. Readers behave identically either way.

Snapshot tests of PDF bytes covering forms or encryption will diff.

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
