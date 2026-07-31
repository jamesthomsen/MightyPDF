<?php

declare(strict_types=1);

namespace MightyPDF\Content;

use MightyPDF\Assembler\Dictionary;
use MightyPDF\Assembler\Document;
use MightyPDF\Assembler\Form\CheckboxField;
use MightyPDF\Assembler\Form\RadioButtonWidget;
use MightyPDF\Assembler\Form\RadioGroupField;
use MightyPDF\Assembler\Form\TextField;
use MightyPDF\Assembler\Page;
use MightyPDF\Assembler\Stream;
use MightyPDF\Assembler\Types\PdfName;
use MightyPDF\Assembler\Types\PdfReal;
use MightyPDF\Assembler\Types\PdfReference;
use MightyPDF\Assembler\Types\PdfRectangle;
use MightyPDF\Assembler\Types\WinAnsiEncoding;
use MightyPDF\Content\Barcode\Code39;
use MightyPDF\Content\Font\StandardFont;
use MightyPDF\Content\Image\GifImage;
use MightyPDF\Content\Image\JpegImage;
use MightyPDF\Content\Image\PngImage;
use MightyPDF\Content\Svg\SvgDocument;
use MightyPDF\Content\Text\TextWrapper;

/**
 * The content-layer entry point for drawing on a page: text now, shapes
 * and images in later milestones. Owns the page's single content Stream,
 * created lazily on first use and then appended to -- so any number of
 * draw calls still produce one combined content stream per page (not one
 * per call) -- plus the bookkeeping needed to reference supporting
 * resources (fonts, later images) from the page's /Resources dictionary.
 *
 * A single method (fontResourceName()) owns every side effect of "start
 * using this font on this page": allocating and registering the Font
 * dictionary, and wiring it into /Resources /Font. Same discipline as
 * IndirectObjectRegistry centralizing xref bookkeeping -- scattering
 * these steps across call sites is exactly what produced the 2012 bugs
 * this project is rebuilding away from.
 */
final class PageBuilder
{
    private ?Stream $stream = null;

    /** @var array<string, string> StandardFont case name => resource name (e.g. "F1"), for page /Resources /Font */
    private array $fontResourceNames = [];
    private int $nextFontResourceNumber = 1;
    private int $nextImageResourceNumber = 1;

    /** @var array<string, string> StandardFont case name => resource name, for AcroForm /DR /Font */
    private array $formFontResourceNames = [];
    private int $nextFormFontResourceNumber = 1;

    /** @var array<string, string> "fillAlpha:strokeAlpha" => resource name (e.g. "GS1") */
    private array $extGStateResourceNames = [];
    private int $nextExtGStateResourceNumber = 1;

