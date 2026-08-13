<?php

declare(strict_types=1);

namespace MightyPDF\Editor\Form;

use MightyPDF\Assembler\Dictionary;
use MightyPDF\Assembler\Stream;
use MightyPDF\Assembler\Types\PdfArray;
use MightyPDF\Assembler\Types\PdfBoolean;
use MightyPDF\Assembler\Types\PdfInteger;
use MightyPDF\Assembler\Types\PdfName;
use MightyPDF\Assembler\Types\PdfReference;
use MightyPDF\Assembler\Types\PdfValue;
use MightyPDF\Content\ContentStream;
use MightyPDF\Editor\PageOverlay;
use MightyPDF\Editor\PageTree;
use MightyPDF\Editor\PdfEditor;

/**
 * Turns filled-in form fields into ordinary page content: what the form
 * showed stays exactly where it was and looks the same, and there is no
 * longer a form to edit, tab through, or accidentally clear.
 *
 * This is the step after FormFiller, and the two together are the whole of
 * "fill this out and send it": a filled form is still a form, and a
 * recipient's reader will happily let them retype the numbers in it.
 *
 * **Nothing is re-drawn.** A widget's appearance stream already contains
 * the drawing a reader displays for it, so flattening places that stream
 * on the page rather than reconstructing it from /V and /DA. That matters
 * for fidelity -- a flattened form is pixel-identical to the form, not a
 * best-effort reproduction of it -- and it is also the only honest option,
 * since the appearance is the only description of the field that is
 * guaranteed to exist.
 *
 * It is also why a field with no appearance is a refusal rather than a
 * shrug (see flatten()): that field flattens to blank paper, and blank
 * paper cannot afterwards be told apart from a field somebody chose to
 * leave empty. A form written by this library is exactly that case until
 * FormFiller has been over it -- text and choice fields are written
 * relying on /NeedAppearances and have no stream of their own to place --
 * so the two really are a pair, and the error says so.
 *
 * The placement follows §12.5.5: the appearance's /BBox is transformed by
 * its /Matrix, and the *bounding box of that result* is mapped onto the
 * widget's /Rect. Doing only the obvious part of this -- translating to
 * the rect's corner -- is the usual flattening bug, and it puts every
 * rotated or non-unit-scaled field in the wrong place and the wrong size.
 */
final class FormFlattener
{
    /**
     * Distinct from PageOverlay's own prefix, since both name things in
     * one /XObject dictionary.
     */
    private const string RESOURCE_PREFIX = 'MPFlat';

    private readonly FormFiller $form;

    private readonly PageTree $tree;

    /** @var list<string> */
    private array $flattened = [];

    /** @var list<string> */
    private array $withoutAppearance = [];

    /**
     * Object ids no longer part of the form: the flattened fields, plus
     * any ancestor left holding nothing once they were taken out.
     *
     * @var array<int, true>
     */
    private array $removed = [];

    public function __construct(
        private readonly PdfEditor $editor,
        private readonly bool $allowXfa = false,
    ) {
        $this->form = new FormFiller($editor, $allowXfa);
        $this->tree = new PageTree($editor);
    }

    /**
     * Draws the fields onto their pages and removes the form.
     *
     * @param list<string>|null $names the fields to flatten, or null for
     *        all of them. Naming a subset leaves the rest fillable, which
     *        is how a form is locked down a section at a time.
     * @param bool $allowBlankFields flatten fields that have no appearance
     *        stream, which draws nothing where they were. Refused by
     *        default; see below for why that is a decision rather than a
     *        detail.
     */
    public function flatten(?array $names = null, bool $allowBlankFields = false): void
    {
        if (!$this->form->hasForm()) {
            return;
        }

        if ($this->form->isXfaForm() && !$this->allowXfa) {
            throw new FormException(
                'This is an XFA form, so what Acrobat displays is generated from its XML rather than '
                . 'from the appearance streams flattening would place -- the flattened page could differ '
                . 'from the form the user filled in. Construct the FormFlattener with allowXfa: true to '
                . 'flatten it anyway.',
            );
        }

        $fields = $this->select($names);

        $this->refuseSignedSignatures($fields);

        // Found before anything is drawn or removed, so that a refusal
        // leaves the document exactly as it was rather than half
        // flattened, and so that it can name every offending field at
        // once instead of stopping at the first.
        $this->withoutAppearance = $this->blankFields($fields);

        if (!$allowBlankFields && $this->withoutAppearance !== []) {
            throw new FormException($this->blankFieldsMessage());
        }

        $widgets = $this->widgetsByObjectId($fields);

        foreach ($this->tree->pages() as $page) {
            $this->flattenPage($page, $widgets);
        }

        $this->prune($fields);

        $this->flattened = array_keys($fields);
    }

