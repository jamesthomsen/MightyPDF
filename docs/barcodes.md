# Barcodes

## Barcodes

```php
use MightyPDF\Content\Barcode\Symbology;

$content->drawBarcode('MightyPDF v2.0.0', x: 72, y: 600, width: 200, height: 40,
    symbology: Symbology::Code128, quietZone: true);
```

Four linear symbologies, and only the bars are drawn — the
human-readable line underneath is the caller's, via `drawText()`.

| | Carries | Notes |
|---|---|---|
| `Symbology::Code39` | 43 characters | Simple and verbose: 12–16 modules per character, no lowercase |
| `Symbology::Code128` | all of ASCII | Two-thirds the width, and digits packed two to a symbol |
| `Symbology::Ean13` | 13 digits | Retail packaging; check digit computed or verified |
| `Symbology::UpcA` | 12 digits | The same symbol with a leading zero |

**Code 128 chooses its own code sets** — start in C for four leading
digits, switch in for a run of six, shift for a single character out of
set and switch for two — and always carries the check symbol the
standard requires, which is not the caller's to supply.

**EAN-13 computes its check digit** from twelve digits, or verifies a
thirteenth against it and refuses a mismatch rather than correcting it: a
wrong check digit is a barcode that scans as a different product.
`Ean13::normalize()` gives back the full thirteen digits for the printed
line, so it says what the symbol actually encodes.

### QR codes

```php
use MightyPDF\Content\Barcode\QrEccLevel;

$content->drawQrCode('https://example.com/invoice/2026-0417',
    x: 400, y: 600, size: 100, level: QrEccLevel::Medium);
```

Versions 1 to 40, all four error-correction levels, and numeric,
alphanumeric or byte mode chosen as whichever is compact enough to hold
the whole string. Byte mode is UTF-8.

The module count follows the data and the level, so a long string and a
short one come out at different densities in the same box. `minVersion`
pins it, which is what a run of labels or a sheet of tickets wants.

`QrEccLevel` trades capacity for damage tolerance: `Low` ≈ 7%
recoverable, `Medium` ≈ 15% (the default and the usual choice),
`Quartile` ≈ 25%, `High` ≈ 30%. Reach for the higher two when the code
will be printed small, on something that creases, or with a logo over
the middle.

### Data Matrix

The 2D symbology of small things — a component, a vial, a postal item, a
form field that has to survive a fax. It packs more into a small area than
QR and, unlike QR, needs only a one-module quiet zone.

```php
$content->drawDataMatrix('LOT-4471/A', x: 72, y: 560, size: 60);
```

Square by default. The six rectangular sizes exist for marking things that
are themselves long and thin, and are a choice rather than an optimisation
— a rectangle almost never comes out smaller:

```php
use MightyPDF\Content\Barcode\DataMatrixShape;

$content->drawDataMatrix('LOT-4471/A', 72, 560, 60, DataMatrixShape::Rectangular);
```

Encoding is ASCII mode throughout, which puts **two digits in one
codeword** — the case that dominates, since the things Data Matrix is
printed on are mostly numbered rather than described. Long runs of letters
come out up to about a third larger than C40 would manage. The symbol
sizes were checked module-for-module against libdmtx, and every one of
them round-trips through it.

### Quiet zones

A barcode printed hard against other content does not scan, and that is
invisible on the page. `quietZone: true` reserves the clear space
*inside* the box you gave, so the bars shrink and the layout is
undisturbed.

It is **on by default for QR codes and everywhere in the layout layer**,
where a symbol sits against other content. It is **off by default for
`PageBuilder::drawBarcode()`**, which is what that method has always
done — a caller leaving it off is undertaking to leave the space itself.
`Symbology::quietZoneModules()` says how much (it is asymmetric for
EAN-13).

See [`examples/19-barcodes-and-qr-codes.php`](../examples/19-barcodes-and-qr-codes.php).
