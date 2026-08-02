<?php

declare(strict_types=1);

namespace MightyPDF\Content\Svg;

use MightyPDF\Content\ContentStream;
use MightyPDF\Content\Font\TrueType\FontException;
use MightyPDF\Content\Text\Utf8;

/**
 * Walks a parsed SVG element tree and emits the PDF operators that draw
 * it.
 *
 * Split out of SvgDocument, which now only parses: the walk carries four
 * pieces of state at once -- the inherited style, the transform in
 * effect, the stream, and the callbacks that turn a needed resource into
 * a name -- and threading those through a dozen static methods was
 * turning every signature into a parameter list.
 *
 * The transform is tracked here even though PDF tracks its own CTM
 * perfectly well, because a gradient is painted through a pattern, and a
 * pattern's coordinates are measured from the page rather than from the
 * CTM. See SvgShadingPattern.
 */
final class SvgRenderer
{
    private const array SKIPPED_TAGS = [
        'defs', 'title', 'desc', 'metadata', 'style', 'symbol',
        'clipPath', 'mask', 'linearGradient', 'radialGradient', 'pattern',
        'filter', 'use', 'animate', 'animateTransform', 'animateMotion',
    ];

    /**
     * @param array<string, SvgGradient> $gradients
     * @param \Closure(float, float): string $extGStateResourceName
     * @param (\Closure(SvgGradient, array, array): string)|null $shadingPatternResourceName
     *        null where the caller cannot provide pattern resources, in
     *        which case a gradient fill degrades to no paint -- the same
     *        fallback as a reference that cannot be resolved at all
     * @param (\Closure(SvgStyle): ?SvgTextFont)|null $textFontResourceName
     *        chooses and registers a font for a piece of text; null
     *        itself, or a null result, skips the text
     * @param (\Closure(string): ?SvgRasterImage)|null $imageResourceName
     *        turns the bytes of an embedded raster image into a page
     *        resource, or returns null for bytes it cannot decode; null
     *        itself where the caller cannot embed images at all, in
     *        which case <image> elements are skipped
     */
    public function __construct(
        private readonly ContentStream $stream,
        private readonly array $gradients,
        private readonly SvgStylesheet $stylesheet,
        private readonly \Closure $extGStateResourceName,
        private readonly ?\Closure $shadingPatternResourceName,
        private readonly ?\Closure $imageResourceName = null,
        private readonly ?\Closure $textFontResourceName = null,
    ) {
    }

    /**
     * @param array{0: float, 1: float, 2: float, 3: float, 4: float, 5: float} $baseMatrix
     *        what the caller has already transformed this drawing by --
     *        the placement of the whole SVG on the page
     */
    public function render(\SimpleXMLElement $root, array $baseMatrix): void
    {
        $this->renderElement($root, new SvgStyle(), $baseMatrix);
    }

    /**
     * @param array{0: float, 1: float, 2: float, 3: float, 4: float, 5: float} $matrix
     */
    private function renderElement(\SimpleXMLElement $element, SvgStyle $inheritedStyle, array $matrix): void
    {
        $tag = $element->getName();

        if (in_array($tag, self::SKIPPED_TAGS, true)) {
            return;
        }

        $style = $inheritedStyle->mergeAttributes($this->cascade($tag, self::attributesOfElement($element)));
        $matrices = SvgTransform::parse(isset($element['transform']) ? (string) $element['transform'] : null);

        if ($matrices !== []) {
            $this->stream->pushGraphicsState();

            foreach ($matrices as $step) {
                $this->stream->concatMatrix(...$step);

                // The written order is the order they are concatenated
                // in, and each applies before the ones already in
                // effect -- see SvgTransform.
                $matrix = SvgTransform::compose($step, $matrix);
            }
        }

        match ($tag) {
            'g', 'svg' => $this->renderChildren($element, $style, $matrix),
            'path' => $this->renderPath($element, $style, $matrix),
            'rect' => $this->renderRect($element, $style, $matrix),
            'circle' => $this->renderCircle($element, $style, $matrix),
            'ellipse' => $this->renderEllipse($element, $style, $matrix),
            'line' => $this->renderLine($element, $style, $matrix),
            'polyline' => $this->renderPoly($element, $style, $matrix, closed: false),
            'polygon' => $this->renderPoly($element, $style, $matrix, closed: true),
            'image' => $this->renderImage($element, $style),
            'text' => $this->renderText($element, $style),
            default => null, // unrecognized element: skip, don't fail the whole document
        };

        if ($matrices !== []) {
            $this->stream->popGraphicsState();
        }
    }