    /**
     * The fields flattened by the last call, in document order.
     *
     * @return list<string>
     */
    public function flattened(): array
    {
        return $this->flattened;
    }

    /**
     * Fields with no appearance stream to place -- the ones that flatten
     * to nothing at all.
     *
     * Populated before anything is written, so it is also readable after
     * flatten() has refused: catch the FormException and ask.
     *
     * @return list<string>
     */
    public function withoutAppearance(): array
    {
        return $this->withoutAppearance;
    }

    /**
     * Which of these fields would draw nothing.
     *
     * A hidden widget does not count: it is displaying nothing already, so
     * flattening it to nothing loses none of what was on the page. A field
     * whose widgets are *all* hidden is therefore not blank, it is absent,
     * and flattening it is exactly right.
     *
     * @param array<string, Field> $fields
     * @return list<string>
     */
    private function blankFields(array $fields): array
    {
        $blank = [];

        foreach ($fields as $name => $field) {
            foreach ($field->widgets as $widget) {
                if (!$this->isHidden($widget) && $this->appearanceOf($widget) === null) {
                    $blank[] = $name;

                    break;
                }
            }
        }

        return $blank;
    }

    private function blankFieldsMessage(): string
    {
        $listed = array_slice($this->withoutAppearance, 0, 10);
        $more = count($this->withoutAppearance) - count($listed);

        return sprintf(
            'These fields have no appearance stream, so flattening would draw nothing where they are '
            . 'and leave blank paper that nothing can distinguish from a field left empty on purpose: '
            . '"%s"%s.%s Pass allowBlankFields: true to flatten them anyway.',
            implode('", "', $listed),
            $more > 0 ? sprintf(' and %d more', $more) : '',
            $this->needsAppearances()
                ? ' The document has /NeedAppearances set, so it is relying on the reader to draw these'
                    . ' -- fill them through FormFiller first, which draws them for real.'
                : '',
        );
    }

    /**
     * @param list<string>|null $names
     * @return array<string, Field>
     */
    private function select(?array $names): array
    {
        $all = $this->form->fields();

        if ($names === null) {
            return $all;
        }

        $selected = [];

        foreach ($names as $name) {
            $selected[$name] = $all[$name]
                ?? throw new FormException("This PDF has no form field named \"$name\" to flatten.");
        }

        return $selected;
    }

    /**
     * A signature field holding a signature is not flattenable: removing
     * it deletes the signature, and drawing its appearance leaves a
     * picture of a signature with nothing behind it -- which is worse than
     * either, being indistinguishable from the real thing on screen.
     *
     * @param array<string, Field> $fields
     */
    private function refuseSignedSignatures(array $fields): void
    {
        foreach ($fields as $name => $field) {
            if ($field->type === FieldType::Signature && $this->editor->resolve($field->dictionary->get('V')) !== null) {
                throw new FormException(
                    "\"$name\" holds a digital signature. Flattening it would delete the signature while "
                    . 'leaving a picture of one on the page, so the document would look signed and no '
                    . 'longer be. Flatten the other fields by name instead.',
                );
            }
        }
    }

