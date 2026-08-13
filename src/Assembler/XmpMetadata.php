<?php

declare(strict_types=1);

namespace MightyPDF\Assembler;

use MightyPDF\Assembler\Types\PdfName;

/**
 * The document's XMP packet (ISO 32000-2 §14.3.2) -- the same metadata the
 * /Info dictionary carries, stated again in RDF/XML because that is the
 * form the rest of the world reads.
 *
 * Both exist for historical reasons and neither replaces the other: /Info
 * is what a PDF reader shows in its properties box, and XMP is what asset
 * managers, search indexes, print workflows and every conformance level
 * above plain PDF look at. A file with only /Info is invisible to the
 * second group.
 *
 * **The packet is generated from DocumentInfo rather than set beside it.**
 * Two hand-maintained copies of the same six fields disagree eventually,
 * and a document whose /Info says one title and whose XMP says another is
 * worse than one that only says it once -- which of the two a given tool
 * believes is not something the document gets to decide. So the flow is
 * one way: set metadata through info(), and this restates it. Anything
 * with no /Info equivalent (dc:rights, the asset ids) is set here and
 * lives only here.
 *
 * A caller with a complete packet of its own -- a Factur-X profile, a
 * PDF/A identification block -- can hand it over whole with setPacket(),
 * and then nothing is generated and nothing is checked.
 *
 * The stream is deliberately **uncompressed**: §14.3.2 exists so that a
 * consumer can find this without understanding the rest of the file, and
 * a deflated packet defeats half of that on its own.
 *
 * Whether it is *encrypted* is the document's decision rather than this
 * class's, and the default is that it is, along with everything else --
 * see Document::encrypt()'s $encryptMetadata, which is what completes the
 * thought for a document that would rather stay indexable. Passing false
 * there is what exempts the packet, and the reader honours the same
 * exemption from the other side in CryptTransform::isNeverEncrypted().
 */
final class XmpMetadata
{
    /**
     * The fixed packet id from the XMP specification. It is not a document
     * identifier and is the same in every XMP packet ever written -- it is
     * there so a scanner can recognise the packet in a byte stream.
     */
    private const string PACKET_ID = 'W5M0MpCehiHzreSzNTczkc9d';

    private readonly Stream $stream;

    private ?string $packet = null;

    private ?string $rights = null;
    private ?string $documentId = null;
    private ?string $instanceId = null;

    public function __construct(int $objectId)
    {
        // compress: false -- see the class comment. Composed rather than
        // extended because Stream is final; the packet is a stream, but
        // building one is a job of its own.
        $this->stream = new Stream($objectId, '', false);

        $this->stream->set('Type', new PdfName('Metadata'));
        $this->stream->set('Subtype', new PdfName('XML'));
    }

    /** The object to register and reference -- see Document::metadata(). */
    public function stream(): Stream
    {
        return $this->stream;
    }

    public function objectId(): int
    {
        return $this->stream->objectId();
    }

    /**
     * The copyright statement (dc:rights). No /Info equivalent, so this is
     * the only place a document can say it in a form anything reads.
     */
    public function setRights(string $rights): static
    {
        $this->rights = $rights;

        return $this;
    }

    /**
     * xmpMM:DocumentID -- the identity of the *work*, which stays the same
     * across every version and rendition of it.
     *
     * Not invented here. An id this library made up per save would change
     * every time the document was rebuilt, which is precisely backwards:
     * a caller that has a stable identity for the thing being produced
     * should say so, and one that has not is better off saying nothing.
     */
    public function setDocumentId(string $id): static
    {
        $this->documentId = $id;

        return $this;
    }

    /** xmpMM:InstanceID -- the identity of this particular saved file. */
    public function setInstanceId(string $id): static
    {
        $this->instanceId = $id;

        return $this;
    }

    /**
     * Replaces the packet outright with one the caller has built.
     *
     * Nothing is generated afterwards and nothing is validated: a caller
     * supplying a whole packet is doing so because they need something
     * this does not generate, and quietly merging fields into it would
     * produce a packet neither party wrote.
     */
    public function setPacket(string $xml): static
    {
        $this->packet = $xml;
        $this->stream->replaceBytes($xml);

        return $this;
    }

