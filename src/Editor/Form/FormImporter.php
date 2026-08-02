<?php

declare(strict_types=1);

namespace MightyPDF\Editor\Form;

use MightyPDF\Assembler\Dictionary;
use MightyPDF\Assembler\Document;
use MightyPDF\Assembler\Types\PdfBoolean;
use MightyPDF\Assembler\Types\PdfInteger;
use MightyPDF\Assembler\Types\PdfName;
use MightyPDF\Assembler\Types\PdfString;
use MightyPDF\Assembler\Types\PdfValue;

/**
 * Collects form fields from several source documents into the one
 * /AcroForm a merged document is allowed to have.
 *
 * One instance is shared by every PageImporter feeding a single target
 * (see PdfMerger), because the two problems here are only visible across
 * sources:
 *
 * **Names collide.** A field's name is how anything later addresses it,
 * and two files that each have a "signature" field are not describing
 * one field -- filling either would fill both, since a value lives on
 * the field and every widget of it displays that value. So the second
 * one is renamed, and take() reports what it became.
 *
 * **Default resources collide.** A field's /DA names a font from the
 * form's /DR ("/Helv 9 Tf"), and two files' /DR entries may both be
 * called /Helv and be different fonts. Where they are byte-identical
 * the entry is shared; where they are not, the incoming one is renamed
 * and the /DA strings that referred to it are rewritten to match.
 */
final class FormImporter
{
    /** @var array<string, true> names already taken by a root field */
    private array $takenNames = [];

    private bool $hasFields = false;

    /** @var array<string, array<string, string>> category => name in the merged /DR => what that resource is */
    private array $resourceSignatures = [];

    private bool $needAppearances = false;
    private int $signatureFlags = 0;

    public function __construct(private readonly Document $target)
    {
    }

    /**
     * Adds a root field to the merged form, renaming it if a field of
     * that name is already there.
     *
     * Only root fields are named here. A field nested inside one keeps
     * its own partial name: what makes it unique is the ancestry it
     * hangs from, which renaming the root has already settled.
     */
    public function take(Dictionary $field): void
    {
        $name = $field->get('T');

        if ($name instanceof PdfString) {
            $field->set('T', PdfString::text($this->freeName($name->toUtf8())));
        }

        $this->hasFields = true;
        $this->target->acroForm()->addField($field->objectId());
    }

    /**
     * Merges one source form's dictionary-level settings.
     *
     * /DA and /Q describe how fields with none of their own are drawn,
     * so the first source to state either wins -- there is one form and
     * it can hold one answer, and overwriting it with the last source's
     * would make the result depend on merge order. The two flags are
     * different: they are claims about the document as a whole, and a
     * merged document that contains a signature field does contain one
     * however few of its sources did.
     *
     * Only values that carry nothing of the source document with them
     * are taken. A string or a number means the same thing in any file;
     * a reference means an object number, and object numbers are exactly
     * what importing renumbers.
     */
    public function takeFormSettings(?Dictionary $sourceForm): void
    {
        if ($sourceForm === null) {
            return;
        }

        $form = $this->target->acroForm();

        foreach (['DA', 'Q'] as $key) {
            $value = $sourceForm->get($key);

            if ($value instanceof PdfString || $value instanceof PdfInteger) {
                if ($form->get($key) === null) {
                    $form->set($key, $value);
                }
            }
        }

        $needAppearances = $sourceForm->get('NeedAppearances');
        $this->needAppearances = $this->needAppearances
            || ($needAppearances !== null && $needAppearances->format() === 'true');

        $flags = $sourceForm->get('SigFlags');

        if ($flags instanceof PdfInteger) {
            $this->signatureFlags |= $flags->value();
        }
    }

    /**
     * Adds one entry of a source form's /DR to the merged form's, and
     * says what it ended up called.
     *
     * $signature identifies the resource by what it *is* rather than by
     * where it lives -- object numbers differ between files by
     * definition, so two forms' /Helv can only be recognised as one
     * font by comparing their contents. Where the signatures match, the
     * entry already there is used and $copy is never called; the
     * incoming resource is not copied into the document at all.
     *
     * $copy is a closure for exactly that reason: deciding whether a
     * resource is needed has to come before copying it, or a merge of
     * two forms that agree about their fonts carries two of each.
     *
     * @param \Closure(): PdfValue $copy
     */
    public function takeDefaultResource(string $category, string $name, string $signature, \Closure $copy): string
    {
        $entries = $this->target->acroForm()->defaultResources()->get($category);

        if (!$entries instanceof Dictionary) {
            $entries = new Dictionary();
            $this->target->acroForm()->defaultResources()->set($category, $entries);
        }

        foreach ($this->resourceSignatures[$category] ?? [] as $taken => $known) {
            if ($known === $signature) {
                return (string) $taken;
            }
        }

        $chosen = $name;
        $suffix = 2;

        while ($entries->get($chosen) !== null) {
            $chosen = $name . '_' . $suffix++;
        }

        $entries->set($chosen, $copy());
        $this->resourceSignatures[$category][$chosen] = $signature;

        return $chosen;
    }

    /**
     * Writes the flags that could only be decided once every source had
     * been seen.
     *
     * Nothing happens where no field was taken: asking the target for
     * its form is what creates one, and a merge of documents with no
     * fields should not leave an empty /AcroForm behind.
     */
    public function finish(): void
    {
        if (!$this->hasFields) {
            return;
        }

        $form = $this->target->acroForm();

        // The widgets brought their own appearance streams with them, so
        // regeneration is asked for only where a source asked for it --
        // a merged document is not a reason to redraw fields that were
        // already drawn. (A new document's form defaults the flag on,
        // which is right for fields this library creates and wrong for
        // fields it copied.)
        $form->set('NeedAppearances', $this->needAppearances ? new PdfBoolean(true) : null);

        if ($this->signatureFlags !== 0) {
            $form->set('SigFlags', new PdfInteger($this->signatureFlags));
        }
    }

    private function freeName(string $name): string
    {
        $candidate = $name;
        $suffix = 2;

        while (isset($this->takenNames[$candidate])) {
            $candidate = $name . '_' . $suffix++;
        }

        $this->takenNames[$candidate] = true;

        return $candidate;
    }

    /**
     * Rewrites the font name in a /DA string, for a field whose form's
     * /DR entry had to be renamed.
     *
     * A /DA is a content-stream fragment ("0 g /Helv 9 Tf"), and the
     * name being replaced is the operand of Tf. Matching that operator
     * rather than the name alone matters: /Helv may equally appear in
     * the string as a colour or an unrelated operand, and a blind
     * replacement would rewrite it there too.
     *
     * @param array<string, string> $renames
     */
    public static function rewriteDefaultAppearance(string $da, array $renames): string
    {
        if ($renames === []) {
            return $da;
        }

        return (string) preg_replace_callback(
            '~/([^\s/\[\]<>(){}]+)(\s+[\d.+-]+\s+Tf)~',
            static fn (array $match): string => '/' . ($renames[$match[1]] ?? $match[1]) . $match[2],
            $da,
        );
    }

    /** Whether a dictionary is a widget annotation rather than any other kind. */
    public static function isWidget(?Dictionary $annotation): bool
    {
        $subtype = $annotation?->get('Subtype');

        return $subtype instanceof PdfName && $subtype->value() === 'Widget';
    }
}
