<?php

declare(strict_types=1);

namespace MightyPDF\Content\Barcode;

/**
 * The grid of a QR symbol under construction: which modules are dark, and
 * which of them are function patterns rather than data.
 *
 * Kept apart from QrCode because the two do different things. QrCode
 * turns a string into codewords -- modes, capacities, error correction.
 * This turns codewords into a picture -- finder squares, timing lines,
 * the zigzag the data snakes through, and the mask. Neither half needs to
 * see inside the other, and the second is where every off-by-one in a QR
 * implementation lives.
 *
 * The function-pattern flags are the load-bearing part. A module that
 * belongs to a finder square, a timing line or the format information is
 * not data, must not be written over by the zigzag, and must not be
 * flipped by the mask -- and there is no way to tell from the module
 * itself, since a dark function module and a dark data module look
 * identical. So they are tracked as they are drawn.
 */
final class QrMatrix
{
    private readonly int $size;

    /** @var list<list<bool>> [y][x], true where dark */
    private array $modules;

    /** @var list<list<bool>> [y][x], true where the module is a function pattern */
    private array $isFunction;

    public function __construct(private readonly int $version)
    {
        if ($version < 1 || $version > 40) {
            throw new \InvalidArgumentException("A QR version runs from 1 to 40, got $version.");
        }

        $this->size = $version * 4 + 17;

        $row = array_fill(0, $this->size, false);
        $this->modules = array_fill(0, $this->size, $row);
        $this->isFunction = array_fill(0, $this->size, $row);
    }

    public function size(): int
    {
        return $this->size;
    }

    /** @return list<list<bool>> */
    public function modules(): array
    {
        return $this->modules;
    }

    /**
     * Everything that is not data: the three finder squares and their
     * separators, the timing lines, the alignment patterns, and the
     * format and version information.
     *
     * The format information depends on the mask, which is why this is
     * redrawn for each of the eight rather than drawn once.
     */
    public function drawFunctionPatterns(QrEccLevel $level, int $mask): void
    {
        // Timing first: the alternating lines the finder patterns and
        // alignment patterns then overwrite where they cross.
        for ($i = 0; $i < $this->size; ++$i) {
            $this->setFunction(6, $i, $i % 2 === 0);
            $this->setFunction($i, 6, $i % 2 === 0);
        }

        // Three of them, not four: the missing corner is what tells a
        // scanner which way up the symbol is.
        $this->drawFinder(3, 3);
        $this->drawFinder($this->size - 4, 3);
        $this->drawFinder(3, $this->size - 4);

        $this->drawAlignmentPatterns();
        $this->drawFormatBits($level, $mask);
        $this->drawVersionBits();
    }

    /**
     * Snakes the codewords through every module the function patterns
     * left alone.
     *
     * Two modules wide, upwards then downwards, right to left -- with a
     * jump over the vertical timing line at column 6, which would
     * otherwise make one of the pairs one module wide.
     *
     * @param list<int> $codewords
     */
    public function drawCodewords(array $codewords): void
    {
        $bit = 0;
        $available = count($codewords) * 8;

        for ($right = $this->size - 1; $right >= 1; $right -= 2) {
            if ($right === 6) {
                $right = 5;
            }

            for ($vertical = 0; $vertical < $this->size; ++$vertical) {
                for ($column = 0; $column < 2; ++$column) {
                    $x = $right - $column;
                    $upward = (($right + 1) & 2) === 0;
                    $y = $upward ? $this->size - 1 - $vertical : $vertical;

                    if ($this->isFunction[$y][$x] || $bit >= $available) {
                        continue;
                    }

                    $this->modules[$y][$x] = (($codewords[$bit >> 3] >> (7 - ($bit & 7))) & 1) !== 0;
                    ++$bit;
                }
            }
        }
    }

    /**
     * Flips the data modules the mask selects, leaving function patterns
     * alone.
     *
     * Self-inverse, which is how a scanner undoes it: applying the same
     * mask twice gets the original back.
     */
    public function applyMask(int $mask): void
    {
        if ($mask < 0 || $mask > 7) {
            throw new \InvalidArgumentException("A QR mask is 0 to 7, got $mask.");
        }

        for ($y = 0; $y < $this->size; ++$y) {
            for ($x = 0; $x < $this->size; ++$x) {
                if ($this->isFunction[$y][$x]) {
                    continue;
                }

                $invert = match ($mask) {
                    0 => ($x + $y) % 2 === 0,
                    1 => $y % 2 === 0,
                    2 => $x % 3 === 0,
                    3 => ($x + $y) % 3 === 0,
                    4 => (intdiv($x, 3) + intdiv($y, 2)) % 2 === 0,
                    5 => $x * $y % 2 + $x * $y % 3 === 0,
                    6 => ($x * $y % 2 + $x * $y % 3) % 2 === 0,
                    default => (($x + $y) % 2 + $x * $y % 3) % 2 === 0,
                };

                $this->modules[$y][$x] = $this->modules[$y][$x] !== $invert;
            }
        }
    }

