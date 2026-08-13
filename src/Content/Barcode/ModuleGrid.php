<?php

declare(strict_types=1);

namespace MightyPDF\Content\Barcode;

use MightyPDF\Exception\InvalidArgumentException;

/**
 * A two-dimensional symbol as a grid of dark and light modules.
 *
 * What every 2D symbology here produces and what the drawing code
 * consumes, so that placing a Data Matrix and placing a PDF417 are the
 * same loop over rectangles. QR predates this and keeps its own QrMatrix,
 * which carries the extra state masking needs (which modules are function
 * patterns) and so is not the same thing wearing a different name.
 *
 * Row 0 is the top of the symbol. PDF's y runs the other way, which is
 * the drawing code's problem and is handled in exactly one place.
 */
final class ModuleGrid
{
    /**
     * @param list<list<bool>> $modules [row][column], true where dark
     */
    private function __construct(private readonly array $modules)
    {
    }

    /**
     * @param list<list<bool>> $rows [row][column], all the same length
     */
    public static function of(array $rows): self
    {
        if ($rows === []) {
            throw new InvalidArgumentException('A symbol needs at least one row.');
        }

        $width = count($rows[0]);

        foreach ($rows as $index => $row) {
            if (count($row) !== $width) {
                throw new InvalidArgumentException(sprintf(
                    'Every row of a symbol is the same width; row 0 has %d modules and row %d has %d.',
                    $width,
                    $index,
                    count($row),
                ));
            }
        }

        return new self($rows);
    }

    public function width(): int
    {
        return count($this->modules[0]);
    }

    public function height(): int
    {
        return count($this->modules);
    }

    public function isDark(int $column, int $row): bool
    {
        return $this->modules[$row][$column] ?? false;
    }

    /** @return list<list<bool>> */
    public function rows(): array
    {
        return $this->modules;
    }

    /**
     * The symbol as one string per row, '1' for dark -- for tests and for
     * looking at a symbol without rendering it.
     *
     * @return list<string>
     */
    public function toStrings(): array
    {
        return array_map(
            static fn (array $row): string => implode('', array_map(
                static fn (bool $dark): string => $dark ? '1' : '0',
                $row,
            )),
            $this->modules,
        );
    }
}
