<?php

declare(strict_types=1);

namespace MightyPDF\Assembler\Structure;

use MightyPDF\Assembler\Dictionary;
use MightyPDF\Assembler\Types\PdfArray;
use MightyPDF\Assembler\Types\PdfInteger;
use MightyPDF\Assembler\Types\PdfName;
use MightyPDF\Assembler\Types\PdfReference;
use MightyPDF\Assembler\Types\PdfString;
use MightyPDF\Assembler\Types\PdfValue;

/**
 * One node of a document's structure tree (ISO 32000-2 §14.7.2) -- a
 * heading, a paragraph, a table cell, a figure.
 *
 * An element's /K holds its children, and those come in two kinds that
 * sit side by side in one list: other elements, and *marked-content
 * identifiers* pointing at the actual marks on a page. So a paragraph
 * spanning a page break is one element with two MCIDs on two pages, and a
 * section is one element whose children are all elements. That mixture is
 * the thing to understand about the structure tree: it is a document
 * outline and a pointer into the page streams at the same time.
 */
final class StructureElement extends Dictionary
{
    /** @var list<PdfValue> the /K children, elements and MCIDs together */
    private array $children = [];

    /**
     * Which page this element's marked content is on.
     *
     * §14.7.2 requires /Pg on any element whose content is on a page other
     * than its parent's -- and getting it wrong is not a rendering fault,
     * it is a screen reader reading the wrong page.
     */
    private ?int $pageObjectId = null;

    public function __construct(
        int $objectId,
        public readonly StructureRole $role,
        private readonly StructureTree $tree,
        private readonly ?self $parent = null,
    ) {
        parent::__construct($objectId);

        $this->set('Type', new PdfName('StructElem'));
        $this->set('S', new PdfName($role->value));

        if ($parent !== null) {
            $this->set('P', new PdfReference($parent->objectId()));
        }
    }

    /**
     * Adds a child element of the given role.
     *
     * Heading levels are checked here rather than at save: a document
     * whose headings jump from H1 to H3 has an outline that every tool
     * building one from the structure gets wrong, and the moment to say so
     * is while the caller still knows what they meant.
     */
    public function child(StructureRole $role): self
    {
        $this->refuseSkippedHeadingLevel($role);

        $child = $this->tree->element($role, $this);

        $this->children[] = new PdfReference($child->objectId());
        $this->rebuild();

        return $child;
    }

    /**
     * Attaches a run of marked content on a page to this element.
     *
     * Called by the content layer as it draws; see
     * PageBuilder::tagged(), which is where the matching BDC/EMC pair is
     * written.
     */
    public function addMarkedContent(int $mcid, int $pageObjectId): void
    {
        if ($this->pageObjectId === null) {
            $this->pageObjectId = $pageObjectId;
            $this->set('Pg', new PdfReference($pageObjectId));
        }

        $this->children[] = $this->pageObjectId === $pageObjectId
            ? new PdfInteger($mcid)
            // Content on a second page needs to say which page it is on,
            // which an MCID alone cannot. §14.7.4.2's marked-content
            // reference is the form that can.
            : (new Dictionary())
                ->set('Type', new PdfName('MCR'))
                ->set('Pg', new PdfReference($pageObjectId))
                ->set('MCID', new PdfInteger($mcid));

        $this->rebuild();
    }

    /**
     * Attaches an annotation -- a link, a form field's widget -- to this
     * element.
     *
     * Without this a link is a rectangle a screen reader announces with no
     * idea what it is for, because the text it covers belongs to the page
     * and the annotation belongs to nothing.
     */
    public function addAnnotation(int $annotationObjectId, int $pageObjectId): void
    {
        $this->children[] = (new Dictionary())
            ->set('Type', new PdfName('OBJR'))
            ->set('Pg', new PdfReference($pageObjectId))
            ->set('Obj', new PdfReference($annotationObjectId));

        $this->rebuild();
    }

    /**
     * Text standing in for content that is not text: what a screen reader
     * says instead of a figure.
     *
     * A figure without it is the commonest accessibility failure there is,
     * and the first thing any checker reports.
     */
    public function setAlternateText(string $text): static
    {
        $this->set('Alt', PdfString::text($text));

        return $this;
    }

    /**
     * The text this content *actually is*, where what was drawn does not
     * spell it: a drop cap drawn separately from the rest of its word, a
     * ligature, a logo standing in for a company name.
     *
     * Distinct from /Alt: alternate text describes, actual text replaces.
     * A screen reader reads /ActualText as though it were the content.
     */
    public function setActualText(string $text): static
    {
        $this->set('ActualText', PdfString::text($text));

        return $this;
    }

    /** A language for this element, where it differs from the document's. */
    public function setLanguage(string $language): static
    {
        $this->set('Lang', PdfString::text($language));

        return $this;
    }

    /** A short title, shown by tools that display the structure tree. */
    public function setTitle(string $title): static
    {
        $this->set('T', PdfString::text($title));

        return $this;
    }

    public function parent(): ?self
    {
        return $this->parent;
    }

    /** Whether anything at all has been attached to this element. */
    public function isEmpty(): bool
    {
        return $this->children === [];
    }

    private function refuseSkippedHeadingLevel(StructureRole $role): void
    {
        $level = $role->headingLevel();

        if ($level === null || $level === 1) {
            return;
        }

        $previous = $this->tree->lastHeadingLevel();

        if ($previous !== null && $level <= $previous + 1) {
            return;
        }

        throw new \LogicException(sprintf(
            'This document\'s headings would go from %s to H%d. A heading level may only descend one '
            . 'at a time (§14.8.4.7): a document that skips one has an outline that every tool building '
            . 'one from the structure gets wrong, and no way to tell what the missing level was.',
            $previous === null ? 'nothing' : "H$previous",
            $level,
        ));
    }

    /**
     * /K is rebuilt rather than appended to, because its shape depends on
     * how many children there are: one child is written directly and
     * several go in an array (§14.7.2 permits both, and readers that
     * accept only the array form are rarer than ones that trip over a
     * single-element array where a dictionary was expected).
     */
    private function rebuild(): void
    {
        $this->set('K', match (count($this->children)) {
            0 => null,
            1 => $this->children[0],
            default => new PdfArray(...$this->children),
        });
    }
}
