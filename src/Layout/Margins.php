<?php

declare(strict_types=1);

namespace MightyPDF\Layout;

/**
 * The page's margins, in whatever Unit its Flow was given.
 *
 * Named constructors rather than four positional floats: "15, 20, 15,
 * 20" is unreadable and, worse, ambiguous -- CSS orders them clockwise
 * from the top, most PDF libraries take left/top/right/bottom, and
 * nothing on the call site says which one this is. uniform() and
 * symmetric() cover most documents without anyone having to know.
 */
final class Margins
{
    public function __construct(
        public readonly float $top,
        public readonly float $right,
        public readonly float $bottom,
        public readonly float $left,
    ) {
    }

    public static function uniform(float $all): self
    {
        return new self($all, $all, $all, $all);
    }

    public static function symmetric(float $vertical, float $horizontal): self
    {
        return new self($vertical, $horizontal, $vertical, $horizontal);
    }
}
