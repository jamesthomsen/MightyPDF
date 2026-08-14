# Images

```php
$content->drawJpeg($path, x: 72,  y: 560, width: 100, height: 100);
$content->drawPng ($path, x: 220, y: 560, width: 100, height: 100);
$content->drawGif ($path, x: 368, y: 560, width: 100, height: 100);
```

All three take a file path and a placement rectangle (`x`, `y` = bottom-left
corner, `width`/`height` in points — the image is scaled to fit, not
cropped).

- **JPEG**: original file bytes are embedded verbatim (no re-encoding).
- **PNG**: non-interlaced, no-alpha `IDAT` data is relayed verbatim (no
  decompress/recompress). PNGs with a baked-in alpha channel (color types
  4/6) are split into a color image plus a `/SMask`; interlaced (Adam7)
  PNGs of any color type are de-interlaced first. Sub-byte bit depths
  (1/2/4, grayscale or indexed) relay verbatim when not interlaced, and
  are widened to one byte per pixel when they are.
- **GIF**: decoded to indexed color; transparency is supported.
- **TIFF**: the format scanners and fax gateways produce.

```php
$content->drawTiff($path, x: 72, y: 400, width: 200, height: 120);
$content->drawTiff($path, 72, 400, 200, 120, page: 2);   // a multi-page fax
```

**CCITT Group 3 and Group 4 strips are relayed, not decoded.** That is the
point rather than an optimisation: a G4 strip is already what PDF's
`/CCITTFaxDecode` expects, so a scan embeds as the same bytes it arrived
as — no decode, no re-encode, no generation loss, and a 30 MB batch of
scans that embeds in about 30 MB rather than swelling to hundreds.

Everything else is decoded and re-emitted as Flate: uncompressed, LZW,
PackBits and Deflate, in bilevel, grayscale, RGB or palette, with the
horizontal predictor undone. `PageBuilder::tiffPageCount($path)` says how
many images a file holds.

Refused rather than mis-rendered: JPEG-in-TIFF, tiled images, separated
planes (`/PlanarConfiguration 2`), CMYK and YCbCr. A fax split across
several strips is refused too — each strip is coded independently, so
concatenating them decodes correctly until the second strip and then to
noise.
