<?php

declare(strict_types=1);

namespace MightyPDF\Editor\Form;

use MightyPDF\Assembler\Dictionary;
use MightyPDF\Assembler\Stream;
use MightyPDF\Assembler\Types\PdfArray;
use MightyPDF\Assembler\Types\PdfInteger;
use MightyPDF\Assembler\Types\PdfName;
use MightyPDF\Assembler\Types\PdfNumberFormat;
use MightyPDF\Assembler\Types\PdfReal;
use MightyPDF\Assembler\Types\PdfRectangle;
use MightyPDF\Assembler\Types\PdfReference;
use MightyPDF\Assembler\Types\PdfString;
use MightyPDF\Assembler\Types\PdfValue;
use MightyPDF\Assembler\Types\WinAnsiEncoding;
use MightyPDF\Content\Font\FontMetrics;
use MightyPDF\Content\Font\StandardFont;
use MightyPDF\Content\Text\TextWrapper;
use MightyPDF\Editor\PdfEditor;

/**
 * Draws a filled text field's value into an appearance stream, so that it
 * is visible without the reader being asked to redraw it.
 *
 * /NeedsAppearances is the easy answer and not a complete one. It asks
 * the reader to build these streams itself, which Acrobat, poppler and
 * Ghostscript all do -- but a reader that ignores the flag shows an empty
 * box where a filled-in value ought to be, and the flag also means the
 * document arrives announcing that it is not finished. Producing the
 * stream here makes a filled form look filled in everywhere, and leaves
 * readers that *do* honour the flag free to redraw it anyway.
 *
 * What it deliberately does not do is change the value. /V keeps the text
 * exactly as given, in full Unicode; this draws an approximation of it
 * using whatever font the form specified, transliterating what that font
 * cannot represent. The stored data stays right even where the picture of
 * it cannot be.
 */
final class TextAppearanceBuilder
{
    /** Acrobat's conventional inset between a field's border and its text. */
    private const float PADDING = 2.0;

    /**
     * Fraction of the font size taken up by capitals, near enough for
     * every standard font. Used to centre a single line vertically, which
     * looks right when done on the capitals rather than on the full em box
     * -- descenders would otherwise push the text visibly high.
     */
    private const float CAP_HEIGHT_RATIO = 0.72;

    private const float LINE_SPACING = 1.15;

    public function __construct(private readonly PdfEditor $editor)
    {
    }

    /**
     * @return ?Stream null when the form does not say enough to draw
     *         with -- no /DA, or a font that cannot be measured. The
     *         caller falls back to /NeedsAppearances.
     */
    public function build(Field $field, Dictionary $widget, string $text): ?Stream
    {
        $rectangle = $this->rectangleOf($widget);

        if ($rectangle === null) {
            return null;
        }

        [$width, $height] = $rectangle;

        $appearance = DefaultAppearance::parse($this->defaultAppearanceString($field, $widget));

        if ($appearance->fontResourceName === null) {
            return null;
        }

        $font = $this->resourceFont($appearance->fontResourceName);

        if ($font === null) {
            return null;
        }

        [$fontObject, $metrics] = $font;
        $encoded = WinAnsiEncoding::encode($text);

        $multiline = $field->isMultiline();
        $comb = $this->combCells($field);

        $appearance = $appearance->isAutoSized()
            ? $appearance->withSize($this->autoSize($encoded, $metrics, $width, $height, $multiline, $comb))
            : $appearance;

        $body = $multiline
            ? $this->multilineBody($text, $encoded, $appearance, $metrics, $width, $height)
            : ($comb !== null
                ? $this->combBody($encoded, $appearance, $metrics, $width, $height, $comb)
                : $this->singleLineBody($encoded, $appearance, $metrics, $field, $width, $height));

        return $this->wrap($body, $appearance->fontResourceName, $fontObject, $width, $height);
    }

    /**
     * The marked-content and clipping wrapper every text field appearance
     * has.
     *
     * "/Tx BMC ... EMC" marks the run as field text, which is how a reader
     * recognises the part it may replace when it regenerates. The clip is
     * what keeps a value longer than its box from spilling across the page
     * rather than being cut off at the edge, as every reader draws it.
     */
    private function wrap(string $body, string $fontResourceName, PdfValue $fontObject, float $width, float $height): Stream
    {
        $content = sprintf(
            "/Tx BMC\nq\n%s %s %s %s re\nW\nn\n%sQ\nEMC\n",
            PdfNumberFormat::format(0.0),
            PdfNumberFormat::format(0.0),
            PdfNumberFormat::format($width),
            PdfNumberFormat::format($height),
            $body,
        );

        $stream = new Stream($this->editor->allocate(), $content);

        $stream->set('Type', new PdfName('XObject'));
        $stream->set('Subtype', new PdfName('Form'));
        $stream->set('BBox', new PdfRectangle(0, 0, $width, $height));
        $stream->set('Resources', (new Dictionary())->set(
            'Font',
            (new Dictionary())->set($fontResourceName, $fontObject),
        ));

        $this->editor->register($stream);

        return $stream;
    }

