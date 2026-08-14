# Known limitations

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
