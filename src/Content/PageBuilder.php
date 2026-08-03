<?php

declare(strict_types=1);

namespace MightyPDF\Content;

use MightyPDF\Assembler\Dictionary;
use MightyPDF\Assembler\DocumentContext;
use MightyPDF\Assembler\Form\CheckboxField;
use MightyPDF\Assembler\Form\ChoiceField;
use MightyPDF\Assembler\Form\RadioButtonWidget;
use MightyPDF\Assembler\Form\RadioGroupField;
use MightyPDF\Assembler\Form\SignatureField;
use MightyPDF\Assembler\Form\TextField;
use MightyPDF\Assembler\PageContext;
use MightyPDF\Assembler\Stream;
use MightyPDF\Assembler\Types\PdfName;
use MightyPDF\Assembler\Types\PdfReal;
use MightyPDF\Assembler\Types\PdfReference;
use MightyPDF\Assembler\Types\PdfRectangle;
use MightyPDF\Content\Barcode\Code39;
use MightyPDF\Content\Font\EmbeddedFont;
use MightyPDF\Content\Font\Font;
use MightyPDF\Content\Font\FontWriter;
use MightyPDF\Content\Font\StandardFont;
use MightyPDF\Content\Font\TrueType\FontException;
use MightyPDF\Content\Image\GifImage;
use MightyPDF\Content\Image\JpegImage;
use MightyPDF\Content\Image\PngImage;
use MightyPDF\Content\Svg\SvgDocument;
use MightyPDF\Content\Svg\SvgGradient;
use MightyPDF\Content\Svg\SvgPattern;
use MightyPDF\Content\Svg\SvgRasterImage;
use MightyPDF\Content\Svg\SvgShadingPattern;
use MightyPDF\Content\Svg\SvgSoftMask;
use MightyPDF\Content\Svg\SvgStyle;
use MightyPDF\Content\Svg\SvgTextFont;
use MightyPDF\Content\Svg\SvgTilingPattern;
use MightyPDF\Content\Text\TextWrapper;

/**
 * The content-layer entry point for drawing on a page: text now, shapes
 * and images in later milestones. Owns the page's single content Stream,
 * created lazily on first use and then appended to -- so any number of
 * draw calls still produce one combined content stream per page (not one
 * per call) -- plus the bookkeeping needed to reference supporting
 * resources (fonts, later images) from the page's /Resources dictionary.
 *
 * The side effects of "start using this font here" are split in two:
 * Font::writerFor() owns allocating/registering/caching the one shared
 * font object per document, and fontResourceName() below owns naming it
 * in this page's /Resources /Font. They are separate because the object
 * is document-scoped while the name is page-scoped -- conflating the two
 * is what let form fields on different pages collide in the shared
 * AcroForm /DR (fixed by moving that naming onto AcroForm itself). Same
 * discipline as IndirectObjectRegistry centralizing xref bookkeeping --
 * scattering these steps across call sites is exactly what produced the
 * 2012 bugs this project is rebuilding away from.
 */
final class PageBuilder
{
    private ?Stream $stream = null;

    /** @var array<string, string> Font::cacheKey() => resource name (e.g. "F1"), for page /Resources /Font */
    private array $fontResourceNames = [];
    private int $nextFontResourceNumber = 1;
    private int $nextImageResourceNumber = 1;
    private int $nextPatternResourceNumber = 1;

    /** @var array<string, string> "fillAlpha:strokeAlpha" => resource name (e.g. "GS1") */
    private array $extGStateResourceNames = [];
    private int $nextExtGStateResourceNumber = 1;

    public function __construct(
        private readonly DocumentContext $document,
        private readonly PageContext $page,
    ) {
    }

    /**
     * $r/$g/$b (0.0-1.0, default black) are set explicitly on every call
     * rather than left to whatever the page's shared content stream last
     * had in effect -- fillRectangle()/fillRect() etc. also set fill
     * color, and every drawing call on a page shares one continuous
     * content stream (see the class doc comment), so an implicit "use
     * whatever color was last set" default would make text color depend
     * on unrelated drawing order elsewhere on the page.
     */
    public function drawText(Font $font, float $sizePt, float $x, float $y, string $text, float $r = 0.0, float $g = 0.0, float $b = 0.0): static
    {
        $writer = $font->writerFor($this->document);

        // Encoded before the font is named in this page's resources, so
        // that text the font cannot draw leaves no half-finished trace
        // on a page a caller may still go on to use.
        $encoded = $writer->encode($text);
        $resourceName = $this->fontResourceName($font, $writer);

        $operators = new ContentStream();
        $operators->setFillColorRgb($r, $g, $b)
            ->beginText()
            ->setFont($resourceName, $sizePt)
            ->showTextAt($x, $y, $encoded, $writer->usesHexStrings())
            ->endText();

        $this->append($operators->bytes());

        return $this;
    }