    private function singleLineBody(
        string $encoded,
        DefaultAppearance $appearance,
        FontMetrics $metrics,
        Field $field,
        float $width,
        float $height,
    ): string {
        $textWidth = $metrics->widthOf($encoded, $appearance->fontSizePt);
        $x = $this->alignedX($field, $textWidth, $width);
        $y = ($height - $appearance->fontSizePt * self::CAP_HEIGHT_RATIO) / 2.0;

        return $this->textBlock($appearance, [[$x, $y, $encoded]]);
    }

    private function multilineBody(
        string $text,
        string $encoded,
        DefaultAppearance $appearance,
        FontMetrics $metrics,
        float $width,
        float $height,
    ): string {
        $lines = TextWrapper::wrap($text, $metrics, $appearance->fontSizePt, max(1.0, $width - self::PADDING * 2));
        $leading = $appearance->fontSizePt * self::LINE_SPACING;

        // Multiline text starts at the top and runs down, unlike a single
        // line, which is centred.
        $y = $height - self::PADDING - $appearance->fontSizePt * self::CAP_HEIGHT_RATIO;
        $runs = [];

        foreach ($lines as $line) {
            $runs[] = [self::PADDING, $y, WinAnsiEncoding::encode($line)];
            $y -= $leading;
        }

        return $this->textBlock($appearance, $runs);
    }

    /**
     * A comb field divides its box into /MaxLen equal cells and puts one
     * character in the middle of each -- the boxed-per-character look of a
     * postcode or account number on a printed form. Drawn as ordinary
     * running text it lines up with none of the printed boxes.
     */
    private function combBody(
        string $encoded,
        DefaultAppearance $appearance,
        FontMetrics $metrics,
        float $width,
        float $height,
        int $cells,
    ): string {
        $cellWidth = $width / $cells;
        $y = ($height - $appearance->fontSizePt * self::CAP_HEIGHT_RATIO) / 2.0;
        $runs = [];

        for ($i = 0, $length = min(strlen($encoded), $cells); $i < $length; ++$i) {
            $character = $encoded[$i];
            $characterWidth = $metrics->widthOf($character, $appearance->fontSizePt);

            $runs[] = [$cellWidth * $i + ($cellWidth - $characterWidth) / 2.0, $y, $character];
        }

        return $this->textBlock($appearance, $runs);
    }

    /**
     * @param list<array{0: float, 1: float, 2: string}> $runs x, y, WinAnsi bytes
     */
    private function textBlock(DefaultAppearance $appearance, array $runs): string
    {
        if ($runs === []) {
            return '';
        }

        // The /DA operators are replayed verbatim, so whatever colour or
        // spacing the form's author set survives untouched.
        $out = "BT\n" . rtrim($appearance->operators) . "\n";

        foreach ($runs as [$x, $y, $bytes]) {
            $out .= sprintf(
                "1 0 0 1 %s %s Tm\n%s Tj\n",
                PdfNumberFormat::format($x),
                PdfNumberFormat::format($y),
                PdfString::latin1($bytes)->format(),
            );
        }

        return $out . "ET\n";
    }

    private function alignedX(Field $field, float $textWidth, float $width): float
    {
        $quadding = $this->editor->resolve($field->dictionary->get('Q'));
        $quadding = $quadding instanceof PdfInteger ? $quadding->value() : 0;

        return match ($quadding) {
            1 => max(self::PADDING, ($width - $textWidth) / 2.0),
            2 => max(self::PADDING, $width - $textWidth - self::PADDING),
            default => self::PADDING,
        };
    }

    /**
     * Picks a size for a field whose /DA asks for 0, meaning "fit it".
     *
     * Bounded by the box in both directions: tall enough text overflows
     * vertically just as surely as wide text overflows horizontally, and
     * a reader clips both.
     */
    private function autoSize(
        string $encoded,
        FontMetrics $metrics,
        float $width,
        float $height,
        bool $multiline,
        ?int $comb,
    ): float {
        $byHeight = $multiline
            // Auto-sized multiline text is conventionally left small
            // enough for several lines rather than fitted to one.
            ? 12.0
            : max(4.0, ($height - self::PADDING) / (self::CAP_HEIGHT_RATIO + 0.35));

        if ($encoded === '' || $multiline) {
            return min(12.0, $byHeight);
        }

        if ($comb !== null) {
            return min($byHeight, max(4.0, $width / $comb * 0.8));
        }

        $widthAtOnePoint = $metrics->widthOf($encoded, 1.0);

        $byWidth = $widthAtOnePoint > 0.0
            ? ($width - self::PADDING * 2) / $widthAtOnePoint
            : $byHeight;

        return max(4.0, min($byHeight, $byWidth));
    }