    /** @param array{0: float, 1: float, 2: float, 3: float, 4: float, 5: float} $matrix */
    private function renderChildren(\SimpleXMLElement $element, SvgStyle $style, array $matrix): void
    {
        foreach ($element->children() as $child) {
            $this->renderElement($child, $style, $matrix);
        }
    }

    /** @param array{0: float, 1: float, 2: float, 3: float, 4: float, 5: float} $matrix */
    private function renderPath(\SimpleXMLElement $element, SvgStyle $style, array $matrix): void
    {
        if (!isset($element['d'])) {
            return;
        }

        $d = (string) $element['d'];

        $painted = $this->applyStyle($style, $matrix, static fn (): array => self::pathBounds($d));
        SvgPathParser::apply($d, $this->stream);
        $this->finishPainting($painted, $style);
    }

    /** @param array{0: float, 1: float, 2: float, 3: float, 4: float, 5: float} $matrix */
    private function renderRect(\SimpleXMLElement $element, SvgStyle $style, array $matrix): void
    {
        $width = (float) ($element['width'] ?? 0);
        $height = (float) ($element['height'] ?? 0);

        if ($width <= 0 || $height <= 0) {
            return;
        }

        $x = (float) ($element['x'] ?? 0);
        $y = (float) ($element['y'] ?? 0);

        $painted = $this->applyStyle($style, $matrix, static fn (): array => [$x, $y, $width, $height]);
        $this->stream->rect($x, $y, $width, $height);
        $this->finishPainting($painted, $style);
    }

    /** @param array{0: float, 1: float, 2: float, 3: float, 4: float, 5: float} $matrix */
    private function renderCircle(\SimpleXMLElement $element, SvgStyle $style, array $matrix): void
    {
        $r = (float) ($element['r'] ?? 0);

        if ($r <= 0) {
            return;
        }

        $this->renderEllipseAt(
            (float) ($element['cx'] ?? 0),
            (float) ($element['cy'] ?? 0),
            $r,
            $r,
            $style,
            $matrix,
        );
    }

    /** @param array{0: float, 1: float, 2: float, 3: float, 4: float, 5: float} $matrix */
    private function renderEllipse(\SimpleXMLElement $element, SvgStyle $style, array $matrix): void
    {
        $rx = (float) ($element['rx'] ?? 0);
        $ry = (float) ($element['ry'] ?? 0);

        if ($rx <= 0 || $ry <= 0) {
            return;
        }

        $this->renderEllipseAt(
            (float) ($element['cx'] ?? 0),
            (float) ($element['cy'] ?? 0),
            $rx,
            $ry,
            $style,
            $matrix,
        );
    }

    /** @param array{0: float, 1: float, 2: float, 3: float, 4: float, 5: float} $matrix */
    private function renderEllipseAt(
        float $cx,
        float $cy,
        float $rx,
        float $ry,
        SvgStyle $style,
        array $matrix,
    ): void {
        $painted = $this->applyStyle($style, $matrix, static fn (): array => [$cx - $rx, $cy - $ry, $rx * 2, $ry * 2]);
        $this->ellipsePath($cx, $cy, $rx, $ry);
        $this->finishPainting($painted, $style);
    }

