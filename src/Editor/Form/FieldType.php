<?php

declare(strict_types=1);

namespace MightyPDF\Editor\Form;

/**
 * What a form field actually is, from the caller's point of view.
 *
 * PDF says this in two places at once: /FT gives four broad types, and
 * bits of /Ff then split /Btn three ways and /Ch two (ISO 32000-2
 * §12.7.4). A caller filling a form cares about the combination -- a
 * checkbox and a push button are both /Btn but only one of them holds a
 * value -- so the two are resolved into one enum here rather than left
 * for every call site to recombine.
 */
enum FieldType
{
    case Text;
    case Checkbox;
    case RadioGroup;
    case PushButton;
    case Choice;
    case Signature;

    /** A field whose /FT is missing or unrecognised. */
    case Unknown;

    /** Field flags, Table 227. */
    private const int FLAG_PUSHBUTTON = 1 << 16;
    private const int FLAG_RADIO = 1 << 15;

    public static function resolve(?string $fieldType, int $flags): self
    {
        return match ($fieldType) {
            'Tx' => self::Text,
            'Ch' => self::Choice,
            'Sig' => self::Signature,
            'Btn' => match (true) {
                ($flags & self::FLAG_PUSHBUTTON) !== 0 => self::PushButton,
                ($flags & self::FLAG_RADIO) !== 0 => self::RadioGroup,
                default => self::Checkbox,
            },
            default => self::Unknown,
        };
    }

    public function holdsAValue(): bool
    {
        return match ($this) {
            self::Text, self::Checkbox, self::RadioGroup, self::Choice => true,
            self::PushButton, self::Signature, self::Unknown => false,
        };
    }
}