    /**
     * Regenerates the packet from the document's /Info.
     *
     * Called at save, so that metadata set after metadata() was first
     * asked for still arrives -- the order those two happen in is the
     * caller's business and should not change the file.
     */
    public function buildFrom(?DocumentInfo $info): void
    {
        if ($this->packet !== null) {
            return;
        }

        $this->stream->replaceBytes($this->generate($info));
    }

    private function generate(?DocumentInfo $info): string
    {
        $dublinCore = implode('', [
            self::alternative('dc:title', $info?->text('Title')),
            self::sequence('dc:creator', $info?->text('Author')),
            self::alternative('dc:description', $info?->text('Subject')),
            self::alternative('dc:rights', $this->rights),
        ]);

        $pdf = implode('', [
            self::simple('pdf:Keywords', $info?->text('Keywords')),
            self::simple('pdf:Producer', $info?->text('Producer')),
        ]);

        $xmp = implode('', [
            self::simple('xmp:CreatorTool', $info?->text('Creator')),
            self::simple('xmp:CreateDate', self::iso8601($info?->creationDate())),
            self::simple('xmp:ModifyDate', self::iso8601($info?->modificationDate())),
        ]);

        $media = implode('', [
            self::simple('xmpMM:DocumentID', $this->documentId),
            self::simple('xmpMM:InstanceID', $this->instanceId),
        ]);

        // The trailing padding is conventional and load-bearing for one
        // narrow case: a tool rewriting metadata in place needs somewhere
        // to put a longer packet without moving every byte after it.
        // "w" says the packet may be written to; 2 KB is the usual amount.
        return "<?xpacket begin=\"\u{feff}\" id=\"" . self::PACKET_ID . "\"?>\n"
            . "<x:xmpmeta xmlns:x=\"adobe:ns:meta/\">\n"
            . " <rdf:RDF xmlns:rdf=\"http://www.w3.org/1999/02/22-rdf-syntax-ns#\">\n"
            . "  <rdf:Description rdf:about=\"\"\n"
            . "   xmlns:dc=\"http://purl.org/dc/elements/1.1/\"\n"
            . "   xmlns:pdf=\"http://ns.adobe.com/pdf/1.3/\"\n"
            . "   xmlns:xmp=\"http://ns.adobe.com/xap/1.0/\"\n"
            . "   xmlns:xmpMM=\"http://ns.adobe.com/xap/1.0/mm/\">\n"
            . $dublinCore . $pdf . $xmp . $media
            . "  </rdf:Description>\n"
            . " </rdf:RDF>\n"
            . "</x:xmpmeta>\n"
            . str_repeat(str_repeat(' ', 79) . "\n", 25)
            . "<?xpacket end=\"w\"?>\n";
    }

    /** A plain property: one value, no language and no ordering. */
    private static function simple(string $property, ?string $value): string
    {
        if ($value === null || $value === '') {
            return '';
        }

        return sprintf("   <%s>%s</%s>\n", $property, self::escape($value), $property);
    }

    /**
     * A language alternative (rdf:Alt), which is how XMP spells "text a
     * human reads" -- dc:title and dc:description are both of these, and a
     * bare string there is read by some tools and not others.
     */
    private static function alternative(string $property, ?string $value): string
    {
        if ($value === null || $value === '') {
            return '';
        }

        return sprintf(
            "   <%s>\n    <rdf:Alt>\n     <rdf:li xml:lang=\"x-default\">%s</rdf:li>\n    </rdf:Alt>\n   </%s>\n",
            $property,
            self::escape($value),
            $property,
        );
    }

    /**
     * An ordered list (rdf:Seq). dc:creator is one of these because a
     * document has any number of authors and their order is meaningful --
     * /Author being a single string is the older, flatter idea, so the one
     * string becomes the one entry.
     */
    private static function sequence(string $property, ?string $value): string
    {
        if ($value === null || $value === '') {
            return '';
        }

        return sprintf(
            "   <%s>\n    <rdf:Seq>\n     <rdf:li>%s</rdf:li>\n    </rdf:Seq>\n   </%s>\n",
            $property,
            self::escape($value),
            $property,
        );
    }

    private static function iso8601(?\DateTimeInterface $date): ?string
    {
        if ($date === null) {
            return null;
        }

        // XMP dates are ISO 8601, and "Z" rather than "+00:00" for UTC --
        // both are legal, and Z is what every other producer writes.
        $offset = $date->format('P');

        return $date->format('Y-m-d\TH:i:s') . ($offset === '+00:00' ? 'Z' : $offset);
    }

    private static function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    }
}
