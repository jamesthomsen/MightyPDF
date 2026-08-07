<?php

declare(strict_types=1);

namespace MightyPDF\Content;

/**
 * A dash pattern: alternating on and off lengths in points, and how far
 * into that cycle the line begins.
 *
 * PDF states this as a bare array whose meaning depends on its length --
 * one number is on and off equally, two are on then off, four are a
 * dash-dot, and an empty one is solid. That is compact and easy to write
 * backwards, so the named constructors here cover what documents actually
 * ask for and the constructor stays available for the rest.
 *
 * Lengths are in points, like every other rule weight in this library, so
 * a dash means the same thing in a millimetre Flow and an inch one.
 */
final class Dash
{
    /**
     * @param list<float> $pattern alternating on/off lengths; empty is a
     *        solid line
     */
    public function __construct(
        public readonly array $pattern,
        public readonly float $phase = 0.0,
    ) {
        foreach ($pattern as $length) {
            if ($length < 0.0) {
                throw new \InvalidArgumentException("A dash length cannot be negative, got $length.");
            }
        }

        // Every length zero is not a dash pattern but an invisible line:
        // readers differ on whether they draw nothing or draw it solid,
        // which is a difference nobody notices until the wrong one ships.
        if ($pattern !== [] && array_sum($pattern) <= 0.0) {
            throw new \InvalidArgumentException(
                'A dash pattern of all zeros is not a pattern -- pass an empty array for a solid line.',
            );
        }
    }

    public static function solid(): self
    {
        return new self([]);
    }

    /** Equal on and off, $lengthPt each. */
    public static function dashed(float $lengthPt = 3.0): self
    {
        return new self([$lengthPt]);
    }

    /**
     * Dots rather than dashes: zero-length "on" segments, which show up
     * as round dots only under LineCap::Round -- with the default butt
     * cap they have no area and nothing is drawn at all. Stroke::dotted()
     * sets both together, and is what a caller should normally reach for.
     */
    public static function dotted(float $spacingPt = 2.0): self
    {
        return new self([0.0, $spacingPt]);
    }

    /** The long-short-long of a centreline or a fold mark. */
    public static function dashDot(float $dashPt = 6.0, float $gapPt = 2.0): self
    {
        return new self([$dashPt, $gapPt, 0.0, $gapPt]);
    }

    public function isSolid(): bool
    {
        return $this->pattern === [];
    }
}
