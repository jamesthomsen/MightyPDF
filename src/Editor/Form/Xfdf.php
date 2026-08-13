<?php

declare(strict_types=1);

namespace MightyPDF\Editor\Form;

/**
 * Form data on its own, as XFDF (ISO 19444-1) -- the values without the
 * document they belong to.
 *
 * The other half of FormFiller. Filling a form is only half of what
 * anyone does with one: the values have to come from somewhere and
 * usually have to go somewhere afterwards, and XFDF is the format both
 * ends of that already speak. Acrobat's "Export Data" writes it, its
 * "Import Data" reads it, and it is the format a web front end submits
 * when a form's /SubmitForm action asks for one.
 *
 * ```php
 * $filler = new FormFiller($editor);
 *
 * file_put_contents('data.xfdf', $filler->toXfdf('invoice.pdf'));
 * $filler->fill(Xfdf::parse(file_get_contents('data.xfdf')));
 * ```
 *
 * Field names nest. A form's "address.city" is one field with a dotted
 * full name, and XFDF writes it as a <field name="city"> inside a
 * <field name="address">; both directions here deal in the flat dotted
 * names FormFiller uses, and do the nesting on the way in and out.
 */
final class Xfdf
{
    private const string NAMESPACE_URI = 'http://ns.adobe.com/xfdf/';

    /**
     * @param array<string, string|null> $values keyed by full field name,
     *        as FormFiller::values() returns them. A null is a field with
     *        no value, and is written as an empty one -- XFDF has no way
     *        to say "unset", and leaving the field out entirely would mean
     *        an import could not clear it.
     */
    public static function export(array $values, ?string $sourceFile = null): string
    {
        $document = new \DOMDocument('1.0', 'UTF-8');
        $document->formatOutput = true;

        $root = $document->createElementNS(self::NAMESPACE_URI, 'xfdf');

        // Without this a reader is entitled to collapse the runs of
        // spaces inside a value, and a form field holding "Suite  4"
        // comes back holding "Suite 4".
        $root->setAttribute('xml:space', 'preserve');
        $document->appendChild($root);

        if ($sourceFile !== null) {
            $f = $document->createElementNS(self::NAMESPACE_URI, 'f');
            $f->setAttribute('href', $sourceFile);
            $root->appendChild($f);
        }

        $fields = $document->createElementNS(self::NAMESPACE_URI, 'fields');
        $root->appendChild($fields);

        foreach ($values as $name => $value) {
            $element = self::elementFor($document, $fields, (string) $name);

            $valueElement = $document->createElementNS(self::NAMESPACE_URI, 'value');
            $valueElement->appendChild($document->createTextNode($value ?? ''));
            $element->appendChild($valueElement);
        }

        return (string) $document->saveXML();
    }

    /**
     * The values in an XFDF file, keyed by full field name and ready to
     * hand to FormFiller::fill().
     *
     * Every field the file mentions is returned, including ones the
     * document being filled may not have -- checking that is fill()'s job,
     * and it already reports an unknown name better than this could.
     *
     * @return array<string, string>
     */
    public static function parse(string $xml): array
    {
        $previous = libxml_use_internal_errors(true);

        // LIBXML_NONET for the same reason SvgDocument passes it: this
        // parses a file that arrived from outside, and a DTD that resolves
        // an external entity is how such a file reads /etc/passwd or makes
        // the server issue requests on its behalf.
        $document = new \DOMDocument();
        $loaded = $document->loadXML($xml, LIBXML_NONET);

        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        if (!$loaded) {
            throw new FormException('Malformed XFDF: this is not well-formed XML.');
        }

        $root = $document->documentElement;

        if ($root === null || $root->localName !== 'xfdf') {
            throw new FormException(sprintf(
                'This is not an XFDF file -- its root element is <%s> rather than <xfdf>. '
                . 'Acrobat also exports FDF, which is a different format in PDF syntax rather than XML.',
                $root->localName ?? '',
            ));
        }

        $values = [];

        foreach ($root->childNodes as $child) {
            if ($child instanceof \DOMElement && $child->localName === 'fields') {
                self::collect($child, '', $values);
            }
        }

        return $values;
    }

    /**
     * Walks the <field> tree, building each leaf's dotted full name from
     * the names of its ancestors.
     *
     * A field with both a value and children is not a shape XFDF is
     * supposed to produce, but is one a hand-written file can have, and
     * taking the value where there is one costs nothing.
     *
     * @param array<string, string> $values
     */
    private static function collect(\DOMElement $parent, string $prefix, array &$values): void
    {
        foreach ($parent->childNodes as $child) {
            if (!$child instanceof \DOMElement || $child->localName !== 'field') {
                continue;
            }

            $name = $child->getAttribute('name');

            if ($name === '') {
                continue;
            }

            $full = $prefix === '' ? $name : "$prefix.$name";

            foreach ($child->childNodes as $grandchild) {
                if ($grandchild instanceof \DOMElement && $grandchild->localName === 'value') {
                    // The first <value> only. A multi-select list box
                    // exports one per selection, and FormFiller sets a
                    // single value per field -- see the README's
                    // "Known limitations".
                    $values[$full] ??= $grandchild->textContent;
                }
            }

            self::collect($child, $full, $values);
        }
    }

    /**
     * The <field> element for a dotted name, making the ancestors on the
     * way down and reusing any that a previous name already made.
     */
    private static function elementFor(\DOMDocument $document, \DOMElement $fields, string $name): \DOMElement
    {
        $parent = $fields;

        foreach (explode('.', $name) as $part) {
            $existing = null;

            foreach ($parent->childNodes as $child) {
                if ($child instanceof \DOMElement
                    && $child->localName === 'field'
                    && $child->getAttribute('name') === $part) {
                    $existing = $child;
                    break;
                }
            }

            if ($existing === null) {
                $existing = $document->createElementNS(self::NAMESPACE_URI, 'field');
                $existing->setAttribute('name', $part);
                $parent->appendChild($existing);
            }

            $parent = $existing;
        }

        return $parent;
    }
}
