<?php

declare(strict_types=1);

namespace MightyPDF\Content\Svg;

use MightyPDF\Content\ContentStream;
use MightyPDF\Content\Font\TrueType\FontException;
use MightyPDF\Content\Text\Utf8;

/**
 * Lays out and draws a <text> element: the runs inside it, where each
 * one sits, and the glyphs of any <textPath> along the road it names.
 *
 * Split out of SvgRenderer, which draws shapes. Text shares almost
 * nothing with them -- no bounding box, no fill-or-stroke decision, no
 * paint server -- and needs a good deal that they never do: mixed
 * content walked in DOM order, runs measured before any of them is
 * drawn, and a pen carried between them.
 *
 * Two things make this more than "set a font and show a string".
 *
 * Text is *mixed content* -- characters and tspans interleaved, in
 * order -- which SimpleXML cannot walk (it presents children and text
 * separately), so this drops to DOM for the walk. And text-anchor is not
 * a property of a run but of a *chunk*: a run of text uninterrupted by
 * an absolute position. Centring means measuring a whole chunk before
 * drawing any of it, which is why the runs are collected first and
 * emitted second.
 *
 * @phpstan-type TextRun array{text: string, style: SvgStyle, x: float|null, y: float|null, dx: float, dy: float}
 */
final class SvgTextRenderer
{
    /**
     * @param \Closure(SvgStyle): ?SvgTextFont $font chooses and registers
     *        a font for a piece of text; a null result skips that text
     * @param array<string, string> $paths the "d" of every path with an
     *        id, for <textPath> to lay text along
     */
    public function __construct(
        private readonly ContentStream $stream,
        private readonly SvgResources $resources,
        private readonly \Closure $font,
        private readonly SvgStylesheet $stylesheet,
        private readonly array $paths = [],
    ) {
    }

    /** Draws a <text> element and the <tspan>s inside it. */
    public function render(\SimpleXMLElement $element, SvgStyle $style, SvgElementPath $path): void
    {
        $node = dom_import_simplexml($element);

        $runs = [];
        $this->collectTextRuns($node, $style, $runs, $path);
        $runs = self::trimEnds($runs);

        $pen = [
            'x' => (float) ($element['x'] ?? 0),
            'y' => (float) ($element['y'] ?? 0),
        ];

        foreach (self::chunk($runs) as $chunk) {
            $this->drawTextChunk($chunk, $pen);
        }

        // Text on a path is laid out separately, and collectTextRuns()
        // has already left it alone: a <textPath> holds text placed by a
        // road rather than by a pen, and mixing the two into one list of
        // runs would mean carrying a position that means two things.
        foreach ($node->childNodes as $child) {
            if ($child instanceof \DOMElement && $child->localName === 'textPath') {
                $this->renderTextPath($child, $style, $path);
            }
        }
    }

    /**
     * Draws a <textPath>: its text laid along the path it names, glyph
     * by glyph.
     *
     * Glyph by glyph is the whole of it. A run of text on a straight
     * line is one operator with one matrix; on a curve every glyph sits
     * at its own point, turned to face its own direction, so each needs
     * a matrix of its own and the text has to be measured a character at
     * a time to know where that point is.
     */
    private function renderTextPath(\DOMElement $element, SvgStyle $style, SvgElementPath $path): void
    {
        $walk = $this->pathWalk(self::hrefOf($element));

        if ($walk === null) {
            return;
        }

        $elementPath = SvgElementPath::of('textPath', self::attributesOf($element), $path);
        $style = $style->mergeAttributes($this->stylesheet->cascade($elementPath, self::attributesOf($element)));

        $runs = [];
        $this->collectTextRuns($element, $style, $runs, $elementPath);
        $runs = self::trimEnds($runs);

        $distance = self::startOffset($element, $walk->length())
            + self::anchorOffset($style->textAnchor, $this->textWidth($runs));

        foreach ($runs as $run) {
            $this->drawRunAlong($run, $walk, $distance);
        }
    }

    /**
     * @param TextRun $run
     * @param float $distance how far along the path this run starts --
     *        carried on to where it ends, for the run after it
     */
    private function drawRunAlong(array $run, SvgPathWalk $walk, float &$distance): void
    {
        $font = ($this->font)($run['style']);

        if ($font === null) {
            return;
        }

        $style = $run['style'];

        foreach (Utf8::characters($run['text']) as $character) {
            $width = $font->font->widthOfPt($character, $style->fontSizePt) + $style->letterSpacing;

            // A glyph is placed by its middle, not by its left edge: on a
            // curve the two disagree, and placing by the edge leans every
            // glyph away from the direction it is about to turn.
            $at = $walk->at($distance + $width / 2);
            $distance += $width;

            if ($at === null) {
                // Off the end of the path. SVG does not render these
                // rather than piling them up where the path stops.
                continue;
            }

            $this->drawGlyphAt($font, $character, $style, $at, $width);
        }
    }

