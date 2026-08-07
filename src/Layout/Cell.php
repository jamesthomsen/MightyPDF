<?php

declare(strict_types=1);

namespace MightyPDF\Layout;

/**
 * One cell of a table row, where a plain string will not do: because it
 * needs a style of its own, or because it spans more than one column.
 *
 * Table::row() takes strings and these interchangeably, so a row where
 * one figure is emphasised is a row of strings with one Cell in it rather
 * than a row of Cells:
 *
 * ```php
 * $table->row(['Total', '', new Cell('48,120.00', $bold)]);
 * $table->row([new Cell('Continued overleaf', $note, colspan: 3)]);
 * ```
 */
final class Cell
{
    public function __construct(
        public readonly string $text = '',
        public readonly ?Style $style = null,
        public readonly int $colspan = 1,
    ) {
        if ($colspan < 1) {
            throw new \InvalidArgumentException("A cell must span at least one column, got $colspan.");
        }
    }

    /** $value as a cell, if it is not one already. */
    public static function from(self|string $value): self
    {
        return $value instanceof self ? $value : new self($value);
    }
}
