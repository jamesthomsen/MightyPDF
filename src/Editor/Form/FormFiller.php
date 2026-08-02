<?php

declare(strict_types=1);

namespace MightyPDF\Editor\Form;

use MightyPDF\Assembler\Dictionary;
use MightyPDF\Assembler\Stream;
use MightyPDF\Assembler\Types\PdfArray;
use MightyPDF\Assembler\Types\PdfBoolean;
use MightyPDF\Assembler\Types\PdfHexString;
use MightyPDF\Assembler\Types\PdfInteger;
use MightyPDF\Assembler\Types\PdfName;
use MightyPDF\Assembler\Types\PdfReference;
use MightyPDF\Assembler\Types\PdfString;
use MightyPDF\Assembler\Types\PdfValue;
use MightyPDF\Content\Text\Utf8;
use MightyPDF\Editor\PdfEditor;

/**
 * Fills in the AcroForm fields of an existing PDF (ISO 32000-2 §12.7).
 *
 * Field names are hierarchical. A field's real name is its own /T joined
 * to every ancestor's /T with dots, so the thing a caller thinks of as
 * "line1" may be "address.line1" in the file and a flat scan of /Fields
 * will never find it. This walks the tree properly and indexes by full
 * name.
 *
 * Setting a value means writing in two places, not one, and this is where
 * hand-rolled form filling usually goes wrong:
 *
 * - /V on the *field*, which is the value the document carries.
 * - /AS on every *widget*, which is the appearance state each rectangle
 *   on the page is currently showing.
 *
 * Setting /V alone on a checkbox is the single most common form-filling
 * bug there is: the value is genuinely in the file, every extraction tool
 * agrees the box is ticked, and it renders unticked to every human who
 * opens it.
 *
 * For text and choice fields the new value is also *drawn*, into a fresh
 * appearance stream (see TextAppearanceBuilder), so that a filled form
 * looks filled in even to a reader that ignores /NeedAppearances. Where
 * the form does not say enough to draw with, the stale stream is removed
 * and /NeedAppearances set instead: an empty box is a visible problem,
 * whereas a box still confidently showing the previous value is not.
 */
final class FormFiller
{
    private readonly PdfEditor $editor;

    /** @var array<string, Field>|null built lazily, then reused */
    private ?array $fields = null;

    private ?TextAppearanceBuilder $appearances = null;

    /**
     * @param bool $allowXfa see isXfaForm() -- filling an XFA form's
     *        AcroForm values may have no visible effect at all, so it has
     *        to be asked for explicitly.
     */
    public function __construct(PdfEditor $editor, private readonly bool $allowXfa = false)
    {
        $this->editor = $editor;
    }

    public function hasForm(): bool
    {
        return $this->acroForm() !== null;
    }

    /**
     * Whether the document is an XFA form -- an XML form description that
     * Acrobat may honour *instead of* the AcroForm fields underneath.
     *
     * Filling such a form's AcroForm values can leave the visible form
     * completely unchanged in Acrobat while looking correct in every other
     * tool, which is a uniquely difficult failure to diagnose. Hence the
     * refusal by default.
     */
    public function isXfaForm(): bool
    {
        return $this->acroForm()?->get('XFA') !== null;
    }

    /** Every fillable field's full name, in document order. */
    public function names(): array
    {
        return array_keys($this->index());
    }

    public function field(string $name): ?Field
    {
        return $this->index()[$name] ?? null;
    }

    /**
     * Every field's current value as text, for inspecting a form before
     * filling it (or for checking one afterwards).
     *
     * @return array<string, string|null>
     */
    public function values(): array
    {
        $values = [];

        foreach ($this->index() as $name => $field) {
            $value = $this->editor->resolve($field->dictionary->get('V'));

            $values[$name] = match (true) {
                $value instanceof PdfName => $value->value(),
                $value instanceof PdfString, $value instanceof PdfHexString => $value->toUtf8(),
                default => null,
            };
        }

        return $values;
    }

    /**
     * @param array<string, string|bool|int|float|null> $values keyed by full field name
     */
    public function fill(array $values): void
    {
        foreach ($values as $name => $value) {
            $this->set($name, $value);
        }
    }