    public function __construct(
        private readonly Document $document,
        private readonly Page $page,
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
    public function drawText(StandardFont $font, float $sizePt, float $x, float $y, string $text, float $r = 0.0, float $g = 0.0, float $b = 0.0): static
    {
        $resourceName = $this->fontResourceName($font);
        $encoded = WinAnsiEncoding::encode($text);

        $operators = new ContentStream();
        $operators->setFillColorRgb($r, $g, $b)
            ->beginText()
            ->setFont($resourceName, $sizePt)
            ->showTextAt($x, $y, $encoded)
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
        StandardFont $font,
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
        $metrics = $font->metrics();
        $lines = TextWrapper::wrap($text, $metrics, $sizePt, $width);
        $lastIndex = count($lines) - 1;

        // No ascent metric is shipped for the standard-14 fonts (see
        // FontMetrics), so this uses a standard approximation (~0.8 of
        // the nominal size) to place the first baseline just inside the
        // box's top edge -- consistent with how drawText()'s $y is
        // documented as a baseline, not a box top.
        $ascent = $sizePt * 0.8;
        $blockHeight = count($lines) * $lineHeightPt;
        $topY = match ($valign) {
            'M' => $y + $height / 2 + min($blockHeight, $height) / 2,
            'B' => $y + min($blockHeight, $height),
            default => $y + $height,
        };

        $resourceName = $this->fontResourceName($font);
        $operators = new ContentStream();
        $lineY = $topY - $ascent;

        foreach ($lines as $index => $line) {
            $lineWidth = $metrics->widthOf($line, $sizePt);
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
                ->setFont($resourceName, $sizePt)
                ->setWordSpacing($wordSpacing)
                ->showTextAt($lineX, $lineY, $line)
                ->endText();

            $lineY -= $lineHeightPt;
        }

        $this->append($operators->bytes());

        return $this;
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
        $image = $this->loadImage($path, fn (string $bytes) => JpegImage::fromBytes($this->document->registry()->allocate(), $bytes));

        return $this->placeImage($image, $x, $y, $width, $height);
    }

    public function drawPng(string $path, float $x, float $y, float $width, float $height): static
    {
        $image = $this->loadImage($path, fn (string $bytes) => PngImage::fromBytes($this->document->registry(), $bytes));

        return $this->placeImage($image, $x, $y, $width, $height);
    }

    public function drawGif(string $path, float $x, float $y, float $width, float $height): static
    {
        $image = $this->loadImage($path, fn (string $bytes) => GifImage::fromBytes($this->document->registry()->allocate(), $bytes));

        return $this->placeImage($image, $x, $y, $width, $height);
    }

    /**
     * Reads $path once and hashes its bytes to dedupe against the
     * document-wide image cache (see Document::cachedImage()) -- the same
     * image embedded on multiple pages (e.g. a logo/letterhead) is
     * decoded and registered exactly once, and every later draw call just
     * reuses the same XObject stream. $build only runs, and only
     * allocates/registers a new object, on a cache miss.
     */
    private function loadImage(string $path, \Closure $build): Stream
    {
        $bytes = file_get_contents($path);
        if ($bytes === false) {
            throw new \RuntimeException("Unable to read image file: $path");
        }

        $contentHash = hash('xxh128', $bytes);

        $cached = $this->document->cachedImage($contentHash);
        if ($cached !== null) {
            return $cached;
        }

        $image = $build($bytes);
        $this->document->registry()->register($image);
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
    public function drawSvg(string $path, float $x, float $y, float $width, float $height): static
    {
        $svg = SvgDocument::fromFile($path);

        $scaleX = $width / $svg->viewBoxWidth;
        $scaleY = $height / $svg->viewBoxHeight;

        $operators = new ContentStream();
        $operators->pushGraphicsState()->concatMatrix(
            $scaleX,
            0,
            0,
            -$scaleY,
            $x - $svg->viewBoxX * $scaleX,
            $y + $height + $svg->viewBoxY * $scaleY,
        );

        $svg->render($operators, $this->extGStateResourceName(...));

        $operators->popGraphicsState();

        $this->append($operators->bytes());

        return $this;
    }

    private function extGStateResourceName(float $fillAlpha, float $strokeAlpha): string
    {
        $key = "$fillAlpha:$strokeAlpha";
        if (isset($this->extGStateResourceNames[$key])) {
            return $this->extGStateResourceNames[$key];
        }

        $gsDict = new Dictionary($this->document->registry()->allocate());
        $gsDict->set('Type', new PdfName('ExtGState'));
        $gsDict->set('ca', new PdfReal($fillAlpha));
        $gsDict->set('CA', new PdfReal($strokeAlpha));
        $this->document->registry()->register($gsDict);

        $resourceName = 'GS' . $this->nextExtGStateResourceNumber++;
        $this->extGStateResourceNames[$key] = $resourceName;

        $extGStates = $this->page->resources()->get('ExtGState');
        if (!$extGStates instanceof Dictionary) {
            $extGStates = new Dictionary();
            $this->page->resources()->set('ExtGState', $extGStates);
        }
        $extGStates->set($resourceName, new PdfReference($gsDict->objectId()));

        return $resourceName;
    }

    public function addTextField(
        string $name,
        float $x,
        float $y,
        float $width,
        float $height,
        ?string $value = null,
        StandardFont $font = StandardFont::Helvetica,
        float $fontSizePt = 10.0,
        ?int $maxLength = null,
        ?int $align = null,
        bool $multiline = false,
        bool $readonly = false,
    ): static {
        $resourceName = $this->formFontResourceName($font);

        $field = new TextField(
            $this->document->registry()->allocate(),
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
    ): static {
        $onAppearance = $this->buildCheckboxAppearance($size, checked: true);
        $offAppearance = $this->buildCheckboxAppearance($size, checked: false);
        $this->document->registry()->register($onAppearance);
        $this->document->registry()->register($offAppearance);

        $field = new CheckboxField(
            $this->document->registry()->allocate(),
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

    /** A simple checkmark for "on"; "off" is intentionally blank (an empty box). */
    private function buildCheckboxAppearance(float $size, bool $checked): Stream
    {
        $operators = new ContentStream();
        if ($checked) {
            $operators->setLineWidth(max(1.0, $size * 0.15))
                ->setStrokeColorRgb(0, 0, 0)
                ->moveTo($size * 0.2, $size * 0.5)
                ->lineTo($size * 0.4, $size * 0.2)
                ->lineTo($size * 0.8, $size * 0.8)
                ->stroke();
        }

        $stream = new Stream($this->document->registry()->allocate(), $operators->bytes(), compress: false);
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
    public function addRadioGroup(string $name, array $options, ?string $checkedExportValue = null): static
    {
        $group = new RadioGroupField($this->document->registry()->allocate(), $name, $checkedExportValue);
        $this->document->registry()->register($group);

        foreach ($options as $option) {
            $onAppearance = $this->buildRadioAppearance($option['size'], filled: true);
            $offAppearance = $this->buildRadioAppearance($option['size'], filled: false);
            $this->document->registry()->register($onAppearance);
            $this->document->registry()->register($offAppearance);

            $widget = new RadioButtonWidget(
                $this->document->registry()->allocate(),
                $group->objectId(),
                new PdfRectangle($option['x'], $option['y'], $option['x'] + $option['size'], $option['y'] + $option['size']),
                $option['exportValue'],
                $checkedExportValue !== null && $checkedExportValue === $option['exportValue'],
                $onAppearance,
                $offAppearance,
            );
            $this->document->registry()->register($widget);

            $group->addKid($widget->objectId());
            $this->page->addAnnotation($widget->objectId());
        }

        $this->document->acroForm()->addField($group->objectId());

        return $this;
    }

    /** A filled dot for "on"; "off" is intentionally blank, matching buildCheckboxAppearance(). */
    private function buildRadioAppearance(float $size, bool $filled): Stream
    {
        $operators = new ContentStream();
        if ($filled) {
            $cx = $size / 2;
            $cy = $size / 2;
            $radius = $size * 0.25;
            $k = $radius * 0.5523; // bezier control-point offset for a circle approximation

            $operators->setFillColorRgb(0, 0, 0)
                ->moveTo($cx + $radius, $cy)
                ->curveTo($cx + $radius, $cy + $k, $cx + $k, $cy + $radius, $cx, $cy + $radius)
                ->curveTo($cx - $k, $cy + $radius, $cx - $radius, $cy + $k, $cx - $radius, $cy)
                ->curveTo($cx - $radius, $cy - $k, $cx - $k, $cy - $radius, $cx, $cy - $radius)
                ->curveTo($cx + $k, $cy - $radius, $cx + $radius, $cy - $k, $cx + $radius, $cy)
                ->closePath()
                ->fill();
        }

        $stream = new Stream($this->document->registry()->allocate(), $operators->bytes(), compress: false);
        $stream->set('Type', new PdfName('XObject'));
        $stream->set('Subtype', new PdfName('Form'));
        $stream->set('BBox', new PdfRectangle(0, 0, $size, $size));

        return $stream;
    }

    /**
     * Owns every side effect of "add this field to this page": register
     * it with the document, list it in the page's /Annots, and list it
     * in the document's single shared AcroForm /Fields. Same discipline
     * as fontResourceName()/placeImage().
     */
    private function registerField(TextField|CheckboxField $field): void
    {
        $this->document->registry()->register($field);
        $this->page->addAnnotation($field->objectId());
        $this->document->acroForm()->addField($field->objectId());
    }

    private function fontResourceName(StandardFont $font): string
    {
        return $this->wireFontIntoResources(
            $font,
            $this->page->resources(),
            $this->fontResourceNames,
            $this->nextFontResourceNumber,
        );
    }

    private function formFontResourceName(StandardFont $font): string
    {
        return $this->wireFontIntoResources(
            $font,
            $this->document->acroForm()->defaultResources(),
            $this->formFontResourceNames,
            $this->nextFormFontResourceNumber,
        );
    }

    /** @param array<string, string> $cache */
    private function wireFontIntoResources(StandardFont $font, Dictionary $targetResources, array &$cache, int &$nextNumber): string
    {
        $key = $font->name;
        if (isset($cache[$key])) {
            return $cache[$key];
        }

        $fontDict = new Dictionary($this->document->registry()->allocate());
        $fontDict->set('Type', new PdfName('Font'));
        $fontDict->set('Subtype', new PdfName('Type1'));
        $fontDict->set('BaseFont', new PdfName($font->baseFontName()));
        if ($font->usesWinAnsiEncoding()) {
            $fontDict->set('Encoding', new PdfName('WinAnsiEncoding'));
        }
        $this->document->registry()->register($fontDict);

        $resourceName = 'F' . $nextNumber++;
        $cache[$key] = $resourceName;

        $fonts = $targetResources->get('Font');
        if (!$fonts instanceof Dictionary) {
            $fonts = new Dictionary();
            $targetResources->set('Font', $fonts);
        }
        $fonts->set($resourceName, new PdfReference($fontDict->objectId()));

        return $resourceName;
    }

    private function append(string $bytes): void
    {
        if ($this->stream === null) {
            $this->stream = new Stream($this->document->registry()->allocate(), '');
            $this->document->registry()->register($this->stream);
            $this->page->addContentStream($this->stream);
        }

        $this->stream->appendBytes($bytes);
    }
}
