<?php

declare(strict_types=1);

namespace MightyPDF\Assembler;

/**
 * An object that cannot be finished until nothing more will be added to
 * the document -- an embedded font subset being the reason this exists:
 * which glyphs a font program has to contain is only known once every
 * draw call that could use one has happened.
 *
 * finalize() runs in a pass of its own, over every registered object,
 * before the first one is serialized. Two properties the callers below
 * depend on, and that implementations must keep:
 *
 * - It must be idempotent. save() is documented as repeatable -- calling
 *   it twice returns the same bytes -- so finalize() has to be able to
 *   run again over its own output and produce the same thing.
 * - It may reach objects other than itself (a font dictionary filling in
 *   its own descriptor and font-file streams). That is exactly why this
 *   is a separate pass rather than a hook on the way past each object:
 *   an object it touches may have a lower number and so already be
 *   written by the time the finalizable one comes around.
 *
 * It must *not* allocate object ids: writing has begun by then, and an
 * object that appears after the xref is being counted is not in the file.
 */
interface Finalizable
{
    public function finalize(): void;
}