    public function set(string $name, string|bool|int|float|null $value): void
    {
        $field = $this->field($name) ?? throw new FormException($this->unknownFieldMessage($name));

        if ($this->isXfaForm() && !$this->allowXfa) {
            throw new FormException(
                'This is an XFA form, and Acrobat may ignore the AcroForm values entirely -- '
                . 'the fill would appear to work everywhere except the reader most people use. '
                . 'Construct the FormFiller with allowXfa: true to fill it anyway.',
            );
        }

        match ($field->type) {
            FieldType::Text => $this->setTextValue($field, $value),
            FieldType::Checkbox, FieldType::RadioGroup => $this->setButtonValue($field, $value),
            FieldType::Choice => $this->setChoiceValue($field, $value),
            FieldType::PushButton => throw new FormException(
                "\"$name\" is a push button; it triggers an action and holds no value.",
            ),
            FieldType::Signature => throw new FormException(
                "\"$name\" is a signature field, which cannot be filled by setting a value.",
            ),
            FieldType::Unknown => throw new FormException(
                "\"$name\" has no usable /FT, so its type is unknown and it cannot be filled.",
            ),
        };
    }

    private function setTextValue(Field $field, string|bool|int|float|null $value): void
    {
        $text = '';

        if ($value === null) {
            $field->dictionary->set('V', null);
        } else {
            $text = is_bool($value) ? ($value ? 'Yes' : 'No') : (string) $value;

            // /MaxLen counts characters, not bytes, so this has to be a
            // code point count -- a 5-character limit must accept "café£".
            $length = count(Utf8::codePoints($text));

            if ($field->maxLength !== null && $length > $field->maxLength) {
                throw new FormException(sprintf(
                    '"%s" holds at most %d characters and the value has %d. Truncating silently would '
                    . 'put data in the file that nobody asked for, so this is refused.',
                    $field->name,
                    $field->maxLength,
                    $length,
                ));
            }

            $field->dictionary->set('V', PdfString::text($text));
        }

        $this->refreshAppearances($field, $text);
        $this->markChanged($field->dictionary);
    }

    private function setButtonValue(Field $field, string|bool|int|float|null $value): void
    {
        $state = $this->buttonState($field, $value);

        $field->dictionary->set('V', new PdfName($state));
        $this->markChanged($field->dictionary);

        foreach ($field->widgets as $widget) {
            // Each widget shows the chosen state only if it is one of the
            // states *that widget* has an appearance for. This is what
            // makes a radio group work: exactly one member recognises the
            // value, and every other member must be turned off explicitly
            // rather than merely left alone.
            $widget->set('AS', new PdfName(
                in_array($state, $this->onStatesOf($widget), true) ? $state : 'Off',
            ));

            $this->markChanged($widget);
        }
    }

    private function buttonState(Field $field, string|bool|int|float|null $value): string
    {
        if ($value === false || $value === null || $value === 'Off') {
            return 'Off';
        }

        if ($value === true) {
            if (count($field->onStates) !== 1) {
                throw new FormException(sprintf(
                    '"%s" has %d states to choose between (%s), so `true` is ambiguous -- pass the one you want.',
                    $field->name,
                    count($field->onStates),
                    $field->onStates === [] ? 'none' : '/' . implode(', /', $field->onStates),
                ));
            }

            return $field->onStates[0];
        }

        $state = (string) $value;

        if (!in_array($state, $field->onStates, true)) {
            throw new FormException(sprintf(
                '"%s" has no appearance for the state "%s". This document calls its states %s -- '
                . 'they are chosen by whoever made the form, and "Yes" is a convention rather than a rule.',
                $field->name,
                $state,
                $field->onStates === [] ? '(none at all)' : '/' . implode(', /', $field->onStates),
            ));
        }

        return $state;
    }

    private function setChoiceValue(Field $field, string|bool|int|float|null $value): void
    {
        if ($value === null) {
            $field->dictionary->set('V', null);
            $field->dictionary->set('I', null);
            $this->refreshAppearances($field, '');
            $this->markChanged($field->dictionary);

            return;
        }

        $text = is_bool($value) ? ($value ? 'Yes' : 'No') : (string) $value;
        $index = array_search($text, $field->options, true);

        if ($index === false && !$field->acceptsFreeText()) {
            throw new FormException(sprintf(
                '"%s" does not offer "%s". Its options are: %s.',
                $field->name,
                $text,
                $field->options === [] ? '(none)' : '"' . implode('", "', $field->options) . '"',
            ));
        }

        $field->dictionary->set('V', PdfString::text($text));

        // /I is the selected option's position in /Opt, and readers use it
        // to highlight the row. A stale /I pointing at a different row is
        // worse than none, so it is cleared when the value is not on the
        // list at all.
        $field->dictionary->set('I', $index === false ? null : new PdfArray(new PdfInteger($index)));

        $this->refreshAppearances($field, $text);
        $this->markChanged($field->dictionary);
    }

