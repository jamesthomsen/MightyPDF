<?php

declare(strict_types=1);

namespace MightyPDF\Assembler\Annotation;

/**
 * Which icon a reader draws for a file attachment on the page
 * (ISO 32000-2 §12.5.6.15, /Name).
 *
 * These four are the ones the spec defines. What each actually looks like
 * is the reader's business and they differ between readers, so treat the
 * choice as a hint about the kind of thing attached rather than as a
 * picture you are placing.
 */
enum AttachmentIcon: string
{
    /** The default, and the one every reader draws recognisably. */
    case PushPin = 'PushPin';

    case Paperclip = 'Paperclip';

    case Graph = 'Graph';

    case Tag = 'Tag';
}
