# Accessibility and tagging

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
