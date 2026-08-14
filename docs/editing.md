# Editing existing PDFs

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

See [`examples/08-editing-existing-pdf.php`](../examples/08-editing-existing-pdf.php)
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

See [`examples/11-stamping-an-existing-page.php`](../examples/11-stamping-an-existing-page.php)
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

See [`examples/13-merging-documents.php`](../examples/13-merging-documents.php)
for a runnable version.

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
