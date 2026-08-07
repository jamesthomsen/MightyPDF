<?php

declare(strict_types=1);

namespace MightyPDF\Assembler;

/**
 * How a reader arranges the pages (ISO 32000-2 §12.2, /PageLayout).
 *
 * The two-up layouts are the ones worth asking for: a document laid out
 * as facing pages -- a report with spreads, a booklet -- reads wrongly as
 * a single column, and the reader has no way to know that from the pages
 * themselves.
 */
enum PageLayout: string
{
    /** One page at a time. The reader's own default. */
    case SinglePage = 'SinglePage';

    /** One column, scrolling continuously. */
    case OneColumn = 'OneColumn';

    /** Two columns, odd-numbered pages on the left. */
    case TwoColumnLeft = 'TwoColumnLeft';

    /** Two columns, odd-numbered pages on the right -- how a bound book falls open. */
    case TwoColumnRight = 'TwoColumnRight';

    /** Facing pages, one spread at a time, odd on the left. */
    case TwoPageLeft = 'TwoPageLeft';

    /** Facing pages, odd on the right. */
    case TwoPageRight = 'TwoPageRight';
}
