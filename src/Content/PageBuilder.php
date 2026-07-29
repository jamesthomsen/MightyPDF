<?php

declare(strict_types=1);

namespace MightyPDF\Content;

use MightyPDF\Assembler\Dictionary;
use MightyPDF\Assembler\Document;
use MightyPDF\Assembler\Page;
use MightyPDF\Assembler\Stream;
use MightyPDF\Assembler\Types\PdfName;
use MightyPDF\Assembler\Types\PdfReference;
use MightyPDF\Content\Font\StandardFont;

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

    /** @var array<string, string> StandardFont case name => resource name (e.g. "F1") */
    private array $fontResourceNames = [];
    private int $nextFontResourceNumber = 1;

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

    private function fontResourceName(StandardFont $font): string
    {
        $key = $font->name;
        if (isset($this->fontResourceNames[$key])) {
            return $this->fontResourceNames[$key];
        }

        $fontDict = new Dictionary($this->document->registry()->allocate());
        $fontDict->set('Type', new PdfName('Font'));
        $fontDict->set('Subtype', new PdfName('Type1'));
        $fontDict->set('BaseFont', new PdfName($font->baseFontName()));
        if ($font->usesWinAnsiEncoding()) {
            $fontDict->set('Encoding', new PdfName('WinAnsiEncoding'));
        }
        $this->document->registry()->register($fontDict);

        $resourceName = 'F' . $this->nextFontResourceNumber++;
        $this->fontResourceNames[$key] = $resourceName;

        $fonts = $this->page->resources()->get('Font');
        if (!$fonts instanceof Dictionary) {
            $fonts = new Dictionary();
            $this->page->resources()->set('Font', $fonts);
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