    /**
     * Replaces each widget's appearance stream with one showing the new
     * value.
     *
     * Drawing it here rather than leaving it to /NeedAppearances is what
     * makes a filled form look filled in to a reader that ignores the
     * flag. Where the form does not say enough to draw with -- no /DA, or
     * a font whose widths are nowhere in the file -- the stale stream is
     * removed and the flag set instead: an empty box is a visible problem,
     * whereas a box still confidently showing the previous value is not.
     */
    private function refreshAppearances(Field $field, string $text): void
    {
        foreach ($field->widgets as $widget) {
            $appearance = $this->appearances()->build($field, $widget, $text);

            if ($appearance === null) {
                if ($widget->get('AP') !== null) {
                    $widget->set('AP', null);
                    $this->markChanged($widget);
                }

                $this->requireAppearanceRegeneration();

                continue;
            }

            $widget->set('AP', (new Dictionary())->set('N', new PdfReference($appearance->objectId())));
            $this->markChanged($widget);
        }
    }

    private function appearances(): TextAppearanceBuilder
    {
        return $this->appearances ??= new TextAppearanceBuilder($this->editor);
    }

    private function requireAppearanceRegeneration(): void
    {
        $acroForm = $this->acroForm();

        if ($acroForm === null) {
            return;
        }

        $acroForm->set('NeedAppearances', new PdfBoolean(true));

        // Whether the /AcroForm is an object of its own or written inline
        // in the catalog is the original writer's choice; when it is
        // inline, the catalog is the object that has to be rewritten.
        $this->markChanged($acroForm->hasObjectId() ? $acroForm : $this->editor->catalog());
    }

    private function markChanged(Dictionary $dictionary): void
    {
        if (!$dictionary->hasObjectId()) {
            throw new FormException(
                'This form field is written inline inside another object rather than as an object of '
                . 'its own, so an incremental update cannot rewrite it on its own.',
            );
        }

        $this->editor->register($dictionary);
    }

    private function acroForm(): ?Dictionary
    {
        return $this->editor->resolveDictionary($this->editor->catalog()->get('AcroForm'));
    }

    /** @return array<string, Field> */
    private function index(): array
    {
        if ($this->fields !== null) {
            return $this->fields;
        }

        $this->fields = [];
        $roots = $this->editor->resolve($this->acroForm()?->get('Fields'));

        if ($roots instanceof PdfArray) {
            foreach ($roots->items() as $root) {
                $this->collect($root, '', [], []);
            }
        }

        return $this->fields;
    }

    /**
     * Walks one node of the field tree.
     *
     * @param array<string, PdfValue> $inherited /FT, /Ff, /V, /DA and /Q
     *        pass down the tree (§12.7.3.2), so a group of fields can
     *        declare "these are all text fields, read-only" once at the
     *        top and a leaf may have no /FT of its own at all.
     * @param array<int, true> $seen guards against a /Kids cycle
     */
    private function collect(?PdfValue $reference, string $prefix, array $inherited, array $seen): void
    {
        $node = $this->editor->resolveDictionary($reference);

        if ($node === null) {
            return;
        }

        if ($node->hasObjectId()) {
            if (isset($seen[$node->objectId()])) {
                return;
            }

            $seen[$node->objectId()] = true;
        }

        foreach (['FT', 'Ff', 'V', 'DA', 'Q'] as $key) {
            $own = $node->get($key);

            if ($own !== null) {
                $inherited[$key] = $own;
            }
        }

        $title = $this->textOf($node->get('T'));
        $name = $title === null ? $prefix : ($prefix === '' ? $title : "$prefix.$title");

        [$childFields, $widgets] = $this->partitionKids($node);

        if ($childFields !== []) {
            foreach ($childFields as $child) {
                $this->collect($child, $name, $inherited, $seen);
            }

            return;
        }

        if ($name === '') {
            // A widget sitting directly in /Fields with no /T anywhere
            // above it. Nothing can address it, so nothing can fill it.
            return;
        }

        $this->fields[$name] = $this->buildField($name, $node, $widgets === [] ? [$node] : $widgets, $inherited);
    }

