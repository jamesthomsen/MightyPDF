<?php

declare(strict_types=1);

namespace MightyPDF\Editor\Form;

use MightyPDF\Assembler\Dictionary;

/**
 * One fillable field, resolved out of the document's field tree.
 *
 * The separation between $dictionary and $widgets is the thing that makes
 * form filling work, and the thing that most naive attempts get wrong. A
 * field is the logical thing that holds a value; a widget is a rectangle
 * on a page that shows it. PDF lets these be the same object (the common
 * case for a simple text field), or a parent field with several widget
 * children (a radio group, or one field appearing on every page of a
 * contract) -- and a value written to the wrong one of the two either
 * does nothing or shows up on only one page.
 *
 * $onStates lists the appearance states the widgets actually have, which
 * for a checkbox is the only way to know what "ticked" is called in this
 * particular document. It is very often /Yes and quite often not.
 */
final readonly class Field
{
    /**
     * @param list<Dictionary> $widgets
     * @param list<string> $onStates appearance state names other than /Off
     * @param list<string> $options a choice field's /Opt export values
     */
    public function __construct(
        public string $name,
        public FieldType $type,
        public Dictionary $dictionary,
        public array $widgets,
        public int $flags,
        public array $onStates = [],
        public array $options = [],
        public ?int $maxLength = null,
    ) {
    }

    /** Table 227, bit 1. */
    public function isReadOnly(): bool
    {
        return ($this->flags & 1) !== 0;
    }

    /** Table 227, bit 2. */
    public function isRequired(): bool
    {
        return ($this->flags & 2) !== 0;
    }

    /** Table 228, bit 13 -- a text field that accepts line breaks. */
    public function isMultiline(): bool
    {
        return $this->type === FieldType::Text && ($this->flags & (1 << 12)) !== 0;
    }

    /** Table 230, bit 19 -- a combo box that also accepts typed-in text. */
    public function acceptsFreeText(): bool
    {
        return $this->type === FieldType::Choice && ($this->flags & (1 << 18)) !== 0;
    }
}
