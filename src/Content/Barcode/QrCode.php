<?php

declare(strict_types=1);

namespace MightyPDF\Content\Barcode;

use MightyPDF\Exception\InvalidArgumentException;

/**
 * A QR code (ISO/IEC 18004): the 2D symbology, and the one a payment
 * link, a ticket or a "scan me" on an invoice is actually printed in.
 *
 * A linear barcode carries a few dozen characters and needs a scanner
 * held square to it. This carries a few thousand, reads at any rotation,
 * and survives a torn corner -- which is why it is the one thing on this
 * list that a document generator gets asked for by name.
 *
 * Like the linear symbologies here, this produces a matrix of dark and
 * light modules and nothing else. PageBuilder::drawQrCode() turns it into
 * squares on a page.
 *
 * **Versions 1 to 40** are all supported, chosen automatically as the
 * smallest that holds the data at the requested error-correction level.
 * Pass $minVersion to force a floor, which is what a caller printing a
 * run of codes at one physical size wants -- otherwise a short one and a
 * long one come out with different module counts and so different
 * densities.
 *
 * **One mode per symbol.** The data is encoded as numeric, alphanumeric
 * or byte, whichever is compact enough to hold all of it -- not as a
 * mixture. Segmenting a string into runs of different modes can save a
 * few percent on mixed content, and costs a search this does not do. The
 * result is always a conforming symbol; it is occasionally one version
 * larger than an optimal encoder would produce.
 *
 * **Byte mode is UTF-8.** The standard says ISO-8859-1 and every scanner
 * built in the last fifteen years reads UTF-8, which is what a URL with a
 * non-ASCII character in it will be. Text that has to be Latin-1 should
 * be converted by the caller before it gets here.
 */
final class QrCode
{
    /**
     * How many check codewords each block carries, by level and version.
     * Index 0 is unused -- versions are 1-based.
     *
     * These two tables are all the version-dependent data there is. The
     * standard prints a much larger one (block counts, data capacities
     * and total codewords for all 160 combinations), but every other
     * column of it follows from these two and the module geometry, and a
     * table you derive cannot disagree with itself.
     *
     * @var array<int, list<int>>
     */
    private const array ECC_CODEWORDS_PER_BLOCK = [
        QrEccLevel::Low->value => [
            -1, 7, 10, 15, 20, 26, 18, 20, 24, 30, 18, 20, 24, 26, 30, 22, 24, 28, 30, 28, 28,
            28, 28, 30, 30, 26, 28, 30, 30, 30, 30, 30, 30, 30, 30, 30, 30, 30, 30, 30, 30,
        ],
        QrEccLevel::Medium->value => [
            -1, 10, 16, 26, 18, 24, 16, 18, 22, 22, 26, 30, 22, 22, 24, 24, 28, 28, 26, 26, 26,
            26, 28, 28, 28, 28, 28, 28, 28, 28, 28, 28, 28, 28, 28, 28, 28, 28, 28, 28, 28,
        ],
        QrEccLevel::Quartile->value => [
            -1, 13, 22, 18, 26, 18, 24, 18, 22, 20, 24, 28, 26, 24, 20, 30, 24, 28, 28, 26, 30,
            28, 30, 30, 30, 30, 28, 30, 30, 30, 30, 30, 30, 30, 30, 30, 30, 30, 30, 30, 30,
        ],
        QrEccLevel::High->value => [
            -1, 17, 28, 22, 16, 22, 28, 26, 26, 24, 28, 24, 28, 22, 24, 24, 30, 28, 28, 26, 28,
            30, 24, 30, 30, 30, 30, 30, 30, 30, 30, 30, 30, 30, 30, 30, 30, 30, 30, 30, 30,
        ],
    ];

    /** @var array<int, list<int>> */
    private const array ECC_BLOCKS = [
        QrEccLevel::Low->value => [
            -1, 1, 1, 1, 1, 1, 2, 2, 2, 2, 4, 4, 4, 4, 4, 6, 6, 6, 6, 7, 8,
            8, 9, 9, 10, 12, 12, 12, 13, 14, 15, 16, 17, 18, 19, 19, 20, 21, 22, 24, 25,
        ],
        QrEccLevel::Medium->value => [
            -1, 1, 1, 1, 2, 2, 4, 4, 4, 5, 5, 5, 8, 9, 9, 10, 10, 11, 13, 14, 16,
            17, 17, 18, 20, 21, 23, 25, 26, 28, 29, 31, 33, 35, 37, 38, 40, 43, 45, 47, 49,
        ],
        QrEccLevel::Quartile->value => [
            -1, 1, 1, 2, 2, 4, 4, 6, 6, 8, 8, 8, 10, 12, 16, 12, 17, 16, 18, 21, 20,
            23, 23, 25, 27, 29, 34, 34, 35, 38, 40, 43, 45, 48, 51, 53, 56, 59, 62, 65, 68,
        ],
        QrEccLevel::High->value => [
            -1, 1, 1, 2, 4, 4, 4, 5, 6, 8, 8, 11, 11, 16, 16, 18, 16, 19, 21, 25, 25,
            25, 34, 30, 32, 35, 37, 40, 42, 45, 48, 51, 54, 57, 60, 63, 66, 70, 74, 77, 81,
        ],
    ];

