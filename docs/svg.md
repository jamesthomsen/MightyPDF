# SVG

## SVG vector graphics

```php
$content->drawSvg($path, x: 72, y: 560, width: 120, height: 120);
```

The SVG is placed and scaled to fit the given rectangle. Because it's
vector data (not a raster image), it stays crisp at any size — the same
file can be drawn small and large with no quality loss. Anything reaching
outside that rectangle is clipped, which is what SVG itself does: a root
viewport is `overflow: hidden` by default.

The drawing becomes a form XObject, so placing the same file the same way
more than once — a logo on every page — costs one drawing rather than
one per page. The file is read, parsed and rendered on the first
placement and reused on the rest, gradients and patterns included. Reuse
needs the placement to match as well as the file, since a gradient is
painted through a pattern whose matrix has the placement folded into it;
the same drawing placed elsewhere is genuinely a different one.

Supported: paths (lines, curves, arcs), basic shapes (`rect`, `circle`,
`ellipse`, `line`, `polyline`, `polygon`), fill/stroke in flat colours or
gradients and patterns, opacity, simple transforms
(`translate`/`scale`/`rotate`/`skew`/`matrix`), text — including text on
a path — embedded raster images, and styling from `<style>` blocks as
well as attributes.

**Gradients** — `<linearGradient>` and `<radialGradient>`, in either
`objectBoundingBox` (the default, measured across the shape) or
`userSpaceOnUse` units, with `gradientTransform`, and with
`href`/`xlink:href` inheritance so one gradient can borrow another's
stops. They become PDF shading patterns, which stay vector: a gradient
scales with the drawing rather than being rasterized into it.

`stop-opacity` is honoured, including the common "colour fading to
nothing" written as one colour with two opacities. A PDF colour carries
no transparency, so such a gradient is drawn twice — once in colour as
the shading, once in greyscale as a luminosity soft mask on the graphics
state, where white means opaque and black means invisible. A shape whose
fill *and* stroke both fade is painted under the fill's mask: the
graphics state has room for one.

**`spreadMethod="reflect"` and `"repeat"` are drawn as `pad`**, the
default: the end colours are held flat beyond the ends of the gradient
rather than bouncing or tiling.

**Patterns** — `<pattern>` becomes a PDF tiling pattern: the tile's
contents are drawn once and repeated across the shape, still as vector
artwork. `patternUnits` and `patternContentUnits` (each
`objectBoundingBox` or `userSpaceOnUse`), `patternTransform`, a
`viewBox` with `preserveAspectRatio`, and `href`/`xlink:href`
inheritance are all honoured — a pattern can borrow another's tile, its
contents, or both.

Anything can go in a tile, gradients and images included, since it is
drawn by the same renderer as the rest of the document.

A pattern is painted per shape, but shapes painted the *same* way share
one tile and one PDF pattern object — what a tile looks like depends on
the pattern and on the matrix its contents are drawn under and on
nothing else. Shapes of different sizes still get their own where the
pattern is measured in `objectBoundingBox` units, since then the tile
genuinely differs.

A pattern painted with itself paints nothing on the inner reference
rather than tiling forever, and a *chain* of patterns — each tile
painted with the next one along, which is not circular and so gets past
that check — stops at four deep or a thousand distinct tiles, whichever
comes first. Both are far past what a drawing uses and far below the
point where a few kilobytes of SVG turns into a document of hundreds of
megabytes.

**Embedded raster images** — an `<image>` element is drawn, with
`preserveAspectRatio` (`meet`, `slice` including the clip, `none`, and
the alignment keywords) honoured. PNG, JPEG and GIF are recognised from
the bytes rather than from the data URI's declared media type, which
tools get wrong.

**Only `data:` URIs are read.** An `<image href="/etc/passwd">` or an
`href` pointing at a URL is skipped, not fetched: an SVG may have
arrived from anywhere, and following a path in one would let a document
this library did not write name a file it then embeds in a document that
may be sent on. Inline is also how self-contained SVGs carry images in
practice.

**Text** — `<text>` and `<tspan>`, with `font-family`, `font-size`,
`font-weight`, `font-style`, `text-anchor`, `letter-spacing`, per-span
fill colours and the `x`/`y`/`dx`/`dy` positioning a span can carry.
Whitespace is collapsed the way SVG specifies, so indented markup does
not turn into indented text.

**`<textPath>`** lays a run of text along a path the drawing already
contains, with `startOffset` (a length or a percentage) and
`text-anchor`. Each glyph is placed at its own point along the path and
turned to face its own direction, which is what a curve requires — and
means text on a path is a glyph per operator, so copying it out of the
reader gives the characters without the word breaks. Glyphs that run off
the end of the path are not drawn, as SVG specifies.

A drawing names its font the way CSS does — a list of preferences ending
in a generic name — and there is no font catalogue here to look those up
in. By default they map onto the standard 14: anything serif-ish becomes
Times, anything monospace becomes Courier, everything else Helvetica,
with the bold and italic cuts chosen from `font-weight`/`font-style`.
Pass a resolver to use real fonts instead:

```php
$content->drawSvg($path, x: 72, y: 500, width: 200, height: 200,
    fontResolver: fn (string $family, bool $bold, bool $italic) => $bold ? $interBold : $inter);
```

**CSS in `<style>` blocks** — which is how drawing tools actually write
styling: a block of `.cls-1 { fill: #e74c3c }` rules and shapes carrying
`class="cls-1"` rather than fills of their own. Type, class, id and
universal selectors are matched, in any combination on a single element
(`rect.cls-1`) and in comma-separated groups, with the usual specificity
order and document order as the tiebreak. The cascade is honoured:
presentation attributes lose to style-block rules, which lose to the
inline `style` attribute.

All four combinators are matched too — descendant (`g .label`), child
(`g > rect`), adjacent sibling (`rect + text`) and general sibling
(`rect ~ text`) — so a sheet can style by where an element sits and not
only by what it is. Pseudo-classes and attribute selectors are still
ignored rather than approximated: they ask about state and about
attributes this renderer does not model, and a selector understood in
part matches the wrong elements confidently. An ignored selector
contributes nothing and the rest of the sheet still applies. At-rules
(`@media`, `@supports`, `@import`) are skipped whole:
the rules inside one look exactly like ordinary rules, and a drawing's
print styling is usually the opposite of its screen styling.

**Not supported** (elements are skipped, not mis-rendered): filters and
animation. A `fill="url(#…)"` naming anything that cannot be resolved
paints nothing, rather than failing the document. See
[`src/Content/Svg/SvgDocument.php`](../src/Content/Svg/SvgDocument.php) for
the exact scope.