    /**
     * Lines only ever stroke -- fill is not meaningful for a zero-area
     * path, per spec.
     *
     * @param array{0: float, 1: float, 2: float, 3: float, 4: float, 5: float} $matrix
     */
    private function renderLine(\SimpleXMLElement $element, SvgStyle $style, array $matrix): void
    {
        $x1 = (float) ($element['x1'] ?? 0);
        $y1 = (float) ($element['y1'] ?? 0);
        $x2 = (float) ($element['x2'] ?? 0);
        $y2 = (float) ($element['y2'] ?? 0);

        $painted = $this->applyStroke($style, $matrix, static fn (): array => [
            min($x1, $x2),
            min($y1, $y2),
            abs($x2 - $x1),
            abs($y2 - $y1),
        ]);

        if (!$painted) {
            return;
        }

        $this->stream->moveTo($x1, $y1)->lineTo($x2, $y2)->stroke();
    }

    /** @param array{0: float, 1: float, 2: float, 3: float, 4: float, 5: float} $matrix */
    private function renderPoly(\SimpleXMLElement $element, SvgStyle $style, array $matrix, bool $closed): void
    {
        $points = self::parsePoints(isset($element['points']) ? (string) $element['points'] : '');

        if (count($points) < 2) {
            return;
        }

        $painted = $this->applyStyle($style, $matrix, static fn (): array => self::pointBounds($points));

        $this->stream->moveTo($points[0][0], $points[0][1]);

        for ($i = 1, $count = count($points); $i < $count; ++$i) {
            $this->stream->lineTo($points[$i][0], $points[$i][1]);
        }

        if ($closed) {
            $this->stream->closePath();
        }

        $this->finishPainting($painted, $style, fillable: $closed);
    }

    /**
     * Draws a raster image embedded in the drawing.
     *
     * Only images carried inline as data: URIs are drawn -- see
     * SvgImageSource for why a file path or URL is not followed -- and
     * bytes that are not an image this library can decode are skipped
     * rather than raised, the same way an unsupported element is. A
     * broken decoration in a drawing should not fail the document it
     * decorates.
     */
    private function renderImage(\SimpleXMLElement $element, SvgStyle $style): void
    {
        $width = (float) ($element['width'] ?? 0);
        $height = (float) ($element['height'] ?? 0);

        if ($this->imageResourceName === null || $width <= 0 || $height <= 0) {
            return;
        }

        $bytes = SvgImageSource::bytes(self::href($element));

        if ($bytes === null) {
            return;
        }

        $image = ($this->imageResourceName)($bytes);

        if ($image === null) {
            return;
        }

        $x = (float) ($element['x'] ?? 0);
        $y = (float) ($element['y'] ?? 0);

        $placement = SvgAspectRatio::place(
            isset($element['preserveAspectRatio']) ? (string) $element['preserveAspectRatio'] : null,
            $x,
            $y,
            $width,
            $height,
            $image->width,
            $image->height,
        );

        $this->stream->pushGraphicsState();

        if ($style->fillOpacity < 1.0) {
            $this->stream->setExtGState(($this->extGStateResourceName)($style->fillOpacity, $style->strokeOpacity));
        }

        if ($placement['clip']) {
            // "slice" scales the image to cover its rectangle, which
            // means the parts that overflow have to be cut off rather
            // than drawn over whatever else is on the page.
            $this->stream->clipToRectangle($x, $y, $width, $height);
        }

        $this->stream->concatMatrix(...$placement['matrix'])
            ->paintXObject($image->resourceName)
            ->popGraphicsState();
    }

    /**
     * Draws a <text> element and the <tspan>s inside it.
     *
     * Two things make this more than "set a font and show a string".
     *
     * Text is *mixed content* -- characters and tspans interleaved, in
     * order -- which SimpleXML cannot walk (it presents children and
     * text separately), so this drops to DOM for the walk. And
     * text-anchor is not a property of a run but of a *chunk*: a run of
     * text uninterrupted by an absolute position. Centring means
     * measuring a whole chunk before drawing any of it, which is why
     * the runs are collected first and emitted second.
     */
    private function renderText(\SimpleXMLElement $element, SvgStyle $style): void
    {
        if ($this->textFontResourceName === null) {
            return;
        }

        $runs = [];
        $this->collectTextRuns(dom_import_simplexml($element), $style, $runs);
        $runs = self::trimEnds($runs);

        $pen = [
            'x' => (float) ($element['x'] ?? 0),
            'y' => (float) ($element['y'] ?? 0),
        ];

        foreach (self::chunk($runs) as $chunk) {
            $this->drawTextChunk($chunk, $pen);
        }
    }