    /** The 45 characters alphanumeric mode can carry, at their code values. */
    private const string ALPHANUMERIC = '0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZ $%*+-./:';

    /** The clear space a scanner needs on every side, in modules. */
    public const int QUIET_ZONE_MODULES = 4;

    /**
     * @param list<list<bool>> $modules [y][x], true where dark
     */
    private function __construct(
        public readonly int $version,
        public readonly QrEccLevel $level,
        public readonly int $mask,
        private readonly array $modules,
    ) {
    }

    /**
     * $minVersion forces a floor on the size, so that a run of codes of
     * differing lengths comes out at one module count and so one density.
     */
    public static function encode(
        string $data,
        QrEccLevel $level = QrEccLevel::Medium,
        int $minVersion = 1,
        int $maxVersion = 40,
    ): self {
        if ($minVersion < 1 || $maxVersion > 40 || $minVersion > $maxVersion) {
            throw new InvalidArgumentException(
                "Versions run from 1 to 40, and the minimum cannot exceed the maximum -- got $minVersion to $maxVersion.",
            );
        }

        $mode = self::modeFor($data);
        $version = self::versionFor($data, $mode, $level, $minVersion, $maxVersion);

        $codewords = self::codewords($data, $mode, $version, $level);
        $blocks = self::interleave($codewords, $version, $level);

        return self::withBestMask($version, $level, $blocks);
    }

    /** Modules per side, including no quiet zone. */
    public function size(): int
    {
        return count($this->modules);
    }

    public function isDark(int $x, int $y): bool
    {
        return $this->modules[$y][$x] ?? false;
    }

    /** @return list<list<bool>> [y][x] */
    public function modules(): array
    {
        return $this->modules;
    }

    // -- Encoding --------------------------------------------------------

    /**
     * The most compact mode that can hold all of $data.
     *
     * @return 'numeric'|'alphanumeric'|'byte'
     */
    private static function modeFor(string $data): string
    {
        if ($data !== '' && ctype_digit($data)) {
            return 'numeric';
        }

        $length = strlen($data);

        for ($i = 0; $i < $length; ++$i) {
            if (!str_contains(self::ALPHANUMERIC, $data[$i])) {
                return 'byte';
            }
        }

        return $data === '' ? 'byte' : 'alphanumeric';
    }

    private static function modeIndicator(string $mode): int
    {
        return match ($mode) {
            'numeric' => 1,
            'alphanumeric' => 2,
            default => 4,
        };
    }

    /**
     * How many bits the character count takes, which widens twice as
     * versions grow -- at 10 and again at 27.
     */
    private static function countBits(string $mode, int $version): int
    {
        $group = match (true) {
            $version <= 9 => 0,
            $version <= 26 => 1,
            default => 2,
        };

        return match ($mode) {
            'numeric' => [10, 12, 14][$group],
            'alphanumeric' => [9, 11, 13][$group],
            default => [8, 16, 16][$group],
        };
    }

    private static function versionFor(
        string $data,
        string $mode,
        QrEccLevel $level,
        int $minVersion,
        int $maxVersion,
    ): int {
        for ($version = $minVersion; $version <= $maxVersion; ++$version) {
            $capacity = self::dataCodewords($version, $level) * 8;
            $needed = 4 + self::countBits($mode, $version) + self::payloadBits($data, $mode);

            if ($needed <= $capacity) {
                return $version;
            }
        }

        throw new InvalidArgumentException(sprintf(
            '%d bytes is too much for a version-%d QR code at this error-correction level. '
            . 'Use a lower level, raise the maximum version, or put less in it.',
            strlen($data),
            $maxVersion,
        ));
    }

    private static function payloadBits(string $data, string $mode): int
    {
        $length = strlen($data);

        return match ($mode) {
            // Three digits in ten bits, and the remainder in four or
            // seven -- which is why numeric mode is worth having.
            'numeric' => 10 * intdiv($length, 3) + match ($length % 3) {
                1 => 4,
                2 => 7,
                default => 0,
            },
            'alphanumeric' => 11 * intdiv($length, 2) + 6 * ($length % 2),
            default => 8 * $length,
        };
    }

    /**
     * The data as codewords: mode, count, payload, terminator, padding.
     *
     * @return list<int>
     */
    private static function codewords(string $data, string $mode, int $version, QrEccLevel $level): array
    {
        $bits = new BitBuffer();
        $bits->append(self::modeIndicator($mode), 4);
        $bits->append(strlen($data), self::countBits($mode, $version));

        match ($mode) {
            'numeric' => self::appendNumeric($bits, $data),
            'alphanumeric' => self::appendAlphanumeric($bits, $data),
            default => self::appendBytes($bits, $data),
        };

        $capacity = self::dataCodewords($version, $level) * 8;

        // The terminator is up to four zeros -- fewer if the symbol is
        // nearly full, which is legal and is why this is a min().
        $bits->append(0, min(4, $capacity - $bits->length()));
        $bits->append(0, (8 - $bits->length() % 8) % 8);

        // Then these two bytes alternating, forever. The standard names
        // them; they are not arbitrary, and a decoder relies on nothing
        // about them beyond their being ignored.
        for ($pad = 0xEC; $bits->length() < $capacity; $pad ^= 0xEC ^ 0x11) {
            $bits->append($pad, 8);
        }

        return $bits->toBytes();
    }