    /**
     * Draws word-wrapped text into a (x, y, width, height) box -- (x, y)
     * is the box's bottom-left corner, matching fillRectangle()/images
     * elsewhere in this class. $height is required: this method only
     * draws, it doesn't measure -- callers that want a box auto-sized to
     * its content should call TextWrapper::wrap() themselves first (same
     * font/size/width) and size the box from the returned line count,
     * then pass that height in here. That two-step split is simpler than
     * a built-in "auto" mode would be, and needs no dry-run/rollback
     * trick, since MightyPDF is a pure writer with no reader state to
     * undo (contrast this with TCPDF's startTransaction()/MultiCell()/
     * rollbackTransaction() measurement dance).
     *
     * $align: 'L' (default), 'C', 'R', or 'J' (justified -- every line
     * except the last gets extra inter-word spacing to fill the box's
     * width; a line with no spaces to stretch is left as-is).
     * $valign: 'T' (default), 'M', or 'B' -- vertical placement of the
     * wrapped text block within the box when it's shorter than $height.
     * $r/$g/$b: see drawText()'s doc comment -- set explicitly per line
     * for the same reason.
     */
    public function drawParagraph(
        Font $font,
        float $sizePt,
        float $x,
        float $y,
        float $width,
        float $height,
        string $text,
        string $align = 'L',
        string $valign = 'T',
        ?float $lineHeightPt = null,
        float $r = 0.0,
        float $g = 0.0,
        float $b = 0.0,
    ): static {
        $lineHeightPt ??= $sizePt * 1.15;
        $writer = $font->writerFor($this->document);
        $lines = TextWrapper::wrapUtf8($text, $font, $sizePt, $width);
        $lastIndex = count($lines) - 1;

        // Places the first baseline just inside the box's top edge --
        // consistent with how drawText()'s $y is documented as a
        // baseline, not a box top.
        $ascent = $font->ascentPt($sizePt);
        $blockHeight = count($lines) * $lineHeightPt;
        $topY = match ($valign) {
            'M' => $y + $height / 2 + min($blockHeight, $height) / 2,
            'B' => $y + min($blockHeight, $height),
            default => $y + $height,
        };

        $resourceName = $this->fontResourceName($font, $writer);
        $operators = new ContentStream();
        $lineY = $topY - $ascent;

        foreach ($lines as $index => $line) {
            $lineWidth = $font->widthOfPt($line, $sizePt);
            $spaceCount = substr_count($line, ' ');

            $wordSpacing = 0.0;
            $lineX = $x;
            if ($align === 'C') {
                $lineX = $x + ($width - $lineWidth) / 2;
            } elseif ($align === 'R') {
                $lineX = $x + $width - $lineWidth;
            } elseif ($align === 'J' && $index !== $lastIndex && $spaceCount > 0 && $lineWidth < $width) {
                $wordSpacing = ($width - $lineWidth) / $spaceCount;
            }

            $operators->setFillColorRgb($r, $g, $b)
                ->beginText()
                ->setFont($resourceName, $sizePt);

            if ($writer->supportsWordSpacing()) {
                $operators->setWordSpacing($wordSpacing)
                    ->showTextAt($lineX, $lineY, $writer->encode($line), $writer->usesHexStrings());
            } else {
                // A font with two-byte codes cannot be justified with the
                // word-spacing operator -- see ContentStream::
                // showTextRunsAt(), which is what does it instead.
                $operators->showTextRunsAt(
                    $lineX,
                    $lineY,
                    self::spacedRuns($writer, $line, $wordSpacing / $sizePt * 1000.0),
                    $writer->usesHexStrings(),
                );
            }

            $operators->endText();

            $lineY -= $lineHeightPt;
        }

        $this->append($operators->bytes());

        return $this;
    }

    /**
     * One line of text as TJ runs: each word, with $gap thousandths of
     * the font size added after it. A gap of zero is one run and one
     * string, i.e. the same thing a plain Tj would have shown.
     *
     * The space itself stays attached to the word before it rather than
     * being dropped: it is a real glyph with a real width, and the gap
     * is extra space *on top of* it, exactly as the word-spacing
     * operator would have added.
     *
     * @return list<string|float>
     */
    private static function spacedRuns(FontWriter $writer, string $line, float $gap): array
    {
        if ($gap === 0.0) {
            return [$writer->encode($line)];
        }

        $words = explode(' ', $line);
        $last = count($words) - 1;
        $runs = [];

        foreach ($words as $index => $word) {
            if ($index === $last) {
                $runs[] = $writer->encode($word);

                break;
            }

            $runs[] = $writer->encode("$word ");
            $runs[] = $gap;
        }

        return $runs;
    }

