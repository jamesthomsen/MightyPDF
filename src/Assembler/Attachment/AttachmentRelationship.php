<?php

declare(strict_types=1);

namespace MightyPDF\Assembler\Attachment;

/**
 * What an attached file has to do with the document it arrived in
 * (ISO 32000-2 §14.13, /AFRelationship).
 *
 * Worth stating rather than leaving off. An invoice PDF with an XML file
 * inside it is one invoice in two forms, and a consumer has to be able to
 * tell that from "here is an unrelated spreadsheet somebody stapled on".
 * A filename cannot carry that claim; this can, and the EU e-invoicing
 * formats -- Factur-X, ZUGFeRD, XRechnung -- are built on it saying
 * Data.
 */
enum AttachmentRelationship: string
{
    /** The attachment *is* the document, in another form: the machine-readable half of an e-invoice. */
    case Data = 'Data';

    /** The document was generated from the attachment -- a report from its dataset. */
    case Source = 'Source';

    /** Evidence for what the document says: a certificate, a signed original. */
    case Alternative = 'Alternative';

    /** A supplement: appendices, workings, the things that did not fit. */
    case Supplement = 'Supplement';

    /** No claim made. The default, and the entry is then left out entirely. */
    case Unspecified = 'Unspecified';
}
