<?php

declare(strict_types=1);

namespace MightyPDF\Assembler\Annotation;

use MightyPDF\Assembler\Attachment\FileSpecification;
use MightyPDF\Assembler\Dictionary;
use MightyPDF\Assembler\Types\PdfInteger;
use MightyPDF\Assembler\Types\PdfName;
use MightyPDF\Assembler\Types\PdfRectangle;
use MightyPDF\Assembler\Types\PdfReference;
use MightyPDF\Assembler\Types\PdfString;

/**
 * The visible half of an attachment (ISO 32000-2 §12.5.6.15): a mark on
 * the page that opens a file carried inside the document.
 *
 * Document::attach() puts a file in the attachments panel, which is where
 * a machine-readable companion belongs and where a person will never look
 * for it. This puts it on the page, next to whatever it relates to -- the
 * working behind a figure, the certificate behind a claim.
 *
 * The two are one file, not two. This takes the FileSpecification that
 * attach() returned rather than bytes of its own, so the annotation and
 * the panel entry point at the same embedded stream.
 *
 * **The icon is drawn by the reader**, from the /Name given here, and the
 * four names below are the ones the spec defines. A reader is entitled to
 * draw them however it likes and they do differ noticeably between
 * readers -- so the rectangle is a hint at the size rather than a
 * guarantee of it.
 */
final class FileAttachmentAnnotation extends Dictionary
{
    /** Annotation flag bit 3, "Print" -- the same reasoning as LinkAnnotation's. */
    private const int FLAG_PRINT = 4;

    public function __construct(
        int $objectId,
        PdfRectangle $rect,
        FileSpecification $file,
        AttachmentIcon $icon = AttachmentIcon::PushPin,
        ?string $note = null,
    ) {
        parent::__construct($objectId);

        $this->set('Type', new PdfName('Annot'));
        $this->set('Subtype', new PdfName('FileAttachment'));
        $this->set('Rect', $rect);
        $this->set('F', new PdfInteger(self::FLAG_PRINT));
        $this->set('FS', new PdfReference($file->objectId()));
        $this->set('Name', new PdfName($icon->value));

        // The tooltip. Falls back to the filename, since an icon with no
        // hover text is an icon nobody knows to click.
        $this->set('Contents', PdfString::text($note ?? $file->name()));
    }
}