    private function needsAppearances(): bool
    {
        $acroForm = $this->editor->resolveDictionary($this->editor->catalog()->get('AcroForm'));
        $flag = $this->editor->resolve($acroForm?->get('NeedAppearances'));

        return $flag instanceof PdfBoolean && $flag->value();
    }

    /**
     * Widget object id => the field it belongs to.
     *
     * Indexed by id rather than followed through each widget's /P because
     * /P is optional (§12.5.2) and missing often enough to matter. The
     * page a widget is on is then whichever page lists it in /Annots,
     * which is the definition rather than a heuristic.
     *
     * @param array<string, Field> $fields
     * @return array<int, string>
     */
    private function widgetsByObjectId(array $fields): array
    {
        $index = [];

        foreach ($fields as $name => $field) {
            foreach ($field->widgets as $widget) {
                if ($widget->hasObjectId()) {
                    $index[$widget->objectId()] = $name;
                }
            }
        }

        return $index;
    }

    /** @param array<int, string> $widgets widget object id => field name */
    private function flattenPage(Dictionary $page, array $widgets): void
    {
        $annotations = $this->editor->resolve($page->get('Annots'));

        if (!$annotations instanceof PdfArray) {
            return;
        }

        $overlay = new PageOverlay($this->editor, $page);
        $operators = new ContentStream();
        $names = [];
        $kept = [];
        $drew = false;

        foreach ($annotations->items() as $entry) {
            $id = $entry instanceof PdfReference ? $entry->objectId() : null;
            $fieldName = $id === null ? null : ($widgets[$id] ?? null);

            if ($fieldName === null) {
                $kept[] = $entry;

                continue;
            }

            $widget = $this->editor->resolveDictionary($entry);

            // A widget that draws nothing -- hidden, or blank and
            // explicitly allowed to be -- is still removed. It has been
            // accounted for in withoutAppearance() already.
            if ($widget !== null && $this->place($widget, $overlay, $operators, $names)) {
                $drew = true;
            }
        }

        if (count($kept) === count($annotations->items())) {
            return;
        }

        // An /Annots that has become empty is removed rather than left as
        // an empty array: the key is optional, and a page carrying an
        // empty one reads as "annotations were considered here", which
        // after flattening is exactly the wrong impression.
        $page->set('Annots', $kept === [] ? null : new PdfArray(...$kept));
        $this->editor->register($page);

        if ($drew) {
            $overlay->content()->drawCustom($operators);
            $overlay->apply();
        }
    }

    /**
     * Places one widget's appearance on the page. Returns whether anything
     * was actually drawn.
     *
     * @param array<int, string> $names appearance object id => resource
     *        name, so a stream shared by several widgets (every radio
     *        button's /Off state, typically) is named once per page
     */
    private function place(
        Dictionary $widget,
        PageOverlay $overlay,
        ContentStream $operators,
        array &$names,
    ): bool {
        if ($this->isHidden($widget)) {
            // Deliberately still removed, just not drawn: a hidden field
            // is one the document chose not to show, and flattening is
            // not the moment to overrule that.
            return false;
        }

        $appearance = $this->appearanceOf($widget);

        if ($appearance === null || !$appearance->hasObjectId()) {
            return false;
        }

        $rect = $this->rectangleOf($widget);
        $bbox = $this->tree->numbers($appearance->get('BBox'));

        if ($rect === null || count($bbox) < 4) {
            // Without both there is no way to say where this belongs.
            // §11.6.6 requires a /BBox, so an appearance without one does
            // not render in a reader either.
            return false;
        }

        $name = $names[$appearance->objectId()] ??= $this->declare($appearance, $overlay, count($names));

        [$a, $b, $c, $d, $e, $f] = $this->placementMatrix($rect, $bbox, $appearance);

        $operators->pushGraphicsState()
            ->concatMatrix($a, $b, $c, $d, $e, $f)
            ->paintXObject($name)
            ->popGraphicsState();

        return true;
    }