    public function drawLine(
        float $x1,
        float $y1,
        float $x2,
        float $y2,
        float $lineWidthPt = 1.0,
        float $r = 0.0,
        float $g = 0.0,
        float $b = 0.0,
    ): static {
        $operators = (new ContentStream())
            ->setLineWidth($lineWidthPt)
            ->setStrokeColorRgb($r, $g, $b)
            ->moveTo($x1, $y1)
            ->lineTo($x2, $y2)
            ->stroke();

        $this->append($operators->bytes());

        return $this;
    }

    public function strokeRectangle(
        float $x,
        float $y,
        float $width,
        float $height,
        float $lineWidthPt = 1.0,
        float $r = 0.0,
        float $g = 0.0,
        float $b = 0.0,
    ): static {
        $operators = (new ContentStream())
            ->setLineWidth($lineWidthPt)
            ->setStrokeColorRgb($r, $g, $b)
            ->rect($x, $y, $width, $height)
            ->stroke();

        $this->append($operators->bytes());

        return $this;
    }

    public function fillRectangle(
        float $x,
        float $y,
        float $width,
        float $height,
        float $r = 0.0,
        float $g = 0.0,
        float $b = 0.0,
    ): static {
        $operators = (new ContentStream())
            ->setFillColorRgb($r, $g, $b)
            ->rect($x, $y, $width, $height)
            ->fill();

        $this->append($operators->bytes());

        return $this;
    }

    /**
     * Draws a 1D barcode into a (x, y, width, height) box -- (x, y) is the
     * bottom-left corner, matching fillRectangle()/images elsewhere in
     * this class. Only the bars are drawn; a human-readable caption (if
     * wanted) is the caller's job via drawText()/drawParagraph() below
     * the barcode, same as every other composite element in this class
     * stays a single-responsibility primitive.
     *
     * Bar width is uniform-narrow-element-scaled to exactly fill $width
     * (Code39::elements() reports the barcode's width in abstract
     * "module" units; this divides $width by the total module count to
     * get the actual point width of one narrow module).
     */
    public function drawBarcode(
        string $value,
        float $x,
        float $y,
        float $width,
        float $height,
        string $symbology = 'code39',
    ): static {
        if ($symbology !== 'code39') {
            throw new \InvalidArgumentException("Unsupported barcode symbology '$symbology'.");
        }

        $elements = Code39::elements($value);
        $totalModules = array_sum(array_column($elements, 'widthModules'));
        $moduleWidthPt = $width / $totalModules;

        $operators = new ContentStream();
        $cursor = $x;
        foreach ($elements as $element) {
            $elementWidthPt = $element['widthModules'] * $moduleWidthPt;
            if ($element['isBar']) {
                $operators->setFillColorRgb(0, 0, 0)
                    ->rect($cursor, $y, $elementWidthPt, $height)
                    ->fill();
            }
            $cursor += $elementWidthPt;
        }

        $this->append($operators->bytes());

        return $this;
    }

    /**
     * Escape hatch for anything not covered by the convenience methods
     * above (used by, e.g., SVG rendering to inject an arbitrary path):
     * appends a caller-built ContentStream's operators to this page's
     * content stream directly.
     */
    public function drawCustom(ContentStream $operators): static
    {
        $this->append($operators->bytes());

        return $this;
    }

    public function drawJpeg(string $path, float $x, float $y, float $width, float $height): static
    {
        $image = $this->loadImage($path, 'jpeg', fn (string $bytes) => JpegImage::fromBytes($this->document, $bytes));

        return $this->placeImage($image, $x, $y, $width, $height);
    }

    public function drawPng(string $path, float $x, float $y, float $width, float $height): static
    {
        $image = $this->loadImage($path, 'png', fn (string $bytes) => PngImage::fromBytes($this->document, $bytes));

        return $this->placeImage($image, $x, $y, $width, $height);
    }

    public function drawGif(string $path, float $x, float $y, float $width, float $height): static
    {
        $image = $this->loadImage($path, 'gif', fn (string $bytes) => GifImage::fromBytes($this->document, $bytes));

        return $this->placeImage($image, $x, $y, $width, $height);
    }

