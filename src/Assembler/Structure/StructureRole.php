<?php

declare(strict_types=1);

namespace MightyPDF\Assembler\Structure;

/**
 * What a piece of content *is*, as the standard structure types name it
 * (ISO 32000-2 §14.8.4).
 *
 * This is the whole point of tagging. A PDF page says where marks go; it
 * says nothing about whether a line of 18pt text is a heading, a caption
 * or a shouted sentence. A screen reader, a reflow engine and an
 * export-to-Word converter all need to know which, and none of them can
 * work it out from the font size -- so the producer, which does know, has
 * to say.
 *
 * An enum rather than free strings because a mistyped tag is not an error
 * anywhere: it is a valid non-standard structure type, and it silently
 * means nothing to every consumer. Anything genuinely outside this list
 * belongs in a /RoleMap, which is the mechanism the standard provides for
 * exactly that.
 */
enum StructureRole: string
{
    // -- Grouping ------------------------------------------------------

    /** The root of a document's structure. */
    case Document = 'Document';

    /** A self-contained part of a document. */
    case Part = 'Part';

    /** A division: the usual generic container. */
    case Division = 'Div';

    /** A section, which is a division that has a heading. */
    case Section = 'Sect';

    /** A quotation set off from the surrounding text. */
    case BlockQuote = 'BlockQuote';

    /** A caption, belonging to whatever it sits beside. */
    case Caption = 'Caption';

    /** A table of contents, and one entry in one. */
    case TableOfContents = 'TOC';
    case TableOfContentsItem = 'TOCI';

    /** An index. */
    case Index = 'Index';

    // -- Block level ---------------------------------------------------

    /** A paragraph. The default for a run of prose. */
    case Paragraph = 'P';

    case Heading = 'H';
    case Heading1 = 'H1';
    case Heading2 = 'H2';
    case Heading3 = 'H3';
    case Heading4 = 'H4';
    case Heading5 = 'H5';
    case Heading6 = 'H6';

    /** A list, one of its items, the item's label, and the item's body. */
    case ListElement = 'L';
    case ListItem = 'LI';
    case Label = 'Lbl';
    case ListBody = 'LBody';

    /** A table, a row, a header cell and a data cell. */
    case Table = 'Table';
    case TableRow = 'TR';
    case TableHeader = 'TH';
    case TableData = 'TD';

    /** The head, body and foot of a table, for a table that has them. */
    case TableHead = 'THead';
    case TableBody = 'TBody';
    case TableFoot = 'TFoot';

    // -- Inline --------------------------------------------------------

    /** A span of text with no meaning beyond being distinguished. */
    case Span = 'Span';

    /** A quotation inside a sentence. */
    case Quote = 'Quote';

    /** A note, and a reference to one. */
    case Note = 'Note';
    case Reference = 'Reference';

    /** A link's content. The link annotation itself is attached to this. */
    case Link = 'Link';

    /** A form field's content. */
    case Form = 'Form';

    // -- Illustration --------------------------------------------------

    /**
     * A figure. Needs alternate text: a figure with no /Alt is the single
     * most common accessibility failure there is, and the one a checker
     * reports first.
     */
    case Figure = 'Figure';

    case Formula = 'Formula';

    /**
     * The heading level, for a role that has one, or null.
     *
     * Used to check that headings descend by one at a time -- see
     * StructureElement, where skipping a level is refused. A document
     * whose headings go H1, H3 is one whose outline is wrong in every
     * tool that builds one from the structure.
     */
    public function headingLevel(): ?int
    {
        return match ($this) {
            self::Heading1 => 1,
            self::Heading2 => 2,
            self::Heading3 => 3,
            self::Heading4 => 4,
            self::Heading5 => 5,
            self::Heading6 => 6,
            default => null,
        };
    }

    public function isHeading(): bool
    {
        return $this === self::Heading || $this->headingLevel() !== null;
    }

    /**
     * Whether this role describes content directly, as opposed to
     * grouping other structure.
     *
     * A grouping element with marked content directly inside it is not
     * illegal, but it is nearly always a mistake -- text that belongs to
     * a paragraph attached to the section instead.
     */
    public function isGrouping(): bool
    {
        return match ($this) {
            self::Document, self::Part, self::Division, self::Section,
            self::TableOfContents, self::Index, self::ListElement, self::ListItem,
            self::Table, self::TableRow, self::TableHead, self::TableBody, self::TableFoot => true,
            default => false,
        };
    }
}