    /**
     * How bad this masked symbol looks to a scanner, by the standard's
     * four rules: long runs of one colour, solid 2x2 blocks, anything
     * resembling a finder pattern, and an unbalanced ratio of dark to
     * light overall.
     *
     * Lower is better. The absolute number means nothing; only the
     * comparison between the eight masks does.
     */
    public function penalty(): int
    {
        $result = 0;

        $result += $this->linePenalty(fn (int $a, int $b): bool => $this->modules[$a][$b]);
        $result += $this->linePenalty(fn (int $a, int $b): bool => $this->modules[$b][$a]);

        // Rule 2: every 2x2 block of one colour, which is what makes a
        // symbol hard to align to.
        for ($y = 0; $y < $this->size - 1; ++$y) {
            for ($x = 0; $x < $this->size - 1; ++$x) {
                $colour = $this->modules[$y][$x];

                if ($colour === $this->modules[$y][$x + 1]
                    && $colour === $this->modules[$y + 1][$x]
                    && $colour === $this->modules[$y + 1][$x + 1]
                ) {
                    $result += 3;
                }
            }
        }

        // Rule 4: dark modules should be near half. The penalty steps up
        // for every 5% away from that.
        $dark = 0;

        foreach ($this->modules as $row) {
            foreach ($row as $module) {
                $dark += $module ? 1 : 0;
            }
        }

        $total = $this->size * $this->size;
        $steps = intdiv(abs($dark * 20 - $total * 10) + $total - 1, $total) - 1;

        return $result + $steps * 10;
    }

    /**
     * Rules 1 and 3 along one axis: runs of five or more, and anything
     * with a finder pattern's 1:1:3:1:1 proportions in it.
     *
     * Taken as a closure over the two indices so the row pass and the
     * column pass are one implementation -- they are the same scan and
     * the standard states them as one rule applied twice.
     *
     * @param \Closure(int, int): bool $at
     */
    private function linePenalty(\Closure $at): int
    {
        $result = 0;

        for ($line = 0; $line < $this->size; ++$line) {
            $runColour = false;
            $runLength = 0;
            $history = array_fill(0, 7, 0);

            for ($i = 0; $i < $this->size; ++$i) {
                if ($at($line, $i) === $runColour) {
                    ++$runLength;

                    if ($runLength === 5) {
                        $result += 3;
                    } elseif ($runLength > 5) {
                        ++$result;
                    }

                    continue;
                }

                $this->pushRun($runLength, $history);

                if (!$runColour) {
                    $result += $this->finderLikeRuns($history) * 40;
                }

                $runColour = $at($line, $i);
                $runLength = 1;
            }

            $result += $this->terminateRun($runColour, $runLength, $history) * 40;
        }

        return $result;
    }

    /**
     * @param list<int> $history
     */
    private function pushRun(int $runLength, array &$history): void
    {
        if ($history[0] === 0) {
            // The quiet zone counts as light: a finder pattern at the
            // very edge of the symbol has the border on one side of it.
            $runLength += $this->size;
        }

        array_pop($history);
        array_unshift($history, $runLength);
    }

    /**
     * @param list<int> $history
     */
    private function terminateRun(bool $runColour, int $runLength, array $history): int
    {
        if ($runColour) {
            $this->pushRun($runLength, $history);
            $runLength = 0;
        }

        $this->pushRun($runLength + $this->size, $history);

        return $this->finderLikeRuns($history);
    }

    /**
     * How many of the last seven runs form a finder pattern's
     * 1:1:3:1:1 ratio with four modules of light on one side -- which is
     * the thing a scanner mistakes for the real finder squares.
     *
     * @param list<int> $history
     */
    private function finderLikeRuns(array $history): int
    {
        $unit = $history[1];

        $core = $unit > 0
            && $history[2] === $unit
            && $history[3] === $unit * 3
            && $history[4] === $unit
            && $history[5] === $unit;

        return ($core && $history[0] >= $unit * 4 && $history[6] >= $unit ? 1 : 0)
            + ($core && $history[6] >= $unit * 4 && $history[0] >= $unit ? 1 : 0);
    }

