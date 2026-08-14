# Document features

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

See [`examples/15-links-and-bookmarks.php`](../examples/15-links-and-bookmarks.php)
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

See [`examples/12-document-metadata.php`](../examples/12-document-metadata.php)
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

See [`examples/21-attachments-and-viewer-preferences.php`](../examples/21-attachments-and-viewer-preferences.php).
