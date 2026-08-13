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
    /**
     * The dates as they were handed over, kept alongside the formatted
     * strings in the dictionary.
     *
     * XMP states the same two dates in ISO 8601 (see XmpMetadata), and
     * recovering a \DateTimeInterface by parsing "D:20260807120000+01'00'"
     * back out of the dictionary would be a lossy round trip through a
     * format that exists to be written rather than read. Keeping the
     * original costs two properties and means the two never disagree.
     */
    private ?\DateTimeInterface $creationDate = null;
    private ?\DateTimeInterface $modificationDate = null;

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
        $this->creationDate = $date;
        $this->set('CreationDate', PdfString::latin1(self::formatDate($date)));
    }

    /** /ModDate, in the same format -- when the document was last changed. */
    public function setModificationDate(\DateTimeInterface $date): void
    {
        $this->modificationDate = $date;
        $this->set('ModDate', PdfString::latin1(self::formatDate($date)));
    }

    public function creationDate(): ?\DateTimeInterface
    {
        return $this->creationDate;
    }

    public function modificationDate(): ?\DateTimeInterface
    {
        return $this->modificationDate;
    }

    /**
     * One of the text entries as plain UTF-8, or null if it was never set.
     *
     * The dictionary holds PdfStrings, which may be UTF-16BE with a BOM;
     * anything restating this information elsewhere (XMP) needs the text
     * rather than the encoding it was written in.
     */
    public function text(string $key): ?string
    {
        $value = $this->get($key);

        return $value instanceof PdfString ? $value->toUtf8() : null;
    }

    private static function formatDate(\DateTimeInterface $date): string
    {
        $offset = $date->format('P');
        $suffix = $offset === '+00:00' ? 'Z' : str_replace(':', "'", $offset) . "'";

        return 'D:' . $date->format('YmdHis') . $suffix;
    }
}