    /**
     * Walks the mixed content of a text element into a flat list of
     * runs, each with the style and any positioning that applies to it.
     *
     * @param list<array{text: string, style: SvgStyle, x: float|null, y: float|null, dx: float, dy: float}> $runs
     */
    private function collectTextRuns(\DOMElement $element, SvgStyle $style, array &$runs): void
    {
        foreach ($element->childNodes as $node) {
            if ($node instanceof \DOMText) {
                $text = self::collapseWhitespace($node->textContent);

                if ($text !== '') {
                    $runs[] = ['text' => $text, 'style' => $style, 'x' => null, 'y' => null, 'dx' => 0.0, 'dy' => 0.0];
                }

                continue;
            }

            if (!$node instanceof \DOMElement || $node->localName !== 'tspan') {
                continue;
            }

            $childStyle = $style->mergeAttributes($this->cascade($node->localName, self::attributesOf($node)));
            $before = count($runs);

            $this->collectTextRuns($node, $childStyle, $runs);

            // The tspan's own positioning belongs to the first run it
            // produced -- a tspan that contains only another tspan
            // produces none of its own, and the position still has to
            // land on whatever text comes first inside it.
            if (isset($runs[$before])) {
                $runs[$before]['x'] = $node->hasAttribute('x') ? (float) $node->getAttribute('x') : $runs[$before]['x'];
                $runs[$before]['y'] = $node->hasAttribute('y') ? (float) $node->getAttribute('y') : $runs[$before]['y'];
                $runs[$before]['dx'] += (float) $node->getAttribute('dx');
                $runs[$before]['dy'] += (float) $node->getAttribute('dy');
            }
        }
    }

    /**
     * Splits runs into chunks: a chunk begins wherever a run gives an
     * absolute position, and text-anchor measures and aligns one chunk
     * at a time.
     *
     * @param list<array{text: string, style: SvgStyle, x: float|null, y: float|null, dx: float, dy: float}> $runs
     * @return list<list<array{text: string, style: SvgStyle, x: float|null, y: float|null, dx: float, dy: float}>>
     */
    private static function chunk(array $runs): array
    {
        $chunks = [];
        $current = [];

        foreach ($runs as $run) {
            if ($current !== [] && ($run['x'] !== null || $run['y'] !== null)) {
                $chunks[] = $current;
                $current = [];
            }

            $current[] = $run;
        }

        if ($current !== []) {
            $chunks[] = $current;
        }

        return $chunks;
    }

    /**
     * @param list<array{text: string, style: SvgStyle, x: float|null, y: float|null, dx: float, dy: float}> $chunk
     * @param array{x: float, y: float} $pen
     */
    private function drawTextChunk(array $chunk, array &$pen): void
    {
        $fonts = [];
        $width = 0.0;

        foreach ($chunk as $index => $run) {
            $font = ($this->textFontResourceName)($run['style']);
            $fonts[$index] = $font;

            if ($font !== null) {
                $width += self::runWidth($font, $run);
            }
        }

        $first = $chunk[0];
        $pen['x'] = ($first['x'] ?? $pen['x']) + $first['dx'];
        $pen['y'] = ($first['y'] ?? $pen['y']) + $first['dy'];

        // text-anchor shifts the whole chunk against the point it was
        // given: "middle" centres it there, "end" finishes there.
        $pen['x'] -= match ($first['style']->textAnchor) {
            'middle' => $width / 2,
            'end' => $width,
            default => 0.0,
        };

        foreach ($chunk as $index => $run) {
            $font = $fonts[$index];

            if ($font === null) {
                continue;
            }

            if ($index > 0) {
                $pen['x'] = ($run['x'] ?? $pen['x']) + $run['dx'];
                $pen['y'] = ($run['y'] ?? $pen['y']) + $run['dy'];
            }

            $this->drawTextRun($font, $run, $pen);
        }
    }

