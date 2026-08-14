# Forms

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

`addSignatureField(string $name, float $x, float $y, float $width, float $height)` — reserves a `/Rect` and an `/AcroForm` entry for a signature to be added later. The field is created unsigned, with no `/V` (which is what the spec says an unsigned field looks like). To actually put a signature in it, see [Signing](editing.md#signing-a-document): this library prepares and completes the signature, and an external signer holding the key produces the CMS.

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

See [`examples/10-filling-an-existing-form.php`](../examples/10-filling-an-existing-form.php)
for a runnable version.

### Getting the data back out, and putting it back in

Filling a form is half of what anyone does with one. The values have to
come from somewhere, and usually have to go somewhere afterwards.

```php
$filler = new FormFiller($editor);

file_put_contents('data.xfdf', $filler->toXfdf('invoice.pdf'));
file_put_contents('data.json', $filler->toJson());

$filler->fillFromXfdf(file_get_contents('data.xfdf'));
$filler->fillFromJson('{"first_name": "Ada", "subscribe": "Yes"}');
```

**XFDF** (ISO 19444-1) is the format the other end already speaks: it is
what Acrobat's "Export Data" writes and its "Import Data" reads, and
what a browser submits when a form's `/SubmitForm` action asks for one.
The `href` is a hint about which document the data belongs to, and
nothing checks it on the way back in.

Field names nest in XFDF. A form's `address.city` is one field with a
dotted full name, and the file writes it as a `<field name="city">`
inside a `<field name="address">`; both directions here deal in the flat
dotted names `FormFiller` uses and do the nesting on the way through.
Files that flatten it instead — which plenty of hand-rolled exporters do
— are read too.

**JSON** is for the ordinary case where the far end is an application
rather than a PDF reader. A field with no value comes out as `null`
rather than being left out, so the shape of the object is the shape of
the form and a consumer can tell an empty field from one that isn't on
the form at all. Values must be scalars: a nested object is refused
rather than flattened into dotted names, since a caller who sent
`{"address": {"city": …}}` is as likely to have sent the wrong
document's data as to have meant `address.city`.

Both go through `fill()`, so both get its checking — an unknown field
name is reported with the same nearest-match suggestion, an over-long
value against `/MaxLen` is still refused, and a checkbox is still
written to `/V` *and* `/AS`.

`Xfdf::export()` and `Xfdf::parse()` are public if you want the array
rather than the round trip.

See [`examples/25-form-data-in-and-out.php`](../examples/25-form-data-in-and-out.php).

## Flattening a form

A filled form is still a form: the recipient's reader will happily let
them retype the numbers in it. Flattening turns the fields into ordinary
page content — what the form showed stays exactly where it was, and there
is no longer a form to edit.

```php
use MightyPDF\Editor\Form\FormFlattener;

$editor = PdfEditor::open('filled.pdf');

(new FormFlattener($editor))->flatten();          // every field
(new FormFlattener($editor))->flatten(['total']); // just this one; the rest stay fillable

$editor->saveToFile('flattened.pdf');
```

**Nothing is re-drawn.** A widget's appearance stream already contains
what a reader displays, so flattening *places that stream* rather than
reconstructing it from `/V` and `/DA`. A flattened form is therefore
pixel-identical to the form rather than a best-effort reproduction of it.

It follows that a field with no appearance stream flattens to blank paper
— permanently, and indistinguishably from a field left empty on purpose.
That is refused, naming the fields, before anything is written:

```
These fields have no appearance stream, so flattening would draw nothing
where they are … "first_name". The document has /NeedAppearances set, so
it is relying on the reader to draw these — fill them through FormFiller
first, which draws them for real.
```

Fill through `FormFiller` first (which draws them), or pass
`allowBlankFields: true`. A signature field holding a signature is refused
outright: flattening would delete the signature while leaving a picture of
one on the page.
