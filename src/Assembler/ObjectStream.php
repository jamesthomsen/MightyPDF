<?php

declare(strict_types=1);

namespace MightyPDF\Assembler;

use MightyPDF\Assembler\Types\PdfInteger;
use MightyPDF\Assembler\Types\PdfName;
use MightyPDF\Exception\LogicException;

/**
 * An object stream (ISO 32000-2 §7.5.7): a stream whose data is a run of
 * other objects' bodies, so that they get compressed together rather than
 * sitting in the file one uncompressed "N 0 obj ... endobj" at a time.
 *
 * A PDF's dictionaries are the compressible part of it -- short, repetitive
 * ASCII, full of the same handful of key names -- and in an ordinary
 * document there are a great many of them: two objects per form field,
 * several per annotation, one per structure element in a tagged file. Each
 * is small enough that its own Flate stream would cost more than it saved,
 * which is why the writer never compressed them individually. Together they
 * deflate like the text they are.
 *
 * What cannot go in one (§7.5.7):
 *
 * - A stream. Its data has to be findable by byte offset without decoding
 *   anything first, which is the whole reason streams exist.
 * - An object with a non-zero generation, which has no way to be named
 *   here: the header carries object numbers alone.
 * - The encryption dictionary, and anything the trailer must reach before
 *   a key has been derived -- a reader cannot decompress this stream until
 *   it can decrypt it, and it cannot decrypt anything until it has read
 *   /Encrypt.
 *
 * And one rule that is easy to miss: strings inside an object stream are
 * *not* enciphered individually. The stream as a whole is, under its own
 * object number, so a document that encrypted the strings on the way in
 * would have them enciphered twice and readable by nothing. See
 * IndirectObjectRegistry::writeAll(), which is why packing happens there
 * rather than after the encryption pass.
 */
final class ObjectStream
{
    /**
     * How many objects go in one stream before another is started.
     *
     * The compression argument says pack everything into one; the reader
     * argument says the opposite, since a reader that wants one object out
     * of a stream has to inflate the whole of it and keep it. A few
     * hundred is where those stop trading against each other -- the
     * dictionary is warm well before then, so a bigger chunk buys almost
     * nothing and costs a reader that only wanted a page.
     */
    public const int CAPACITY = 200;

    /**
     * Whether $object is one that may be packed at all, leaving aside the
     * document-level exclusions the caller knows about (/Encrypt).
     */
    public static function accepts(PdfObject $object): bool
    {
        return !$object instanceof Stream && $object->generation() === 0;
    }

    /**
     * Packs $objects into a stream numbered $objectId.
     *
     * The result is the stream to write; the caller records the type-2
     * cross-reference entries pointing into it, in the order given here --
     * which is the order of $objects, since an entry names its index.
     *
     * @param array<int, PdfObject> $objects keyed by object id
     */
    public static function pack(int $objectId, array $objects): Stream
    {
        if ($objects === []) {
            throw new LogicException('An object stream with nothing in it is a stream a reader has no reason to read.');
        }

        $header = '';
        $bodies = '';

        foreach ($objects as $id => $object) {
            if (!self::accepts($object)) {
                throw new LogicException(
                    "Object $id cannot go in an object stream -- see ObjectStream for the three kinds that cannot.",
                );
            }

            // trim() for the same reason PdfObject::render() does it: the
            // offsets recorded here are exact, so a body with a stray
            // newline in front of it is one whose recorded offset points
            // at the newline.
            $body = trim($object->render(false));

            $header .= $id . ' ' . strlen($bodies) . ' ';
            $bodies .= $body . "\n";
        }

        $stream = new Stream($objectId, $header . $bodies);

        $stream->set('Type', new PdfName('ObjStm'));
        $stream->set('N', new PdfInteger(count($objects)));

        // Where the bodies start, the offsets in the header being
        // relative to it rather than to the stream.
        $stream->set('First', new PdfInteger(strlen($header)));

        return $stream;
    }
}