    /**
     * The mapping from appearance space to the widget's rectangle
     * (§12.5.5).
     *
     * The appearance's own /Matrix is applied by the reader when the
     * XObject is painted, so it is not repeated here. What is computed is
     * the matrix *outside* that one: the /BBox's four corners are pushed
     * through /Matrix, the axis-aligned box around the result is taken,
     * and this scales and shifts that box onto /Rect. Taking the box
     * around the transformed corners rather than transforming the box's
     * own corner pair is the whole point -- for a rotated /Matrix they are
     * different rectangles.
     *
     * @param list<float> $bbox
     * @return array{float, float, float, float, float, float}
     */
    private function placementMatrix(array $rect, array $bbox, Dictionary $appearance): array
    {
        $matrix = $this->tree->numbers($appearance->get('Matrix'));

        if (count($matrix) < 6) {
            $matrix = [1.0, 0.0, 0.0, 1.0, 0.0, 0.0];
        }

        $xs = [];
        $ys = [];

        foreach ([[$bbox[0], $bbox[1]], [$bbox[2], $bbox[1]], [$bbox[2], $bbox[3]], [$bbox[0], $bbox[3]]] as [$x, $y]) {
            $xs[] = $matrix[0] * $x + $matrix[2] * $y + $matrix[4];
            $ys[] = $matrix[1] * $x + $matrix[3] * $y + $matrix[5];
        }

        $width = max($xs) - min($xs);
        $height = max($ys) - min($ys);

        // A degenerate transformed box cannot be scaled onto anything, and
        // dividing by it would put an INF in the content stream. Drawing
        // it unscaled at least puts the (zero-area) appearance in the
        // right place.
        $sx = $width > 0.0 ? ($rect[2] - $rect[0]) / $width : 1.0;
        $sy = $height > 0.0 ? ($rect[3] - $rect[1]) / $height : 1.0;

        return [$sx, 0.0, 0.0, $sy, $rect[0] - min($xs) * $sx, $rect[1] - min($ys) * $sy];
    }

    /**
     * Names the appearance stream in the overlay's own resources.
     *
     * The stream itself is referenced where it lies rather than copied.
     * If it is missing /Subtype the dictionary is corrected in place --
     * `Do` cannot tell a form from an image without it, so the alternative
     * is a widget that silently fails to paint.
     */
    private function declare(Stream $appearance, PageOverlay $overlay, int $ordinal): string
    {
        $subtype = $this->editor->resolve($appearance->get('Subtype'));

        if (!$subtype instanceof PdfName) {
            $appearance->set('Subtype', new PdfName('Form'));
            $appearance->set('Type', new PdfName('XObject'));
            $this->editor->register($appearance);
        }

        $resources = $overlay->resources();
        $xObjects = $resources->get('XObject');

        if (!$xObjects instanceof Dictionary) {
            $xObjects = new Dictionary();
            $resources->set('XObject', $xObjects);
        }

        $name = self::RESOURCE_PREFIX . $ordinal;
        $xObjects->set($name, new PdfReference($appearance->objectId()));

        return $name;
    }

    /**
     * The stream a reader would display for this widget: /AP /N, and where
     * that holds a set of named states rather than one appearance, the one
     * /AS currently selects.
     */
    private function appearanceOf(Dictionary $widget): ?Stream
    {
        $normal = $this->editor->resolve(
            $this->editor->resolveDictionary($widget->get('AP'))?->get('N'),
        );

        if ($normal instanceof Stream) {
            return $normal;
        }

        if (!$normal instanceof Dictionary) {
            return null;
        }

        $state = $this->editor->resolve($widget->get('AS'));

        if ($state instanceof PdfName) {
            $selected = $this->editor->resolve($normal->get($state->value()));

            return $selected instanceof Stream ? $selected : null;
        }

        // No /AS. With exactly one state there is no ambiguity about what
        // is on screen; with several there is, and guessing would tick
        // boxes nobody ticked.
        $entries = $normal->entries();

        if (count($entries) !== 1) {
            return null;
        }

        $only = $this->editor->resolve(reset($entries) ?: null);

        return $only instanceof Stream ? $only : null;
    }