    /**
     * Reads $path once and hashes its bytes to dedupe against the
     * document-wide image cache (see Document::cachedImage()) -- the same
     * image embedded on multiple pages (e.g. a logo/letterhead) is
     * decoded and registered exactly once, and every later draw call just
     * reuses the same XObject stream. $build only runs, and only
     * allocates/registers a new object, on a cache miss.
     *
     * $format is part of the cache key so that a hit still implies the
     * caller asked for the format the bytes were actually decoded as --
     * otherwise drawPng() on a file already embedded via drawGif() would
     * short-circuit to the cached XObject and never check the PNG
     * signature, silently accepting a call that should have been rejected.
     */
    private function loadImage(string $path, string $format, \Closure $build): Stream
    {
        $bytes = file_get_contents($path);
        if ($bytes === false) {
            throw new \RuntimeException("Unable to read image file: $path");
        }

        $contentHash = $format . ':' . hash('xxh128', $bytes);

        $cached = $this->document->cachedImage($contentHash);
        if ($cached !== null) {
            return $cached;
        }

        $image = $build($bytes);
        $this->document->register($image);
        $this->document->cacheImage($contentHash, $image);

        return $image;
    }

    /**
     * Owns every side effect of "place this already-built image XObject
     * on this page": wiring it into /Resources /XObject and appending the
     * placement operators. Same "one method, every side effect"
     * discipline as fontResourceName(). Registration with the document is
     * loadImage()'s responsibility, not this method's, since a cache hit
     * must not re-register an object that's already registered.
     */
    private function placeImage(Stream $image, float $x, float $y, float $width, float $height): static
    {
        $resourceName = 'Im' . $this->nextImageResourceNumber++;

        $xObjects = $this->page->resources()->get('XObject');
        if (!$xObjects instanceof Dictionary) {
            $xObjects = new Dictionary();
            $this->page->resources()->set('XObject', $xObjects);
        }
        $xObjects->set($resourceName, new PdfReference($image->objectId()));

        $operators = (new ContentStream())->drawImage($resourceName, $x, $y, $width, $height);
        $this->append($operators->bytes());

        return $this;
    }

    /**
     * Places an SVG image, scaled/positioned to fill (x, y, width, height)
     * in points, flipping SVG's top-left/Y-down coordinate convention to
     * PDF's bottom-left/Y-up one via a single placement matrix -- every
     * coordinate inside the SVG itself is used exactly as authored, with
     * no per-shape sign-flipping needed.
     */
    public function drawSvg(
        string $path,
        float $x,
        float $y,
        float $width,
        float $height,
        ?\Closure $fontResolver = null,
    ): static {
        $svg = SvgDocument::fromFile($path);

        $scaleX = $width / $svg->viewBoxWidth;
        $scaleY = $height / $svg->viewBoxHeight;

        $placement = [
            $scaleX,
            0.0,
            0.0,
            -$scaleY,
            $x - $svg->viewBoxX * $scaleX,
            $y + $height + $svg->viewBoxY * $scaleY,
        ];

        $operators = new ContentStream();
        $operators->pushGraphicsState()->concatMatrix(...$placement);

        // The placement matrix is handed over as well as emitted: a
        // gradient is painted through a pattern, and a pattern is
        // positioned relative to the page rather than to the CTM this
        // "cm" just set. See SvgShadingPattern.
        $svg->render(
            $operators,
            $this->extGStateResourceName(...),
            $this->shadingPatternResourceName(...),
            $placement,
            $this->svgImageResource(...),
            fn (SvgStyle $style): ?SvgTextFont => $this->svgTextFont($style, $fontResolver),
            $this->tilingPatternResourceName(...),
            $this->softMaskResourceName(...),
        );

        $operators->popGraphicsState();

        $this->append($operators->bytes());

        return $this;
    }

    /**
     * Chooses the font a piece of SVG text is drawn with, and names it
     * in this page's resources.
     *
     * The default mapping is to the standard 14: an SVG names a font
     * family the way CSS does -- a list of preferences ending in a
     * generic name -- and there is no font on the machine to look those
     * up in, nor should embedding one be decided behind the caller's
     * back. So "serif" and anything Times-like becomes Times, monospace
     * becomes Courier, and everything else Helvetica, with the bold and
     * italic cuts chosen from font-weight and font-style.
     *
     * A caller who wants the drawing's own typeface passes a resolver
     * to drawSvg(), which is handed the same three facts and may return
     * any Font -- an EmbeddedFont included.
     */
    private function svgTextFont(SvgStyle $style, ?\Closure $resolver): ?SvgTextFont
    {
        $font = $resolver === null
            ? self::standardFontFor($style->fontFamily, $style->bold, $style->italic)
            : $resolver($style->fontFamily ?? '', $style->bold, $style->italic);

        if (!$font instanceof Font) {
            return null;
        }

        $writer = $font->writerFor($this->document);

        return new SvgTextFont($this->fontResourceName($font, $writer), $font, $writer);
    }

