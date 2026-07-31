<?php

declare(strict_types=1);

namespace MightyPDF\Reader;

/**
 * The lexical categories of ISO 32000-2 §7.2, "Lexical conventions".
 *
 * Note this is a *lexical* classification, not the object model: `true`,
 * `false`, `null`, `obj`, `R`, `stream` and `trailer` are all just
 * Keyword here, and it is the parser's job to decide which of them is a
 * value and which is syntax. Keeping that decision out of the lexer is
 * what lets the same lexer tokenize a cross-reference table (where `n`
 * and `f` are keywords with no object meaning at all) and a content
 * stream (where every operator is one).
 *
 * ProcedureOpen/ProcedureClose exist because `{` and `}` appear in Type 4
 * (PostScript calculator) function streams. They are never valid inside a
 * document object, but a lexer that treats them as an unknown byte would
 * fail on a file it has no business failing on.
 */
enum TokenType
{
    case Number;
    case Name;
    case LiteralString;
    case HexString;
    case DictionaryOpen;
    case DictionaryClose;
    case ArrayOpen;
    case ArrayClose;
    case ProcedureOpen;
    case ProcedureClose;
    case Keyword;
}
