<?php

declare(strict_types=1);

namespace MightyPDF\Content;

/**
 * The glyph a checkbox or radio button draws in its "on" appearance
 * stream. Purely visual -- a field's value and export semantics are the
 * same whichever mark it wears -- so it's one enum shared by
 * PageBuilder::addCheckbox() and addRadioGroup(), rather than each
 * button type having one glyph permanently baked into it.
 */
enum MarkStyle
{
    case Check;
    case Dot;
    case Square;

    /**
     * The ZapfDingbats character a reader should draw if it regenerates
     * this button's appearance itself -- §12.5.6.19's /MK /CA caption.
     *
     * A form that sets /NeedAppearances has asked readers to rebuild the
     * appearance of *every* widget in it, buttons included, and a reader
     * doing that throws away the vector mark draw() produced. What it
     * draws instead comes from here: without a caption it falls back to
     * its own default, so a box the caller asked to be square comes back
     * as whatever tick that reader happens to prefer.
     *
     * ZapfDingbats has an exact match for all three (a4 is the check,
     * a71 the filled circle, a110 the filled square), so a regenerated
     * appearance and a drawn one agree rather than merely coexisting.
     */
    public function captionCharacter(): string
    {
        return match ($this) {
            self::Check => '4',
            self::Dot => 'l',
            self::Square => 'n',
        };
    }

    /**
     * Draws this mark inside a $size x $size box with its origin at
     * (0, 0) -- the coordinate space of a button widget's appearance
     * stream, where the box is the widget's own /Rect.
     */
    public function draw(ContentStream $operators, float $size): void
    {
        match ($this) {
            self::Check => $this->drawCheck($operators, $size),
            self::Dot => $this->drawDot($operators, $size),
            self::Square => $this->drawSquare($operators, $size),
        };
    }

    private function drawCheck(ContentStream $operators, float $size): void
    {
        $operators->setLineWidth(max(1.0, $size * 0.15))
            ->setStrokeColorRgb(0, 0, 0)
            ->moveTo($size * 0.2, $size * 0.5)
            ->lineTo($size * 0.4, $size * 0.2)
            ->lineTo($size * 0.8, $size * 0.8)
            ->stroke();
    }

    private function drawDot(ContentStream $operators, float $size): void
    {
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

    /**
     * The whole box, edge to edge -- a marked box reads like a filled-in
     * scantron bubble, which is the point: it stays unmistakable at a
     * glance, on a photocopy, and at the small box sizes a dense form
     * uses. Deliberately not inset to leave the box's own border showing;
     * an inset square just looks like a smaller box inside a box.
     */
    private function drawSquare(ContentStream $operators, float $size): void
    {
        $operators->setFillColorRgb(0, 0, 0)
            ->rect(0, 0, $size, $size)
            ->fill();
    }
}
