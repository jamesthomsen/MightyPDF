# Producing the file

## Making the file smaller

```php
$document->compressObjects();
```

A PDF's dictionaries are the compressible part of it, and the writer has
never compressed them: individually they are far too small for a deflate
stream of their own to pay for itself, and there was nowhere to compress
them together. An **object stream** (§7.5.7) is that somewhere — one
stream holding the bodies of a few hundred objects, deflated as a block
— and a document that is mostly dictionaries by count is most documents:
a form has two objects per field, a tagged document one per structure
element, an outline one per bookmark. On a twelve-bookmark document it
is around a 60% saving; on a plain one-page invoice, nothing worth
having.

Streams stay outside, because a stream's data has to be findable by byte
offset without decoding anything first. So does the encryption
dictionary, which a reader must read before it can decrypt the object
stream that would otherwise contain it. Everything else goes in.

Off by default, for two reasons worth knowing before turning it on. The
file stops being greppable — `/Type /Page` is no longer findable with
`strings(1)`, which matters more than it sounds like it should when
something has gone wrong at three in the morning. And the
cross-reference section becomes a stream rather than a table, so the
file needs a PDF 1.5 reader: everything current, and not everything
embedded.

Nothing about the document changes, only how it is written. The same
calls produce the same pages either way, and saving twice still gives
the same bytes twice.

## Large documents

`save()` returns the document as a string, which means the whole file
has to exist in memory at once. For most documents that is fine — a
1,500-page text report is under a megabyte of output. For the ones where
it is not, usually because they carry scans or embedded originals, write
to a stream instead:

```php
$handle = fopen('report.pdf', 'wb');
$document->writeTo($handle);
fclose($handle);
```

`saveToFile()` does exactly this internally, so it costs nothing extra
to prefer it over `file_put_contents($path, $document->save())`.

The same call sends a document to the browser without buffering it
first:

```php
header('Content-Type: application/pdf');
header('Content-Disposition: inline; filename="invoice.pdf"');

$document->writeTo(fopen('php://output', 'wb'));
```

`PdfResponse` is still the better way to send a PDF over HTTP — it gets
the filename escaping and the caching headers right — but it takes the
bytes as a string, so it cannot be used with `writeTo()`. The header
that stands in the way is `Content-Length`, which is not known until the
last byte has been written. Streaming trades it (and the browser's
progress bar) for the memory.

What this changes is where the ceiling is. Building the document still
costs what it costs — the objects are in memory either way — but writing
it no longer adds a second copy of the whole file on top. Streaming, the
write costs about three times the *largest single object*, whatever the
document's total size:

| 64 MB of embedded payloads | peak memory to write |
| --- | --- |
| `save()` | +76 MB |
| `writeTo()`, largest object 16 MB | +48 MB |
| `writeTo()`, largest object 4 MB | +12 MB |
| `writeTo()`, largest object 1 MB | +6 MB |

So the way to keep the ceiling low is to keep individual embedded files
small, rather than to keep the document small. `Flow::writeTo()` is the
same call one layer up, and runs the per-page hooks first exactly as
`save()` does.

`PdfEditor` — the incremental-update writer used for editing an existing
PDF — still builds its output in memory. It holds the original file's
bytes regardless, so the shape of the problem there is different.

## Encrypting a document you create

```php
use MightyPDF\Crypt\Permissions;

$document->encrypt(
    ownerPassword: 'owner-secret',
    userPassword: '',                      // empty: opens without prompting
    permissions: Permissions::allowing(Permissions::PRINT | Permissions::FILL_FORMS),
);

$document->saveToFile('protected.pdf');
```

AES-256 only — there's no reason to write a broken cipher into a new file.
See [`examples/09-encrypting-a-document.php`](../examples/09-encrypting-a-document.php)
for a runnable version.

Be clear about what the two passwords do, because it's easy to expect more
than PDF encryption gives you:

- The **user password** is needed to open the document at all, and is the
  only thing here that provides confidentiality. Leave it empty — the
  usual arrangement — and the file opens in every viewer without a prompt,
  because the key derives from the empty string, which anybody has.
- The **owner password** is what a reader asks for before disregarding the
  permissions.

And `Permissions` are a *request*, not enforcement. The file has already
been decrypted by the time the flags are read, so turning off "copy"
doesn't stop anyone copying anything — it stops Acrobat offering the menu
item. Real confidentiality comes from a real user password and nothing
else.

## When things go wrong

Everything this library throws implements `MightyPDF\Exception\PdfException`,
so one catch covers the lot:

```php
use MightyPDF\Exception\PdfException;

try {
    $document->saveToFile($path);
} catch (PdfException $failure) {
    // Ours: a bad argument, a file that would not open, a font that
    // cannot draw what it was given.
}
```

Underneath, the types are the ones you would expect, because the marker
is an interface rather than a base class and nothing fights PHP's own
hierarchy:

| What happened | What you catch |
| --- | --- |
| An argument was wrong when it was passed | `Exception\InvalidArgumentException` (extends the SPL one) |
| Something reasonable failed anyway | `Exception\RuntimeException` |
| A sequence of calls that cannot be right | `Exception\LogicException` |
| A PDF would not parse | `Reader\ParseException` |
| A password did not open a file | `Crypt\DecryptionException` |
| A font could not be read or embedded | `Content\Font\TrueType\FontException` |
| A form field could not be filled | `Editor\Form\FormException` |

Each of the first three extends the SPL exception it replaces, so
`catch (\InvalidArgumentException $e)` written against any earlier
version keeps working exactly as it did.
