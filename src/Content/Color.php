<?php

declare(strict_types=1);

namespace MightyPDF\Content;

/**
 * An RGB colour, held the way PDF wants it -- three channels from 0.0 to
 * 1.0 -- and constructed the way everything else states it.
 *
 * That gap is the reason this exists. PDF content streams take floats,
 * while every design token, brand guide, CSS value and colour picker in
 * the world says 0-255 or a hex triplet, so without this every call site
 * divides by 255 by hand. Dividing by 255 is not hard; doing it in forty
 * places, one of which says 256, is how a brand colour ends up a shade
 * off in one corner of one document.
 *
 * Out-of-range channels raise rather than clamp, on the same reasoning
 * as SvgColor: a caller passing 300 has a bug, and silently drawing it
 * as 255 hides which of the two numbers was wrong.
 *
 * One of three Paints, and the one everything defaults to. See CmykColor
 * for ink coverage and SpotColor for a named plate.
 */
final class Color implements Paint
{
    public function __construct(
        public readonly float $r,
        public readonly float $g,
        public readonly float $b,
    ) {
        foreach (['red' => $r, 'green' => $g, 'blue' => $b] as $name => $channel) {
            if ($channel < 0.0 || $channel > 1.0) {
                throw new \InvalidArgumentException(
                    "The $name channel must be between 0.0 and 1.0, got $channel. "
                    . 'For 0-255 values use Color::fromRgb255().',
                );
            }
        }
    }

    public static function fromRgb255(int $r, int $g, int $b): self
    {
        foreach (['red' => $r, 'green' => $g, 'blue' => $b] as $name => $channel) {
            if ($channel < 0 || $channel > 255) {
                throw new \InvalidArgumentException("The $name channel must be between 0 and 255, got $channel.");
            }
        }

        return new self($r / 255.0, $g / 255.0, $b / 255.0);
    }

    /**
     * Accepts what people actually paste: "#1a2b3c", "1a2b3c", and the
     * three-digit shorthand "#abc", which expands the way CSS does (each
     * digit doubled, so "#abc" is "#aabbcc" and not "#0a0b0c").
     */
    public static function fromHex(string $hex): self
    {
        $digits = ltrim(trim($hex), '#');

        if (preg_match('/^[0-9a-fA-F]{3}$/', $digits) === 1) {
            $digits = $digits[0] . $digits[0] . $digits[1] . $digits[1] . $digits[2] . $digits[2];
        }

        if (preg_match('/^[0-9a-fA-F]{6}$/', $digits) !== 1) {
            throw new \InvalidArgumentException(
                "\"$hex\" is not a hex colour -- expected three or six hex digits, optionally led by '#'.",
            );
        }

        return self::fromRgb255(
            (int) hexdec(substr($digits, 0, 2)),
            (int) hexdec(substr($digits, 2, 2)),
            (int) hexdec(substr($digits, 4, 2)),
        );
    }

    /** $level from 0.0 (black) to 1.0 (white). */
    public static function gray(float $level): self
    {
        return new self($level, $level, $level);
    }

    public static function black(): self
    {
        return new self(0.0, 0.0, 0.0);
    }

    public static function white(): self
    {
        return new self(1.0, 1.0, 1.0);
    }

    /**
     * The three channels as a list, for spreading into the drawing
     * primitives that take them positionally:
     *
     * ```php
     * $content->fillRectangle($x, $y, $w, $h, ...$color->rgb());
     * ```
     *
     * Those signatures keep their floats rather than gaining a Color
     * overload apiece -- PageBuilder has a dozen of them, and a spread
     * at the call site costs less than doubling its surface.
     *
     * @return array{float, float, float}
     */
    public function rgb(): array
    {
        return [$this->r, $this->g, $this->b];
    }

    /** Already RGB, so this is the identity -- see Paint::toRgb(). */
    public function toRgb(): self
    {
        return $this;
    }

    public function paintKey(): string
    {
        return "rgb:$this->r,$this->g,$this->b";
    }

    public function applyFill(ContentStream $operators, \Closure $nameColorSpace): void
    {
        $operators->setFillColorRgb($this->r, $this->g, $this->b);
    }

    public function applyStroke(ContentStream $operators, \Closure $nameColorSpace): void
    {
        $operators->setStrokeColorRgb($this->r, $this->g, $this->b);
    }

    /** Lowercase, with the leading '#', e.g. "#1a2b3c". */
    public function toHex(): string
    {
        return sprintf(
            '#%02x%02x%02x',
            (int) round($this->r * 255.0),
            (int) round($this->g * 255.0),
            (int) round($this->b * 255.0),
        );
    }

    public function equals(self $other): bool
    {
        return $this->r === $other->r && $this->g === $other->g && $this->b === $other->b;
    }
}
