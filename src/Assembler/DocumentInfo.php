<?php

declare(strict_types=1);

namespace MightyPDF\Assembler;

use MightyPDF\Assembler\Types\PdfString;

/**
 * The document information dictionary (ISO 32000-2 §14.3.3), referenced
 * from the trailer's /Info entry. Unlike Catalog/AcroForm, it carries no
 * /Type of its own -- the spec doesn't give it one.
 *
 * Every setter goes through PdfString::text(), the same "ASCII as-is,
 * anything else as UTF-16BE" choice already used for form field names and
 * values -- these are PDF's "text string" type too, so the same rule about
 * not silently mangling non-Latin1 text applies here.
 */
final class DocumentInfo extends Dictionary
{
    public function setTitle(string $title): void
    {
        $this->set('Title', PdfString::text($title));
    }

    public function setAuthor(string $author): void
    {
        $this->set('Author', PdfString::text($author));
    }

    public function setSubject(string $subject): void
    {
        $this->set('Subject', PdfString::text($subject));
    }

    public function setKeywords(string $keywords): void
    {
        $this->set('Keywords', PdfString::text($keywords));
    }

    public function setCreator(string $creator): void
    {
        $this->set('Creator', PdfString::text($creator));
    }

    public function setProducer(string $producer): void
    {
        $this->set('Producer', PdfString::text($producer));
    }

    /**
     * /CreationDate, as "D:YYYYMMDDHHmmSSOHH'mm'" (ISO 32000-2 §7.9.4) --
     * always plain ASCII, so this goes through PdfString::latin1() rather
     * than text(), for the same reason a UTF-16BE BOM would never belong
     * here.
     */
    public function setCreationDate(\DateTimeInterface $date): void
    {
        $this->set('CreationDate', PdfString::latin1(self::formatDate($date)));
    }

    private static function formatDate(\DateTimeInterface $date): string
    {
        $offset = $date->format('P');
        $suffix = $offset === '+00:00' ? 'Z' : str_replace(':', "'", $offset) . "'";

        return 'D:' . $date->format('YmdHis') . $suffix;
    }
}