    private static function standardFontFor(?string $family, bool $bold, bool $italic): StandardFont
    {
        $family = strtolower($family ?? '');

        // The first family named that this can honour wins, which is
        // what the CSS font-family list means.
        foreach (preg_split('/\s*,\s*/', $family) ?: [] as $name) {
            $name = trim($name, " \t'\"");

            if ($name === 'monospace' || str_contains($name, 'courier') || str_contains($name, 'mono')) {
                return match (true) {
                    $bold && $italic => StandardFont::CourierBoldOblique,
                    $bold => StandardFont::CourierBold,
                    $italic => StandardFont::CourierOblique,
                    default => StandardFont::Courier,
                };
            }

            if ($name === 'serif' || str_contains($name, 'times') || str_contains($name, 'georgia')
                || str_contains($name, 'garamond') || str_contains($name, 'roman')) {
                return match (true) {
                    $bold && $italic => StandardFont::TimesBoldItalic,
                    $bold => StandardFont::TimesBold,
                    $italic => StandardFont::TimesItalic,
                    default => StandardFont::TimesRoman,
                };
            }
        }

        return match (true) {
            $bold && $italic => StandardFont::HelveticaBoldOblique,
            $bold => StandardFont::HelveticaBold,
            $italic => StandardFont::HelveticaOblique,
            default => StandardFont::Helvetica,
        };
    }

    /**
     * Embeds a raster image carried inside an SVG and names it in this
     * page's resources.
     *
     * The format is read from the bytes rather than from the data URI's
     * media type: the type is written by whatever produced the SVG and
     * is wrong often enough that trusting it would mean handing PNG
     * bytes to the JPEG decoder on someone else's typo. Bytes that are
     * not an image this library decodes -- or that are a broken one --
     * return null, and the element is skipped rather than failing the
     * whole document, matching how SVG handles everything it cannot
     * draw.
     */
    private function svgImageResource(string $bytes): ?SvgRasterImage
    {
        $format = match (true) {
            str_starts_with($bytes, "\x89PNG\r\n\x1a\n") => 'png',
            str_starts_with($bytes, "\xFF\xD8\xFF") => 'jpeg',
            str_starts_with($bytes, 'GIF87a'), str_starts_with($bytes, 'GIF89a') => 'gif',
            default => null,
        };

        if ($format === null) {
            return null;
        }

        $contentHash = "svg-$format:" . hash('xxh128', $bytes);
        $image = $this->document->cachedImage($contentHash);

        if ($image === null) {
            try {
                $image = match ($format) {
                    'png' => PngImage::fromBytes($this->document, $bytes),
                    'jpeg' => JpegImage::fromBytes($this->document, $bytes),
                    'gif' => GifImage::fromBytes($this->document, $bytes),
                };
            } catch (\RuntimeException | \InvalidArgumentException) {
                return null;
            }

            $this->document->register($image);
            $this->document->cacheImage($contentHash, $image);
        }

        $resourceName = 'Im' . $this->nextImageResourceNumber++;

        $xObjects = $this->page->resources()->get('XObject');
        if (!$xObjects instanceof Dictionary) {
            $xObjects = new Dictionary();
            $this->page->resources()->set('XObject', $xObjects);
        }
        $xObjects->set($resourceName, new PdfReference($image->objectId()));

        return new SvgRasterImage(
            $resourceName,
            (int) ($image->get('Width')?->format() ?? 0),
            (int) ($image->get('Height')?->format() ?? 0),
        );
    }

    /**
     * Owns every side effect of "paint something with this gradient":
     * build the pattern, register it, and name it in this page's
     * /Resources /Pattern. Same discipline as fontResourceName() and
     * placeImage().
     *
     * Not cached the way fonts and images are. A shading pattern carries
     * the matrix of the shape it paints, so two shapes with the same
     * gradient need two patterns -- which is also why the resource name
     * is simply the next free one rather than something derived from
     * the gradient's id.
     *
     * @param array{0: float, 1: float, 2: float, 3: float, 4: float, 5: float} $matrix
     * @param array{0: float, 1: float, 2: float, 3: float} $boundingBox
     */
    private function shadingPatternResourceName(SvgGradient $gradient, array $matrix, array $boundingBox): string
    {
        $pattern = SvgShadingPattern::build($this->document->allocate(), $gradient, $matrix, $boundingBox);
        $this->document->register($pattern);

        return $this->namePattern($pattern->objectId());
    }