    private static function appendNumeric(BitBuffer $bits, string $data): void
    {
        foreach (str_split($data, 3) as $group) {
            $bits->append((int) $group, strlen($group) * 3 + 1);
        }
    }

    private static function appendAlphanumeric(BitBuffer $bits, string $data): void
    {
        foreach (str_split($data, 2) as $pair) {
            $first = strpos(self::ALPHANUMERIC, $pair[0]);

            if (strlen($pair) === 1) {
                $bits->append((int) $first, 6);

                continue;
            }

            // Two characters in eleven bits rather than twelve, by
            // treating the pair as a base-45 number.
            $bits->append((int) $first * 45 + (int) strpos(self::ALPHANUMERIC, $pair[1]), 11);
        }
    }

    private static function appendBytes(BitBuffer $bits, string $data): void
    {
        $length = strlen($data);

        for ($i = 0; $i < $length; ++$i) {
            $bits->append(ord($data[$i]), 8);
        }
    }

    // -- Blocks ----------------------------------------------------------

    /**
     * Splits the data into blocks, computes each one's check codewords,
     * and interleaves the lot -- which is what spreads a physical smudge
     * across every block instead of destroying one of them.
     *
     * @param list<int> $data
     *
     * @return list<int>
     */
    private static function interleave(array $data, int $version, QrEccLevel $level): array
    {
        $blockCount = self::ECC_BLOCKS[$level->value][$version];
        $eccLength = self::ECC_CODEWORDS_PER_BLOCK[$level->value][$version];
        $rawCodewords = intdiv(self::rawDataModules($version), 8);

        $shortBlocks = $blockCount - $rawCodewords % $blockCount;
        $shortLength = intdiv($rawCodewords, $blockCount) - $eccLength;

        $blocks = [];
        $offset = 0;

        for ($i = 0; $i < $blockCount; ++$i) {
            $length = $shortLength + ($i < $shortBlocks ? 0 : 1);
            $block = array_slice($data, $offset, $length);
            $offset += $length;

            $blocks[] = [$block, ReedSolomon::remainder($block, $eccLength)];
        }

        $result = [];

        // Data first, one codeword from each block in turn. The short
        // blocks have nothing to give on the last pass, which is what the
        // guard is for.
        for ($i = 0; $i <= $shortLength; ++$i) {
            foreach ($blocks as $index => [$block]) {
                if ($i < $shortLength || $index >= $shortBlocks) {
                    $result[] = $block[$i];
                }
            }
        }

        for ($i = 0; $i < $eccLength; ++$i) {
            foreach ($blocks as [, $ecc]) {
                $result[] = $ecc[$i];
            }
        }

        return $result;
    }

    private static function dataCodewords(int $version, QrEccLevel $level): int
    {
        return intdiv(self::rawDataModules($version), 8)
            - self::ECC_CODEWORDS_PER_BLOCK[$level->value][$version]
            * self::ECC_BLOCKS[$level->value][$version];
    }

    /**
     * Modules available for data and error correction: the whole symbol,
     * less the function patterns.
     *
     * Computed rather than tabulated, which is why the tables above are
     * two columns instead of six.
     */
    private static function rawDataModules(int $version): int
    {
        $result = (16 * $version + 128) * $version + 64;

        if ($version >= 2) {
            $alignment = intdiv($version, 7) + 2;
            $result -= (25 * $alignment - 10) * $alignment - 55;

            if ($version >= 7) {
                $result -= 36;
            }
        }

        return $result;
    }

    // -- The matrix ------------------------------------------------------

    /**
     * Draws the symbol under each of the eight masks and keeps the one
     * the standard's penalty rules like best.
     *
     * Masking exists because an unmasked symbol can come out with large
     * blank areas or with patterns that look like the finder squares,
     * either of which defeats a scanner. There is no way to know which
     * mask avoids that without trying them, so the standard says to try
     * all eight and score them -- and every conforming encoder does
     * exactly this.
     *
     * @param list<int> $codewords
     */
    private static function withBestMask(int $version, QrEccLevel $level, array $codewords): self
    {
        $best = null;
        $bestPenalty = PHP_INT_MAX;
        $bestMask = 0;

        for ($mask = 0; $mask < 8; ++$mask) {
            $builder = new QrMatrix($version);
            $builder->drawFunctionPatterns($level, $mask);
            $builder->drawCodewords($codewords);
            $builder->applyMask($mask);

            $penalty = $builder->penalty();

            if ($penalty < $bestPenalty) {
                $bestPenalty = $penalty;
                $best = $builder->modules();
                $bestMask = $mask;
            }
        }

        assert($best !== null);

        return new self($version, $level, $bestMask, $best);
    }
}
