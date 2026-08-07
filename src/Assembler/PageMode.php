<?php

declare(strict_types=1);

namespace MightyPDF\Assembler;

/**
 * Which panel a reader shows alongside the page when the document opens
 * (ISO 32000-2 §12.2, /PageMode).
 *
 * The default is none, which is why Document::outline() asks for
 * UseOutlines: a document with bookmarks wants them seen, and an outline
 * nobody notices is the same as no outline for most of the people who
 * open the file.
 */
enum PageMode: string
{
    /** No panel. The reader's own default. */
    case None = 'UseNone';

    /** The bookmark panel. */
    case Outlines = 'UseOutlines';

    /** Page thumbnails. */
    case Thumbnails = 'UseThumbs';

    /** Full screen, with no chrome at all -- a presentation. */
    case FullScreen = 'FullScreen';

    /** The optional-content panel. */
    case OptionalContent = 'UseOC';

    /** The attachments panel -- worth asking for on a document whose attachment is the point. */
    case Attachments = 'UseAttachments';
}