    /**
     * @param array{text: string, style: SvgStyle, x: float|null, y: float|null, dx: float, dy: float} $run
     * @param array{x: float, y: float} $pen
     */
    private function drawTextRun(SvgTextFont $font, array $run, array &$pen): void
    {
        $style = $run['style'];

        try {
            $encoded = $font->writer->encode($run['text']);
        } catch (FontException) {
            // The font has no glyph for something in this run. Skipping
            // it matches how everything else here handles what it
            // cannot draw, and beats drawing empty boxes.
            return;
        }

        $this->stream->pushGraphicsState();

        if ($style->fillOpacity < 1.0) {
            $this->stream->setExtGState(($this->extGStateResourceName)($style->fillOpacity, $style->strokeOpacity));
        }

        $this->stream->setFillColorRgb(...($style->fill ?? [0.0, 0.0, 0.0]))
            ->beginText()
            ->setFont($font->resourceName, $style->fontSizePt);

        if ($style->letterSpacing !== 0.0) {
            $this->stream->setCharacterSpacing($style->letterSpacing);
        }

        // The vertical flip counteracts the one the whole drawing is
        // placed under -- see ContentStream::showTextWithMatrix().
        $this->stream->showTextWithMatrix(
            [1.0, 0.0, 0.0, -1.0, $pen['x'], $pen['y']],
            $encoded,
            $font->writer->usesHexStrings(),
        )->endText()->popGraphicsState();

        $pen['x'] += self::runWidth($font, $run);
    }

    /**
     * @param array{text: string, style: SvgStyle, x: float|null, y: float|null, dx: float, dy: float} $run
     */
    private static function runWidth(SvgTextFont $font, array $run): float
    {
        $style = $run['style'];

        return $font->font->widthOfPt($run['text'], $style->fontSizePt)
            + $style->letterSpacing * count(Utf8::codePoints($run['text']));
    }

    /**
     * An element's own attributes with any matching CSS rules laid over
     * them.
     *
     * That order is the cascade's, not a convenience: a presentation
     * attribute is the weakest kind of styling there is, and a rule in
     * a <style> block beats it. The inline style attribute beats both,
     * which SvgStyle::mergeAttributes() applies last of all.
     *
     * @param array<string, string> $attributes
     * @return array<string, string>
     */
    private function cascade(string $tag, array $attributes): array
    {
        if ($this->stylesheet->isEmpty()) {
            return $attributes;
        }

        return array_merge($attributes, $this->stylesheet->declarationsFor($tag, $attributes));
    }

    /** @return array<string, string> */
    private static function attributesOfElement(\SimpleXMLElement $element): array
    {
        $attributes = [];

        foreach ($element->attributes() as $name => $value) {
            $attributes[(string) $name] = (string) $value;
        }

        return $attributes;
    }

    /** @return array<string, string> */
    private static function attributesOf(\DOMElement $element): array
    {
        $attributes = [];

        foreach ($element->attributes as $attribute) {
            $attributes[$attribute->name] = $attribute->value;
        }

        return $attributes;
    }

    /**
     * SVG collapses runs of whitespace -- including the newlines and
     * indentation that pretty-printed markup is full of -- into single
     * spaces.
     *
     * Deliberately not trimmed: the space in "Runs: <tspan>one</tspan>
     * and <tspan>two</tspan>" belongs to the text, and trimming each
     * piece as it is read runs the words together. Only the very start
     * and end of a text element are trimmed, once all of it has been
     * collected -- see trimEnds().
     */
    private static function collapseWhitespace(string $text): string
    {
        return (string) preg_replace('/\s+/u', ' ', $text);
    }