    /** A finder square and its separator, centred on ($x, $y). */
    private function drawFinder(int $x, int $y): void
    {
        for ($dy = -4; $dy <= 4; ++$dy) {
            for ($dx = -4; $dx <= 4; ++$dx) {
                // Chebyshev distance, so the rings are squares: dark at
                // 0, 1 and 3, light at 2 and 4 -- the second of which is
                // the separator that isolates the square from the data.
                $distance = max(abs($dx), abs($dy));

                if ($this->contains($x + $dx, $y + $dy)) {
                    $this->setFunction($x + $dx, $y + $dy, $distance !== 2 && $distance !== 4);
                }
            }
        }
    }

    private function drawAlignmentPatterns(): void
    {
        $positions = self::alignmentPositions($this->version);
        $last = count($positions) - 1;

        foreach ($positions as $i => $y) {
            foreach ($positions as $j => $x) {
                // The three corners already hold finder patterns.
                if (($i === 0 && $j === 0) || ($i === 0 && $j === $last) || ($i === $last && $j === 0)) {
                    continue;
                }

                for ($dy = -2; $dy <= 2; ++$dy) {
                    for ($dx = -2; $dx <= 2; ++$dx) {
                        $this->setFunction($x + $dx, $y + $dy, max(abs($dx), abs($dy)) !== 1);
                    }
                }
            }
        }
    }

    /**
     * Where the alignment patterns go: evenly spaced, always including
     * row and column 6, always at the same offset from each edge.
     *
     * Derived rather than tabulated. The standard prints the coordinates
     * for all 40 versions, and they follow this rule -- with one genuine
     * exception at version 32, where the even spacing would not divide.
     *
     * @return list<int>
     */
    public static function alignmentPositions(int $version): array
    {
        if ($version === 1) {
            return [];
        }

        $count = intdiv($version, 7) + 2;
        $step = $version === 32
            ? 26
            : intdiv($version * 4 + $count * 2 + 1, $count * 2 - 2) * 2;

        $positions = [];
        $position = $version * 4 + 10;

        for ($i = 0; $i < $count - 1; ++$i, $position -= $step) {
            array_unshift($positions, $position);
        }

        array_unshift($positions, 6);

        return $positions;
    }

    /**
     * The 15 bits saying which error-correction level and which mask are
     * in use, written twice so that losing one corner does not lose them.
     *
     * Protected by a BCH(15, 5) code and then XORed with a fixed pattern,
     * which is what stops an all-zero format field looking like blank
     * space.
     */
    private function drawFormatBits(QrEccLevel $level, int $mask): void
    {
        $data = $level->value << 3 | $mask;
        $remainder = $data;

        for ($i = 0; $i < 10; ++$i) {
            $remainder = ($remainder << 1) ^ (($remainder >> 9) * 0x537);
        }

        $bits = (($data << 10) | $remainder) ^ 0x5412;

        $bit = static fn (int $index): bool => (($bits >> $index) & 1) !== 0;

        // First copy, wrapped around the top-left finder pattern.
        for ($i = 0; $i <= 5; ++$i) {
            $this->setFunction(8, $i, $bit($i));
        }

        $this->setFunction(8, 7, $bit(6));
        $this->setFunction(8, 8, $bit(7));
        $this->setFunction(7, 8, $bit(8));

        for ($i = 9; $i <= 14; ++$i) {
            $this->setFunction(14 - $i, 8, $bit($i));
        }

        // Second copy, split between the other two finder patterns.
        for ($i = 0; $i <= 7; ++$i) {
            $this->setFunction($this->size - 1 - $i, 8, $bit($i));
        }

        for ($i = 8; $i <= 14; ++$i) {
            $this->setFunction(8, $this->size - 15 + $i, $bit($i));
        }

        // Always dark, always here, and carrying no information at all.
        $this->setFunction(8, $this->size - 8, true);
    }

    /**
     * The version number, from version 7 up, where a scanner can no
     * longer work the size out reliably by counting.
     *
     * Six bits under a BCH(18, 6) code, again written twice.
     */
    private function drawVersionBits(): void
    {
        if ($this->version < 7) {
            return;
        }

        $remainder = $this->version;

        for ($i = 0; $i < 12; ++$i) {
            $remainder = ($remainder << 1) ^ (($remainder >> 11) * 0x1F25);
        }

        $bits = $this->version << 12 | $remainder;

        for ($i = 0; $i < 18; ++$i) {
            $bit = (($bits >> $i) & 1) !== 0;
            $a = $this->size - 11 + $i % 3;
            $b = intdiv($i, 3);

            $this->setFunction($a, $b, $bit);
            $this->setFunction($b, $a, $bit);
        }
    }

    private function setFunction(int $x, int $y, bool $dark): void
    {
        $this->modules[$y][$x] = $dark;
        $this->isFunction[$y][$x] = true;
    }

    private function contains(int $x, int $y): bool
    {
        return $x >= 0 && $x < $this->size && $y >= 0 && $y < $this->size;
    }
}