    /**
     * The same for a <pattern>, whose content the SVG layer has already
     * drawn -- this owns only the PDF object it becomes.
     *
     * The pattern's /Resources is a copy of the page's own, taken now.
     * The tile's content was drawn through the same callbacks as the
     * page's, so every font, image and gradient it names is already
     * registered there under the name it used, and copying is what makes
     * those names resolve inside a stream that is not the page's. It
     * lists more than the tile uses, which costs a few entries and no
     * objects; the alternative is a second set of resource bookkeeping
     * for every nested scope. The copy is taken *before* this pattern is
     * named on the page, so a pattern can never list itself.
     *
     * @param array{0: float, 1: float, 2: float, 3: float, 4: float, 5: float} $matrix
     * @param array{0: float, 1: float, 2: float, 3: float} $boundingBox
     */
    private function tilingPatternResourceName(SvgPattern $pattern, string $content, array $matrix, array $boundingBox): string
    {
        $resources = $this->snapshotResources();

        $tiling = SvgTilingPattern::build(
            $this->document->allocate(),
            $pattern,
            $content,
            $resources,
            $matrix,
            $boundingBox,
        );
        $this->document->register($tiling);

        return $this->namePattern($tiling->objectId());
    }

    /**
     * The page's resources as they stand, two levels deep.
     *
     * Deep enough matters: /Resources holds a dictionary per category,
     * and copying only the outer one would leave the copy sharing the
     * page's /Pattern dictionary -- which this pattern is about to be
     * added to. The pattern would then contain itself, and a reader
     * following it reports a circular reference (Ghostscript does;
     * poppler renders it and says nothing).
     */
    private function snapshotResources(): Dictionary
    {
        $snapshot = new Dictionary();

        foreach ($this->page->resources()->entries() as $key => $value) {
            if ($value instanceof Dictionary) {
                $category = new Dictionary();

                foreach ($value->entries() as $name => $resource) {
                    $category->set((string) $name, $resource);
                }

                $value = $category;
            }

            $snapshot->set((string) $key, $value);
        }

        return $snapshot;
    }

    private function namePattern(int $objectId): string
    {
        $resourceName = 'P' . $this->nextPatternResourceNumber++;

        $patterns = $this->page->resources()->get('Pattern');
        if (!$patterns instanceof Dictionary) {
            $patterns = new Dictionary();
            $this->page->resources()->set('Pattern', $patterns);
        }
        $patterns->set($resourceName, new PdfReference($objectId));

        return $resourceName;
    }

    /**
     * The ExtGState carrying a fading gradient's soft mask, named in
     * this page's /Resources /ExtGState.
     *
     * Not cached the way a plain alpha state is: a mask is drawn in the
     * coordinates of the shape it masks, so two shapes with the same
     * gradient need two of them -- the same reason a shading pattern is
     * built per shape.
     *
     * @param array{0: float, 1: float, 2: float, 3: float} $boundingBox
     */
    private function softMaskResourceName(SvgGradient $gradient, array $boundingBox, float $strokeWidth): string
    {
        [$group, $state] = SvgSoftMask::build(
            $this->document->allocate(),
            $this->document->allocate(),
            $gradient,
            $boundingBox,
            $strokeWidth,
        );

        $this->document->register($group);
        $this->document->register($state);

        return $this->nameExtGState(new PdfReference($state->objectId()));
    }

    private function extGStateResourceName(float $fillAlpha, float $strokeAlpha): string
    {
        $key = "$fillAlpha:$strokeAlpha";
        if (isset($this->extGStateResourceNames[$key])) {
            return $this->extGStateResourceNames[$key];
        }

        $gsDict = new Dictionary($this->document->allocate());
        $gsDict->set('Type', new PdfName('ExtGState'));
        $gsDict->set('ca', new PdfReal($fillAlpha));
        $gsDict->set('CA', new PdfReal($strokeAlpha));
        $this->document->register($gsDict);

        return $this->extGStateResourceNames[$key] = $this->nameExtGState(new PdfReference($gsDict->objectId()));
    }

    private function nameExtGState(PdfReference $state): string
    {
        $resourceName = 'GS' . $this->nextExtGStateResourceNumber++;

        $extGStates = $this->page->resources()->get('ExtGState');
        if (!$extGStates instanceof Dictionary) {
            $extGStates = new Dictionary();
            $this->page->resources()->set('ExtGState', $extGStates);
        }
        $extGStates->set($resourceName, $state);

        return $resourceName;
    }