    /**
     * Drops the whitespace at the two ends of a text element, which is
     * indentation rather than content, and with it any run that was
     * nothing else.
     *
     * @param list<array{text: string, style: SvgStyle, x: float|null, y: float|null, dx: float, dy: float}> $runs
     * @return list<array{text: string, style: SvgStyle, x: float|null, y: float|null, dx: float, dy: float}>
     */
    private static function trimEnds(array $runs): array
    {
        if ($runs === []) {
            return [];
        }

        $last = count($runs) - 1;
        $runs[0]['text'] = ltrim($runs[0]['text']);
        $runs[$last]['text'] = rtrim($runs[$last]['text']);

        return array_values(array_filter($runs, static fn (array $run): bool => $run['text'] !== ''));
    }

    /**
     * An element's image reference, written as SVG 2's "href" or the
     * older "xlink:href" -- both are current in the wild.
     */
    private static function href(\SimpleXMLElement $element): string
    {
        $href = (string) ($element->attributes()['href'] ?? '');

        if ($href !== '') {
            return $href;
        }

        return (string) ($element->attributes('http://www.w3.org/1999/xlink')['href'] ?? '');
    }

    private function ellipsePath(float $cx, float $cy, float $rx, float $ry): void
    {
        // Standard 4-Bezier-arc circle/ellipse approximation.
        $kx = 0.5522847498307936 * $rx;
        $ky = 0.5522847498307936 * $ry;

        $this->stream->moveTo($cx + $rx, $cy)
            ->curveTo($cx + $rx, $cy + $ky, $cx + $kx, $cy + $ry, $cx, $cy + $ry)
            ->curveTo($cx - $kx, $cy + $ry, $cx - $rx, $cy + $ky, $cx - $rx, $cy)
            ->curveTo($cx - $rx, $cy - $ky, $cx - $kx, $cy - $ry, $cx, $cy - $ry)
            ->curveTo($cx + $kx, $cy - $ry, $cx + $rx, $cy - $ky, $cx + $rx, $cy)
            ->closePath();
    }

    /**
     * Sets up colour, opacity and line width for the shape about to be
     * drawn, and reports what will actually be painted.
     *
     * The report matters because a paint is not always the one the
     * style asked for: a gradient reference that leads nowhere paints
     * nothing, and a shape that paints nothing has to end its path with
     * "n" rather than with a fill of the last colour that happened to
     * be set.
     *
     * $bounds is a closure rather than a value because working out a
     * path's box means parsing it a second time, and only a gradient in
     * objectBoundingBox units ever asks.
     *
     * @param array{0: float, 1: float, 2: float, 3: float, 4: float, 5: float} $matrix
     * @param \Closure(): array{0: float, 1: float, 2: float, 3: float} $bounds
     * @return array{fill: bool, stroke: bool}
     */
    private function applyStyle(SvgStyle $style, array $matrix, \Closure $bounds): array
    {
        if ($style->fillOpacity < 1.0 || $style->strokeOpacity < 1.0) {
            $this->stream->setExtGState(($this->extGStateResourceName)($style->fillOpacity, $style->strokeOpacity));
        }

        return [
            'fill' => $this->applyFill($style, $matrix, $bounds),
            'stroke' => $this->applyStroke($style, $matrix, $bounds),
        ];
    }

    /**
     * @param array{0: float, 1: float, 2: float, 3: float, 4: float, 5: float} $matrix
     * @param \Closure(): array{0: float, 1: float, 2: float, 3: float} $bounds
     */
    private function applyFill(SvgStyle $style, array $matrix, \Closure $bounds): bool
    {
        if ($style->fillReference !== null) {
            return $this->applyGradient(
                $style->fillReference,
                $matrix,
                $bounds,
                $this->stream->setFillColorRgb(...),
                $this->stream->setFillPattern(...),
            );
        }

        if ($style->fill === null) {
            return false;
        }

        $this->stream->setFillColorRgb(...$style->fill);

        return true;
    }