    /**
     * @param array{0: float, 1: float, 2: float} $at x, y and the angle of travel
     */
    private function drawGlyphAt(SvgTextFont $font, string $character, SvgStyle $style, array $at, float $width): void
    {
        try {
            $encoded = $font->writer->encode($character);
        } catch (FontException) {
            return;
        }

        [$x, $y, $angle] = $at;
        $cos = cos($angle);
        $sin = sin($angle);

        $this->stream->pushGraphicsState();
        $this->setTextAlpha($style);

        $this->stream->setFillColorRgb(...($style->fill ?? [0.0, 0.0, 0.0]))
            ->beginText()
            ->setFont($font->resourceName, $style->fontSizePt);

        // The glyph's own x-axis runs along the path and its y-axis
        // stands off to the left of it. On a horizontal path that is
        // [1 0 0 -1 x y] -- the same flip drawTextRun() uses against the
        // placement, which is the check that this is the right matrix
        // and not its mirror.
        $this->stream->showTextWithMatrix(
            [$cos, $sin, $sin, -$cos, $x - $cos * $width / 2, $y - $sin * $width / 2],
            $encoded,
            $font->writer->usesHexStrings(),
        )->endText()->popGraphicsState();
    }

    private function pathWalk(?string $reference): ?SvgPathWalk
    {
        $d = $reference === null ? null : ($this->paths[$reference] ?? null);

        if ($d === null) {
            return null;
        }

        $walk = new SvgPathWalk();
        SvgPathParser::apply($d, $walk);

        return $walk->isEmpty() ? null : $walk;
    }

    /** The id in an href/xlink:href, or null where there is none to follow. */
    private static function hrefOf(\DOMElement $element): ?string
    {
        $href = $element->getAttribute('href');

        if ($href === '') {
            $href = $element->getAttributeNS('http://www.w3.org/1999/xlink', 'href');
        }

        return str_starts_with($href, '#') ? substr($href, 1) : null;
    }

    /** startOffset, as a length or as a percentage of the path. */
    private static function startOffset(\DOMElement $element, float $length): float
    {
        $offset = trim($element->getAttribute('startOffset'));

        if ($offset === '') {
            return 0.0;
        }

        return str_ends_with($offset, '%')
            ? (float) substr($offset, 0, -1) / 100.0 * $length
            : (float) $offset;
    }

    private static function anchorOffset(string $anchor, float $width): float
    {
        return match ($anchor) {
            'middle' => -$width / 2,
            'end' => -$width,
            default => 0.0,
        };
    }

    /**
     * @param list<TextRun> $runs
     */
    private function textWidth(array $runs): float
    {
        $width = 0.0;

        foreach ($runs as $run) {
            $font = ($this->font)($run['style']);

            if ($font !== null) {
                $width += self::runWidth($font, $run);
            }
        }

        return $width;
    }

    /**
     * Walks the mixed content of a text element into a flat list of
     * runs, each with the style and any positioning that applies to it.
     *
     * @param list<TextRun> $runs
     */
    private function collectTextRuns(\DOMElement $element, SvgStyle $style, array &$runs, SvgElementPath $path): void
    {
        $previous = null;

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

            $childPath = SvgElementPath::of($node->localName, self::attributesOf($node), $path, $previous);
            $previous = $childPath;

            $childStyle = $style->mergeAttributes($this->stylesheet->cascade($childPath, self::attributesOf($node)));
            $before = count($runs);

            $this->collectTextRuns($node, $childStyle, $runs, $childPath);

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
     * @param list<TextRun> $runs
     * @return list<list<TextRun>>
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
     * @param list<TextRun> $chunk
     * @param array{x: float, y: float} $pen
     */
    private function drawTextChunk(array $chunk, array &$pen): void
    {
        $fonts = [];
        $width = 0.0;

        foreach ($chunk as $index => $run) {
            $font = ($this->font)($run['style']);
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
     * @param TextRun $run
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
        $this->setTextAlpha($style);

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
     * Sets partial opacity for a piece of text, inside the graphics
     * state the caller has already pushed for it.
     */
    private function setTextAlpha(SvgStyle $style): void
    {
        if ($style->fillOpacity >= 1.0) {
            return;
        }

        $this->stream->setExtGState(
            $this->resources->extGStateResourceName($style->fillOpacity, $style->strokeOpacity),
        );
    }

    /**
     * @param TextRun $run
     */
    private static function runWidth(SvgTextFont $font, array $run): float
    {
        $style = $run['style'];

        return $font->font->widthOfPt($run['text'], $style->fontSizePt)
            + $style->letterSpacing * count(Utf8::codePoints($run['text']));
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
     * @param list<TextRun> $runs
     * @return list<TextRun>
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
}