    /**
     * A single- or multi-line text field.
     *
     * $font may be one of the standard 14 or an embedded TrueType font
     * loaded with subset: false -- see formFontResourceName() for why a
     * subset is refused.
     */
    public function addTextField(
        string $name,
        float $x,
        float $y,
        float $width,
        float $height,
        ?string $value = null,
        Font $font = StandardFont::Helvetica,
        float $fontSizePt = 10.0,
        ?int $maxLength = null,
        ?int $align = null,
        bool $multiline = false,
        bool $readonly = false,
    ): static {
        $resourceName = $this->formFontResourceName($font);

        $field = new TextField(
            $this->document->allocate(),
            $name,
            new PdfRectangle($x, $y, $x + $width, $y + $height),
            $resourceName,
            $fontSizePt,
            $value,
            $maxLength,
            $align,
            $multiline,
            $readonly,
        );

        $this->registerField($field);

        return $this;
    }

    public function addCheckbox(
        string $name,
        float $x,
        float $y,
        float $size,
        bool $checked = false,
        string $exportValue = 'Yes',
        MarkStyle $mark = MarkStyle::Check,
    ): static {
        $onAppearance = $this->buildMarkAppearance($size, $mark);
        $offAppearance = $this->buildMarkAppearance($size, null);
        $this->document->register($onAppearance);
        $this->document->register($offAppearance);

        $field = new CheckboxField(
            $this->document->allocate(),
            $name,
            new PdfRectangle($x, $y, $x + $size, $y + $size),
            $checked,
            $onAppearance,
            $offAppearance,
            $exportValue,
        );

        $this->registerField($field);

        return $this;
    }

    /**
     * The appearance stream for one state of a button widget: $mark's
     * glyph for "on", or null for "off", which is intentionally blank (an
     * empty box). Shared by checkboxes and radio options -- the two
     * differ only in which MarkStyle they default to.
     */
    private function buildMarkAppearance(float $size, ?MarkStyle $mark): Stream
    {
        $operators = new ContentStream();
        $mark?->draw($operators, $size);

        $stream = new Stream($this->document->allocate(), $operators->bytes(), compress: false);
        $stream->set('Type', new PdfName('XObject'));
        $stream->set('Subtype', new PdfName('Form'));
        $stream->set('BBox', new PdfRectangle(0, 0, $size, $size));

        return $stream;
    }

    /**
     * A group of mutually-exclusive radio buttons sharing one field name.
     * Unlike addCheckbox(), the parent RadioGroupField is never itself a
     * page annotation -- only its per-option RadioButtonWidget kids are,
     * so registerField() (which assumes a single widget IS the field)
     * doesn't apply here; this method does the equivalent wiring itself.
     *
     * @param list<array{exportValue: string, x: float, y: float, size: float}> $options
     */
    public function addRadioGroup(string $name, array $options, ?string $checkedExportValue = null, MarkStyle $mark = MarkStyle::Dot): static
    {
        $group = new RadioGroupField($this->document->allocate(), $name, $checkedExportValue);
        $this->document->register($group);

        foreach ($options as $option) {
            $onAppearance = $this->buildMarkAppearance($option['size'], $mark);
            $offAppearance = $this->buildMarkAppearance($option['size'], null);
            $this->document->register($onAppearance);
            $this->document->register($offAppearance);

            $widget = new RadioButtonWidget(
                $this->document->allocate(),
                $group->objectId(),
                new PdfRectangle($option['x'], $option['y'], $option['x'] + $option['size'], $option['y'] + $option['size']),
                $option['exportValue'],
                $checkedExportValue !== null && $checkedExportValue === $option['exportValue'],
                $onAppearance,
                $offAppearance,
            );
            $this->document->register($widget);

            $group->addKid($widget->objectId());
            $this->page->addAnnotation($widget->objectId());
        }

        $this->document->acroForm()->addField($group->objectId());

        return $this;
    }

    /**
     * A scrollable list of options, zero or one of which is selected.
     * Same underlying /FT /Ch as addDropdown() -- see ChoiceField.
     *
     * @param list<string> $options
     */
    public function addListBox(
        string $name,
        array $options,
        float $x,
        float $y,
        float $width,
        float $height,
        ?string $value = null,
        Font $font = StandardFont::Helvetica,
        float $fontSizePt = 10.0,
    ): static {
        return $this->addChoiceField($name, $options, $x, $y, $width, $height, $value, $font, $fontSizePt, combo: false);
    }

