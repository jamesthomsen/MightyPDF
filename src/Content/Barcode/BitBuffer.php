<?php

declare(strict_types=1);

namespace MightyPDF\Content\Barcode;

use MightyPDF\Exception\InvalidArgumentException;
use MightyPDF\Exception\LogicException;

/**
 * A growing string of bits, which is what a QR code's data segment is
 * before it is cut into codewords.
 *
 * Everything a QR symbol carries is stated in bits at widths that are not
 * multiples of eight -- a mode indicator is four, a character count nine
 * to sixteen, three digits ten. Assembling that as bytes by hand is a
 * mask-and-shift at every append, which is where an encoder written
 * without one of these goes wrong.
 */
final class BitBuffer
{
    /** @var list<int> one entry per bit, 0 or 1 */
    private array $bits = [];

    /**
     * Appends the low $width bits of $value, most significant first.
     */
    public function append(int $value, int $width): void
    {
        if ($width < 0 || $width > 63) {
            throw new InvalidArgumentException("A field is between 0 and 63 bits wide, got $width.");
        }

        if ($width < 63 && ($value < 0 || $value >= 1 << $width)) {
            throw new InvalidArgumentException("$value does not fit in $width bits.");
        }

        for ($i = $width - 1; $i >= 0; --$i) {
            $this->bits[] = ($value >> $i) & 1;
        }
    }

    public function length(): int
    {
        return count($this->bits);
    }

    /**
     * The bits as bytes, most significant first.
     *
     * The length must already be a multiple of eight: a QR encoder pads
     * to a byte boundary deliberately and at a specific point, so
     * padding silently here would hide that step going missing.
     *
     * @return list<int>
     */
    public function toBytes(): array
    {
        if ($this->length() % 8 !== 0) {
            throw new LogicException(
                'This buffer is ' . $this->length() . ' bits long, which is not a whole number of bytes.',
            );
        }

        $bytes = [];

        foreach (array_chunk($this->bits, 8) as $chunk) {
            $byte = 0;

            foreach ($chunk as $bit) {
                $byte = ($byte << 1) | $bit;
            }

            $bytes[] = $byte;
        }

        return $bytes;
    }
}