    /** Table 166: bit 2 is Hidden, bit 6 NoView. Either means not on screen. */
    private function isHidden(Dictionary $widget): bool
    {
        $flags = $this->editor->resolve($widget->get('F'));

        if (!$flags instanceof PdfInteger) {
            return false;
        }

        return ($flags->value() & 2) !== 0 || ($flags->value() & 32) !== 0;
    }

    /**
     * A widget's /Rect, corners the way round the rest of this expects.
     *
     * @return list<float>|null [x0, y0, x1, y1] with x0 <= x1, y0 <= y1
     */
    private function rectangleOf(Dictionary $widget): ?array
    {
        $rect = $this->tree->numbers($widget->get('Rect'));

        if (count($rect) < 4) {
            return null;
        }

        return [
            min($rect[0], $rect[2]),
            min($rect[1], $rect[3]),
            max($rect[0], $rect[2]),
            max($rect[1], $rect[3]),
        ];
    }

    /**
     * Takes the flattened fields out of the form, and the form out of the
     * document once nothing is left in it.
     *
     * @param array<string, Field> $fields
     */
    private function prune(array $fields): void
    {
        $acroForm = $this->editor->resolveDictionary($this->editor->catalog()->get('AcroForm'));

        if ($acroForm === null) {
            return;
        }

        foreach ($fields as $field) {
            if ($field->dictionary->hasObjectId()) {
                $this->removed[$field->dictionary->objectId()] = true;
            }
        }

        foreach ($fields as $field) {
            $this->detach($field->dictionary);
        }

        // Read after detach(), not before: emptying a subtree adds its
        // interior nodes to the set, and one of those may be what /Fields
        // actually lists.
        $roots = $this->editor->resolve($acroForm->get('Fields'));
        $kept = [];

        foreach ($roots instanceof PdfArray ? $roots->items() : [] as $entry) {
            if (!$entry instanceof PdfReference || !isset($this->removed[$entry->objectId()])) {
                $kept[] = $entry;
            }
        }

        if ($kept !== []) {
            $acroForm->set('Fields', new PdfArray(...$kept));
            $this->editor->register($acroForm->hasObjectId() ? $acroForm : $this->editor->catalog());

            return;
        }

        // Nothing fillable left. The /AcroForm goes with it, rather than
        // staying as an empty shell -- a document with a form containing
        // no fields still makes readers show a form toolbar, and still
        // reads as a form to anything inspecting it.
        $this->editor->catalog()->set('AcroForm', null);
        $this->editor->register($this->editor->catalog());
    }

    /**
     * Removes one field from its parent's /Kids, so a flattened leaf of a
     * field tree does not leave a dangling reference behind it.
     */
    private function detach(Dictionary $field): void
    {
        $parent = $this->editor->resolveDictionary($field->get('Parent'));

        if ($parent === null || !$field->hasObjectId()) {
            return;
        }

        $kids = $this->editor->resolve($parent->get('Kids'));

        if (!$kids instanceof PdfArray) {
            return;
        }

        $kept = [];

        foreach ($kids->items() as $entry) {
            if (!$entry instanceof PdfReference || !isset($this->removed[$entry->objectId()])) {
                $kept[] = $entry;
            }
        }

        if (count($kept) === count($kids->items())) {
            return;
        }

        $parent->set('Kids', $kept === [] ? null : new PdfArray(...$kept));

        if ($parent->hasObjectId()) {
            $this->editor->register($parent);
        }

        // A parent left with no kids is now a field with no widgets and no
        // children -- nothing can fill it and nothing displays it -- so it
        // is removed in turn, up as far as the tree is empty. The guard is
        // the recorded set itself: a node already in it has been here.
        if ($kept === [] && $parent->hasObjectId() && !isset($this->removed[$parent->objectId()])) {
            $this->removed[$parent->objectId()] = true;
            $this->detach($parent);
        }
    }
}