    /**
     * A dropdown ("combo box" in spec terms) with one selected option.
     * Same underlying /FT /Ch as addListBox() -- see ChoiceField.
     *
     * @param list<string> $options
     */
    public function addDropdown(
        string $name,
        array $options,
        float $x,
        float $y,
        float $width,
        float $height,
        ?string $value = null,
        Font $font = StandardFont::Helvetica,
        float $fontSizePt = 10.0,
    ): static {
        return $this->addChoiceField($name, $options, $x, $y, $width, $height, $value, $font, $fontSizePt, combo: true);
    }

    /**
     * @param list<string> $options
     */
    private function addChoiceField(
        string $name,
        array $options,
        float $x,
        float $y,
        float $width,
        float $height,
        ?string $value,
        Font $font,
        float $fontSizePt,
        bool $combo,
    ): static {
        $resourceName = $this->formFontResourceName($font);

        $field = new ChoiceField(
            $this->document->allocate(),
            $name,
            new PdfRectangle($x, $y, $x + $width, $y + $height),
            $resourceName,
            $fontSizePt,
            $options,
            $value,
            $combo,
        );

        $this->registerField($field);

        return $this;
    }

    /**
     * An unsigned signature field placeholder -- reserves a spot on the
     * page and in /AcroForm for a signature to be added later by some
     * other process. This library does not itself sign documents; see
     * SignatureField's docblock.
     */
    public function addSignatureField(string $name, float $x, float $y, float $width, float $height): static
    {
        $field = new SignatureField($this->document->allocate(), $name, new PdfRectangle($x, $y, $x + $width, $y + $height));

        $this->registerField($field);

        return $this;
    }

    /**
     * Owns every side effect of "add this field to this page": register
     * it with the document, list it in the page's /Annots, and list it
     * in the document's single shared AcroForm /Fields. Same discipline
     * as fontResourceName()/placeImage().
     */
    private function registerField(TextField|CheckboxField|ChoiceField|SignatureField $field): void
    {
        $this->document->register($field);
        $this->page->addAnnotation($field->objectId());
        $this->document->acroForm()->addField($field->objectId());
    }

    /**
     * Resource name for $font in this page's own /Resources /Font. The
     * name is page-local (each page has its own /Resources, so /F1 on
     * two pages is unambiguous), but the font *object* it points at is
     * shared document-wide -- the font itself owns that, via
     * Font::writerFor().
     */
    private function fontResourceName(Font $font, FontWriter $writer): string
    {
        $key = $font->cacheKey();
        if (isset($this->fontResourceNames[$key])) {
            return $this->fontResourceNames[$key];
        }

        $resourceName = 'F' . $this->nextFontResourceNumber++;
        $this->fontResourceNames[$key] = $resourceName;

        $resources = $this->page->resources();
        $fonts = $resources->get('Font');
        if (!$fonts instanceof Dictionary) {
            $fonts = new Dictionary();
            $resources->set('Font', $fonts);
        }
        $fonts->set($resourceName, new PdfReference($writer->dictionary()->objectId()));

        return $resourceName;
    }

    /**
     * Unlike page /Resources, the AcroForm's /DR is one dictionary shared
     * by every page, so AcroForm owns both the naming and the dedupe --
     * see AcroForm::fontResourceName().
     *
     * A field's font is not used the way a drawn font is. Its /DA names
     * the font a *reader* lays out what someone types with, and what
     * they will type is not known here -- so a subset, which holds only
     * the glyphs this document already drew, is the one thing it must
     * not be. Hence the refusal below rather than a silent substitution:
     * a subset points at a font whose missing characters only show up
     * when someone fills the form in.
     */
    private function formFontResourceName(Font $font): string
    {
        if ($font instanceof EmbeddedFont && $font->isSubset()) {
            throw new FontException(sprintf(
                'The font "%s" is subset, so it holds only the characters this document draws -- a form field needs '
                . 'every character someone might type into it. Load it with EmbeddedFont::load($path, subset: false) '
                . 'to embed it whole.',
                $font->name(),
            ));
        }

        return $this->document->acroForm()->fontResourceName(
            $font->cacheKey(),
            $font->writerFor($this->document)->dictionary(),
        );
    }

    private function append(string $bytes): void
    {
        if ($this->stream === null) {
            $this->stream = new Stream($this->document->allocate(), '');
            $this->document->register($this->stream);
            $this->page->addContentStream($this->stream);
        }

        $this->stream->appendBytes($bytes);
    }
}
