<?php

declare(strict_types=1);

namespace MightyPDF\Content;

use MightyPDF\Assembler\Dictionary;
use MightyPDF\Assembler\Document;
use MightyPDF\Assembler\Form\CheckboxField;
use MightyPDF\Assembler\Form\TextField;
use MightyPDF\Assembler\Page;
use MightyPDF\Assembler\Stream;
use MightyPDF\Assembler\Types\PdfName;
use MightyPDF\Assembler\Types\PdfReference;
use MightyPDF\Assembler\Types\PdfRectangle;
use MightyPDF\Assembler\Types\WinAnsiEncoding;
use MightyPDF\Content\Font\StandardFont;
use MightyPDF\Content\Image\GifImage;
use MightyPDF\Content\Image\JpegImage;
use MightyPDF\Content\Image\PngImage;

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

    public function __construct(
        private readonly Document $document,
        private readonly Page $page,
    ) {
    }

    public function drawText(StandardFont $font, float $sizePt, float $x, float $y, string $text): static
    {
        $resourceName = $this->fontResourceName($font);
        $encoded = WinAnsiEncoding::encode($text);

        $operators = new ContentStream();
        $operators->beginText()
            ->setFont($resourceName, $sizePt)
            ->showTextAt($x, $y, $encoded)
            ->endText();

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
        return $this->placeImage(JpegImage::fromFile($this->document->registry()->allocate(), $path), $x, $y, $width, $height);
    }

    public function drawPng(string $path, float $x, float $y, float $width, float $height): static
    {
        return $this->placeImage(PngImage::fromFile($this->document->registry()->allocate(), $path), $x, $y, $width, $height);
    }

    public function drawGif(string $path, float $x, float $y, float $width, float $height): static
    {
        return $this->placeImage(GifImage::fromFile($this->document->registry()->allocate(), $path), $x, $y, $width, $height);
    }

    /**
     * Owns every side effect of "place this already-built image XObject
     * on this page": registering it with the document, wiring it into
     * /Resources /XObject, and appending the placement operators. Same
     * "one method, every side effect" discipline as fontResourceName().
     */
    private function placeImage(Stream $image, float $x, float $y, float $width, float $height): static
    {
        $this->document->registry()->register($image);

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
        );

        $this->registerField($field);

        return $this;
    }

    public function addCheckbox(string $name, float $x, float $y, float $size, bool $checked = false): static
    {
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