    /**
     * @param array{0: float, 1: float, 2: float, 3: float, 4: float, 5: float} $matrix
     * @param \Closure(): array{0: float, 1: float, 2: float, 3: float} $bounds
     */
    private function applyStroke(SvgStyle $style, array $matrix, \Closure $bounds): bool
    {
        if ($style->strokeReference !== null) {
            $painted = $this->applyGradient(
                $style->strokeReference,
                $matrix,
                $bounds,
                $this->stream->setStrokeColorRgb(...),
                $this->stream->setStrokePattern(...),
            );

            if ($painted) {
                $this->stream->setLineWidth($style->strokeWidth);
            }

            return $painted;
        }

        if ($style->stroke === null) {
            return false;
        }

        $this->stream->setStrokeColorRgb(...$style->stroke)->setLineWidth($style->strokeWidth);

        return true;
    }

    /**
     * Paints with a url(#id) gradient reference, reporting whether
     * anything was painted at all.
     *
     * Every false here is a deliberate degradation rather than an
     * error: an unresolvable paint server, a gradient with no stops, a
     * shape with no area to measure a gradient against, or a caller
     * that cannot supply pattern resources. A broken decorative fill
     * should not take the whole document down with it -- the same call
     * SvgColor already makes for url() references it cannot follow.
     *
     * @param array{0: float, 1: float, 2: float, 3: float, 4: float, 5: float} $matrix
     * @param \Closure(): array{0: float, 1: float, 2: float, 3: float} $bounds
     * @param \Closure(float, float, float): mixed $setColor
     * @param \Closure(string): mixed $setPattern
     */
    private function applyGradient(
        string $reference,
        array $matrix,
        \Closure $bounds,
        \Closure $setColor,
        \Closure $setPattern,
    ): bool {
        $gradient = $this->gradients[$reference] ?? null;

        if ($gradient === null || $this->shadingPatternResourceName === null) {
            return false;
        }

        // A gradient whose stops are all one colour is a flat fill
        // written the long way -- and a PDF shading that interpolates a
        // colour to itself is a shading readers are entitled to reject.
        $solid = $gradient->solidColor();

        if ($solid !== null) {
            $setColor(...$solid);

            return true;
        }

        $box = $bounds();

        if (!SvgShadingPattern::canPaint($gradient, $box)) {
            return false;
        }

        $setPattern(($this->shadingPatternResourceName)($gradient, $matrix, $box));

        return true;
    }

    /** @param array{fill: bool, stroke: bool} $painted */
    private function finishPainting(array $painted, SvgStyle $style, bool $fillable = true): void
    {
        $hasFill = $fillable && $painted['fill'];

        if ($hasFill && $painted['stroke']) {
            $this->stream->fillAndStroke($style->evenOdd);
        } elseif ($hasFill) {
            $this->stream->fill($style->evenOdd);
        } elseif ($painted['stroke']) {
            $this->stream->stroke();
        } else {
            $this->stream->endPathNoOp();
        }
    }

    /** @return array{0: float, 1: float, 2: float, 3: float} */
    private static function pathBounds(string $d): array
    {
        $bounds = new PathBounds();
        SvgPathParser::apply($d, $bounds);

        return $bounds->box();
    }

    /**
     * @param list<array{0: float, 1: float}> $points
     * @return array{0: float, 1: float, 2: float, 3: float}
     */
    private static function pointBounds(array $points): array
    {
        $xs = array_column($points, 0);
        $ys = array_column($points, 1);

        return [min($xs), min($ys), max($xs) - min($xs), max($ys) - min($ys)];
    }

    /** @return list<array{0: float, 1: float}> */
    private static function parsePoints(string $text): array
    {
        preg_match_all('/-?\d*\.?\d+(?:[eE][+-]?\d+)?/', $text, $matches);
        $numbers = array_map(floatval(...), $matches[0]);

        $points = [];
        for ($i = 0, $count = count($numbers); $i + 1 < $count; $i += 2) {
            $points[] = [$numbers[$i], $numbers[$i + 1]];
        }

        return $points;
    }
}
