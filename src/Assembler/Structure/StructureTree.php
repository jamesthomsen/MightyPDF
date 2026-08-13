<?php

declare(strict_types=1);

namespace MightyPDF\Assembler\Structure;

use MightyPDF\Assembler\Dictionary;
use MightyPDF\Assembler\ObjectHost;
use MightyPDF\Assembler\Types\PdfArray;
use MightyPDF\Assembler\Types\PdfInteger;
use MightyPDF\Assembler\Types\PdfName;
use MightyPDF\Assembler\Types\PdfNull;
use MightyPDF\Assembler\Types\PdfReference;

/**
 * The root of a document's logical structure (ISO 32000-2 §14.7.2) -- what
 * makes a PDF *tagged*, and with it readable by a screen reader,
 * reflowable on a small screen, and convertible to something else without
 * guesswork.
 *
 * A tagged PDF says two things a plain one does not: what each piece of
 * content is (see StructureRole), and what order the pieces are meant to
 * be read in. Neither is recoverable from the page: text is drawn wherever
 * the producer put it, in whatever order suited the producer, and a
 * two-column page drawn column-by-column reads as two columns to a person
 * and as interleaved nonsense to anything working from the stream.
 *
 * **The /ParentTree is the part that is easy to get wrong.** The structure
 * points *down* at page content by marked-content id; the parent tree is
 * the index that lets a reader go back *up*, from a mark on a page to the
 * element it belongs to. Both directions are required, and a tree with a
 * correct structure and a missing or misnumbered parent tree is one that
 * validators reject and assistive technology ignores -- while looking
 * completely correct in a viewer. It is built here rather than left to the
 * caller for exactly that reason.
 */
final class StructureTree extends Dictionary
{
    /**
     * Every element with marked content, grouped by the /StructParents
     * index of the page it is on.
     *
     * @var array<int, array<int, int>> struct-parents index => MCID =>
     *      the element's object id
     */
    private array $parents = [];

    /** @var list<int> the root's children, in reading order */
    private array $children = [];

    private ?int $lastHeadingLevel = null;

    private ?Dictionary $roleMap = null;

    private ?StructureElement $root = null;

    public function __construct(int $objectId, private readonly ObjectHost $document)
    {
        parent::__construct($objectId);

        $this->set('Type', new PdfName('StructTreeRoot'));
    }

    /**
     * The document's top-level element, created on first use.
     *
     * §14.8.4.3 wants a single /Document element at the top, and a
     * structure whose root children are a run of paragraphs is one every
     * checker complains about.
     */
    public function document(): StructureElement
    {
        return $this->root ??= $this->addRoot(StructureRole::Document);
    }

    /** Adds a child of the structure root itself. */
    public function addRoot(StructureRole $role): StructureElement
    {
        $element = $this->element($role, null);

        $this->children[] = $element->objectId();
        $this->set('K', count($this->children) === 1
            ? new PdfReference($this->children[0])
            : new PdfArray(...array_map(
                static fn (int $id): PdfReference => new PdfReference($id),
                $this->children,
            )));

        return $element;
    }

    /**
     * Builds an element and registers it. Called by StructureElement when
     * it makes a child, and by addRoot().
     */
    public function element(StructureRole $role, ?StructureElement $parent): StructureElement
    {
        $element = new StructureElement($this->document->allocate(), $role, $this, $parent);

        $this->document->register($element);

        if ($role->headingLevel() !== null) {
            $this->lastHeadingLevel = $role->headingLevel();
        }

        return $element;
    }

    public function lastHeadingLevel(): ?int
    {
        return $this->lastHeadingLevel;
    }

    /**
     * The next free /StructParents index.
     *
     * Handed out from here rather than counted per page, because the
     * numbers have to be unique across the whole document -- they are the
     * keys of one parent tree, and two pages sharing one means the marks
     * of the second are attributed to the elements of the first.
     */
    public function nextStructParents(): int
    {
        return $this->nextStructParents++;
    }

    private int $nextStructParents = 0;

    /**
     * Records that a mark on a page belongs to an element, which is what
     * the parent tree is built from.
     */
    public function recordMark(int $structParents, int $mcid, StructureElement $element): void
    {
        $this->parents[$structParents][$mcid] = $element->objectId();
    }

    /**
     * Maps a role this library does not know onto one a reader does.
     *
     * The escape hatch §14.8.4 provides for a producer with its own
     * vocabulary. Nothing here needs it, but a caller building structure
     * for a domain that names things its own way does.
     */
    public function mapRole(string $custom, StructureRole $standard): static
    {
        $this->roleMap ??= new Dictionary();
        $this->roleMap->set($custom, new PdfName($standard->value));
        $this->set('RoleMap', $this->roleMap);

        return $this;
    }

    /**
     * Builds the /ParentTree. Called once, when the document is saved,
     * because it describes every mark made anywhere and nothing is known
     * to be the last until then.
     */
    public function finish(): void
    {
        if ($this->parents === []) {
            return;
        }

        $keys = array_keys($this->parents);
        sort($keys);

        $nums = [];

        foreach ($keys as $index) {
            $marks = $this->parents[$index];
            ksort($marks);

            // The entry for a page is an array indexed *by MCID*, so a
            // gap in the numbering has to be a real gap rather than a
            // shorter array -- otherwise every mark after it is attributed
            // to the wrong element.
            $highest = max(array_keys($marks));
            $items = [];

            for ($mcid = 0; $mcid <= $highest; ++$mcid) {
                $items[] = isset($marks[$mcid])
                    ? new PdfReference($marks[$mcid])
                    : new PdfNull();
            }

            // Written directly rather than as an object of its own.
            // §7.9.7 allows a number tree's value to be any object, and
            // an indirect array here would be one more object per page
            // for no reader's benefit.
            $nums[] = new PdfInteger($index);
            $nums[] = new PdfArray(...$items);
        }

        $tree = new Dictionary();
        $tree->set('Nums', new PdfArray(...$nums));

        $this->set('ParentTree', $tree);
        $this->set('ParentTreeNextKey', new PdfInteger(max($keys) + 1));
    }
}