    /** Table 228 bit 25, /Comb -- only meaningful alongside /MaxLen. */
    private function combCells(Field $field): ?int
    {
        if (($field->flags & (1 << 24)) === 0 || $field->isMultiline()) {
            return null;
        }

        return $field->maxLength !== null && $field->maxLength > 0 ? $field->maxLength : null;
    }

    /**
     * /DA is inheritable, and a widget may carry its own. Falling back to
     * the AcroForm's is what makes a form whose fields all share one
     * appearance work at all.
     */
    private function defaultAppearanceString(Field $field, Dictionary $widget): ?string
    {
        foreach ([$widget->get('DA'), $field->dictionary->get('DA'), $this->acroForm()?->get('DA')] as $candidate) {
            $candidate = $this->editor->resolve($candidate);

            if ($candidate instanceof PdfString) {
                return $candidate->bytes();
            }
        }

        return null;
    }

    /**
     * The font a /DA name refers to, from the AcroForm's /DR, together
     * with something that can measure text in it.
     *
     * @return ?array{0: PdfValue, 1: FontMetrics}
     */
    private function resourceFont(string $resourceName): ?array
    {
        $resources = $this->editor->resolveDictionary($this->acroForm()?->get('DR'));
        $fonts = $this->editor->resolveDictionary($resources?->get('Font'));
        $reference = $fonts?->get($resourceName);

        if ($reference === null) {
            return null;
        }

        $font = $this->editor->resolveDictionary($reference);

        if ($font === null) {
            return null;
        }

        $metrics = $this->metricsFor($font);

        return $metrics === null ? null : [$reference, $metrics];
    }

    /**
     * Widths for a font dictionary.
     *
     * A form's font is usually one of the standard 14, for which the
     * widths are built in. Otherwise they have to come from the font's own
     * /Widths array -- and if there is neither, there is no way to know
     * how wide the text will be, so nothing is drawn rather than something
     * misaligned.
     */
    private function metricsFor(Dictionary $font): ?FontMetrics
    {
        $baseFont = $this->editor->resolve($font->get('BaseFont'));

        if ($baseFont instanceof PdfName) {
            $standard = self::standardFontNamed($baseFont->value());

            if ($standard !== null) {
                return $standard->metrics();
            }
        }

        $widths = $this->editor->resolve($font->get('Widths'));
        $firstChar = $this->editor->resolve($font->get('FirstChar'));

        if (!$widths instanceof PdfArray || !$firstChar instanceof PdfInteger) {
            return null;
        }

        $table = [];
        $code = $firstChar->value();

        foreach ($widths->items() as $entry) {
            $entry = $this->editor->resolve($entry);

            if ($entry instanceof PdfInteger) {
                $table[$code] = $entry->value();
            } elseif ($entry instanceof PdfReal) {
                $table[$code] = (int) round($entry->value());
            }

            ++$code;
        }

        return $table === [] ? null : new FontMetrics($table);
    }

    /**
     * Matches a /BaseFont against the standard 14.
     *
     * Subset prefixes ("ABCDEF+Helvetica") and the short aliases forms use
     * for them ("Helv", "TiRo", "Cour") both have to be recognised -- /Helv
     * is what Acrobat itself writes into a form's /DR.
     */
    private static function standardFontNamed(string $baseFont): ?StandardFont
    {
        if (preg_match('/^[A-Z]{6}\+/', $baseFont) === 1) {
            $baseFont = substr($baseFont, 7);
        }

        $aliases = [
            'Helv' => StandardFont::Helvetica,
            'HeBo' => StandardFont::HelveticaBold,
            'TiRo' => StandardFont::TimesRoman,
            'Cour' => StandardFont::Courier,
            'Symb' => StandardFont::Symbol,
            'ZaDb' => StandardFont::ZapfDingbats,
        ];

        if (isset($aliases[$baseFont])) {
            return $aliases[$baseFont];
        }

        foreach (StandardFont::cases() as $case) {
            if ($case->baseFontName() === $baseFont) {
                return $case;
            }
        }

        return null;
    }

    /** @return ?array{0: float, 1: float} width and height */
    private function rectangleOf(Dictionary $widget): ?array
    {
        $rectangle = $this->editor->resolve($widget->get('Rect'));

        if (!$rectangle instanceof PdfArray || count($rectangle->items()) < 4) {
            return null;
        }

        $numbers = [];

        foreach ($rectangle->items() as $item) {
            $item = $this->editor->resolve($item);

            $numbers[] = match (true) {
                $item instanceof PdfInteger => (float) $item->value(),
                $item instanceof PdfReal => $item->value(),
                default => 0.0,
            };
        }

        // A /Rect is two opposite corners in either order, not an origin
        // and a size.
        $width = abs($numbers[2] - $numbers[0]);
        $height = abs($numbers[3] - $numbers[1]);

        return $width > 0.0 && $height > 0.0 ? [$width, $height] : null;
    }

    private function acroForm(): ?Dictionary
    {
        return $this->editor->resolveDictionary($this->editor->catalog()->get('AcroForm'));
    }
}
