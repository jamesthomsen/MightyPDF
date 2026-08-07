<?php

declare(strict_types=1);

namespace MightyPDF\Content;

/**
 * A named ink -- a spot colour -- at some tint of itself: PDF's
 * /Separation colour space (ISO 32000-2 §8.6.6.4).
 *
 * A separation says "this area is printed with the ink called X", which is
 * a different claim from any set of process numbers. A press given one
 * mounts that ink and prints a plate for it; the colour then comes out
 * right by construction rather than by approximation, which is the entire
 * reason a brand specifies one. It is also how a varnish, a die-cut line
 * or a white underprint is marked up, none of which are colours at all.
 *
 * Every separation must carry an *alternate*: what a device with no such
 * ink should do instead. Here that is a CmykColor, because that is what a
 * spot colour's alternate is specified as in practice, and the tint
 * transform from a tint to those four numbers is written as a linear ramp
 * from no ink to the full colour. So a document with a separation in it
 * still looks right on a screen and in an office printer, and prints from
 * the correct plate on a press.
 *
 * The tint is how much of the ink is laid down, 0.0 (none, i.e. the paper)
 * to 1.0 (solid). It is an operand rather than part of the colour space,
 * which is why two tints of one ink share one resource and one plate:
 *
 * ```php
 * $brand = SpotColor::named('PANTONE 300 C', CmykColor::fromPercentages(100, 44, 0, 0));
 *
 * $content->drawRectangle($x, $y, $w, $h, fill: $brand);
 * $content->drawRectangle($x, $y2, $w, $h, fill: $brand->withTint(0.15));
 * ```
 *
 * Two reserved names mean something specific to a press and are passed
 * through unchanged: /All marks every plate at once (registration marks),
 * and /None marks none of them.
 */
final class SpotColor implements Paint
{
    private function __construct(
        public readonly string $name,
        public readonly CmykColor $alternate,
        public readonly float $tint,
    ) {
        if ($name === '') {
            throw new \InvalidArgumentException('A spot colour needs a name -- it is what identifies the plate.');
        }

        if ($tint < 0.0 || $tint > 1.0) {
            throw new \InvalidArgumentException("A tint must be between 0.0 and 1.0, got $tint.");
        }
    }

    /**
     * $name is the ink as the press knows it ("PANTONE 300 C", "Varnish",
     * "Dieline") and is written into the file verbatim -- it is what
     * separates one plate from another, so a document and its printer
     * have to agree on it exactly.
     *
     * $alternate is what to show where the ink is not available.
     */
    public static function named(string $name, CmykColor $alternate, float $tint = 1.0): self
    {
        return new self($name, $alternate, $tint);
    }

    /** The same ink at a different tint -- one plate, two densities. */
    public function withTint(float $tint): self
    {
        return new self($this->name, $this->alternate, $tint);
    }

    /**
     * The colour space's identity, which the tint is deliberately not part
     * of: /Separation names one plate, and every tint of it is painted
     * through the same one.
     */
    public function paintKey(): string
    {
        return 'separation:' . $this->name . ':' . $this->alternate->paintKey();
    }

    /**
     * The alternate at this tint, converted -- the same thing a reader
     * with no such ink will show, and as approximate as CmykColor::toRgb()
     * warns.
     */
    public function toRgb(): Color
    {
        return $this->tintedAlternate()->toRgb();
    }

    /**
     * The alternate scaled by the tint: the tint transform this colour
     * space declares, evaluated here rather than by the reader.
     *
     * A linear ramp from zero ink to the full colour, which is what the
     * /FunctionType 2 dictionary written into the file says.
     */
    public function tintedAlternate(): CmykColor
    {
        return new CmykColor(
            $this->alternate->c * $this->tint,
            $this->alternate->m * $this->tint,
            $this->alternate->y * $this->tint,
            $this->alternate->k * $this->tint,
        );
    }

    public function applyFill(ContentStream $operators, \Closure $nameColorSpace): void
    {
        $operators->setFillColorSpace($nameColorSpace($this))
            ->setFillColorComponents($this->tint);
    }

    public function applyStroke(ContentStream $operators, \Closure $nameColorSpace): void
    {
        $operators->setStrokeColorSpace($nameColorSpace($this))
            ->setStrokeColorComponents($this->tint);
    }
}
