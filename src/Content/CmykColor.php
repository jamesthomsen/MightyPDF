<?php

declare(strict_types=1);

namespace MightyPDF\Content;

/**
 * A colour in PDF's DeviceCMYK space: cyan, magenta, yellow and black,
 * each 0.0 to 1.0, where the numbers are ink coverage rather than light.
 *
 * This is what a print specification is written in, and converting one to
 * RGB before writing it into the file throws away the thing that was being
 * specified. Rich black is the standard example: a heading set in
 * DeviceCMYK 0.6/0.4/0.4/1.0 prints as a deep black, while the same
 * heading in 0/0/0/1.0 prints noticeably washed out next to it -- and both
 * are #000000 in RGB, so a library that only holds RGB cannot tell a
 * caller's press which one was meant.
 *
 * Out-of-range channels raise rather than clamp, matching Color.
 *
 * Note that "0.5 cyan" is not a colour in the way an RGB triple is: what
 * it looks like depends on the press, the paper and the profile in use.
 * PDF's DeviceCMYK is uncalibrated by design and this library writes it
 * through unchanged, which is what a caller specifying ink coverage
 * wants. Nothing here attempts colour management.
 */
final class CmykColor implements Paint
{
    public function __construct(
        public readonly float $c,
        public readonly float $m,
        public readonly float $y,
        public readonly float $k,
    ) {
        foreach (['cyan' => $c, 'magenta' => $m, 'yellow' => $y, 'black' => $k] as $name => $channel) {
            if ($channel < 0.0 || $channel > 1.0) {
                throw new \InvalidArgumentException(
                    "The $name channel must be between 0.0 and 1.0, got $channel. "
                    . 'For percentages use CmykColor::fromPercentages().',
                );
            }
        }
    }

    /**
     * The way a print specification actually states it: whole percentages,
     * as in "C60 M40 Y40 K100".
     */
    public static function fromPercentages(float $c, float $m, float $y, float $k): self
    {
        foreach (['cyan' => $c, 'magenta' => $m, 'yellow' => $y, 'black' => $k] as $name => $channel) {
            if ($channel < 0.0 || $channel > 100.0) {
                throw new \InvalidArgumentException("The $name channel must be between 0 and 100, got $channel.");
            }
        }

        return new self($c / 100.0, $m / 100.0, $y / 100.0, $k / 100.0);
    }

    /** Black as a press means it: one ink, fully covered. */
    public static function black(): self
    {
        return new self(0.0, 0.0, 0.0, 1.0);
    }

    /**
     * Black as a press means it for large areas: three inks under the
     * black, so the coverage does not look grey beside a photograph.
     *
     * The mix here (60/40/40/100) is the common GRACoL/SWOP-ish recipe.
     * Presses differ; a specification that names its own numbers should
     * pass them rather than take these.
     */
    public static function richBlack(): self
    {
        return new self(0.6, 0.4, 0.4, 1.0);
    }

    public static function white(): self
    {
        return new self(0.0, 0.0, 0.0, 0.0);
    }

    /**
     * @return array{float, float, float, float}
     */
    public function components(): array
    {
        return [$this->c, $this->m, $this->y, $this->k];
    }

    /**
     * The naive conversion -- (1 - ink)(1 - black) per channel -- and it
     * is only ever a preview.
     *
     * A real conversion needs the destination profile, and the answer
     * moves by a visible amount between one press and another. This is
     * here so that a CmykColor can still be handed to something that
     * takes nothing else; it is never used when the colour is being
     * written into the page, which goes out as the four numbers given.
     */
    public function toRgb(): Color
    {
        return new Color(
            (1.0 - $this->c) * (1.0 - $this->k),
            (1.0 - $this->m) * (1.0 - $this->k),
            (1.0 - $this->y) * (1.0 - $this->k),
        );
    }

    public function paintKey(): string
    {
        return "cmyk:$this->c,$this->m,$this->y,$this->k";
    }

    public function applyFill(ContentStream $operators, \Closure $nameColorSpace): void
    {
        $operators->setFillColorCmyk($this->c, $this->m, $this->y, $this->k);
    }

    public function applyStroke(ContentStream $operators, \Closure $nameColorSpace): void
    {
        $operators->setStrokeColorCmyk($this->c, $this->m, $this->y, $this->k);
    }

    public function equals(self $other): bool
    {
        return $this->c === $other->c
            && $this->m === $other->m
            && $this->y === $other->y
            && $this->k === $other->k;
    }
}