    /**
     * Splits a node's /Kids into child *fields* and its own *widgets*.
     *
     * The rule is /T: a kid that names itself is a field in its own right,
     * and one that does not is a rectangle belonging to this field
     * (§12.7.3.1). The distinction is invisible without it -- both are
     * dictionaries hanging off the same /Kids array.
     *
     * @return array{0: list<PdfValue>, 1: list<Dictionary>}
     */
    private function partitionKids(Dictionary $node): array
    {
        $kids = $this->editor->resolve($node->get('Kids'));

        if (!$kids instanceof PdfArray) {
            return [[], []];
        }

        $childFields = [];
        $widgets = [];

        foreach ($kids->items() as $kid) {
            $resolved = $this->editor->resolveDictionary($kid);

            if ($resolved === null) {
                continue;
            }

            if ($resolved->get('T') !== null) {
                $childFields[] = $kid;
                continue;
            }

            $widgets[] = $resolved;
        }

        return [$childFields, $widgets];
    }

    /**
     * @param list<Dictionary> $widgets
     * @param array<string, PdfValue> $inherited
     */
    private function buildField(string $name, Dictionary $node, array $widgets, array $inherited): Field
    {
        $fieldType = $inherited['FT'] ?? null;
        $flags = $inherited['Ff'] ?? null;
        $maxLength = $this->editor->resolve($node->get('MaxLen'));

        $onStates = [];

        foreach ($widgets as $widget) {
            foreach ($this->onStatesOf($widget) as $state) {
                $onStates[$state] = true;
            }
        }

        return new Field(
            name: $name,
            type: FieldType::resolve(
                $fieldType instanceof PdfName ? $fieldType->value() : null,
                $flags instanceof PdfInteger ? $flags->value() : 0,
            ),
            dictionary: $node,
            widgets: $widgets,
            flags: $flags instanceof PdfInteger ? $flags->value() : 0,
            onStates: array_keys($onStates),
            options: $this->optionsOf($node),
            maxLength: $maxLength instanceof PdfInteger ? $maxLength->value() : null,
        );
    }

    /**
     * The appearance states one widget can display, /Off excluded.
     *
     * @return list<string>
     */
    private function onStatesOf(Dictionary $widget): array
    {
        $appearances = $this->editor->resolveDictionary($widget->get('AP'));
        $normal = $this->editor->resolveDictionary($appearances?->get('N'));

        // A Stream *is* a Dictionary here, and for a text field /AP /N is
        // exactly that -- one appearance, not a set of named states. Its
        // entries are /Length and /Filter, which would otherwise be
        // reported as states the caller could select.
        if ($normal === null || $normal instanceof Stream) {
            return [];
        }

        $states = [];

        foreach (array_keys($normal->entries()) as $state) {
            if ((string) $state !== 'Off') {
                $states[] = (string) $state;
            }
        }

        return $states;
    }

    /**
     * A choice field's selectable values.
     *
     * /Opt entries are either a string, or a two-element array of
     * [export value, display text] -- and it is the export value that
     * goes in /V, while the display text is the only part the user ever
     * saw. Both are accepted when setting, since a caller reading the
     * form on screen has no way to know the difference exists.
     *
     * @return list<string>
     */
    private function optionsOf(Dictionary $node): array
    {
        $options = $this->editor->resolve($node->get('Opt'));

        if (!$options instanceof PdfArray) {
            return [];
        }

        $out = [];

        foreach ($options->items() as $option) {
            $option = $this->editor->resolve($option);

            if ($option instanceof PdfArray) {
                $option = $this->editor->resolve($option->items()[0] ?? null);
            }

            $text = $this->textOf($option);

            if ($text !== null) {
                $out[] = $text;
            }
        }

        return $out;
    }

    private function textOf(?PdfValue $value): ?string
    {
        $value = $this->editor->resolve($value);

        return match (true) {
            $value instanceof PdfString, $value instanceof PdfHexString => $value->toUtf8(),
            default => null,
        };
    }

    private function unknownFieldMessage(string $name): string
    {
        $names = $this->names();

        if ($names === []) {
            return "This PDF has no fillable form fields, so there is no field named \"$name\".";
        }

        $closest = null;
        $best = PHP_INT_MAX;

        foreach ($names as $candidate) {
            $distance = levenshtein(strtolower($name), strtolower($candidate));

            if ($distance < $best) {
                $best = $distance;
                $closest = $candidate;
            }
        }

        // Field names are hierarchical, so the usual mistake is a real
        // field addressed by its leaf name alone. Naming the closest match
        // turns that from a puzzle into a typo.
        $hint = $closest !== null && $best <= max(3, intdiv(strlen($name), 3))
            ? " Did you mean \"$closest\"?"
            : ' Available fields: "' . implode('", "', array_slice($names, 0, 20)) . '".';

        return "This PDF has no form field named \"$name\".$hint";
    }
}
