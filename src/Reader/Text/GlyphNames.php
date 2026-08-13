<?php

declare(strict_types=1);

namespace MightyPDF\Reader\Text;

use MightyPDF\Content\Text\Utf8;

/**
 * Glyph names back to the characters they stand for.
 *
 * A font's /Encoding /Differences renames codes by *glyph name* -- /eacute
 * rather than a character -- so reading text out of a font that has one
 * means turning those names back into text. The names are conventional:
 * Adobe's Glyph List fixes several thousand of them, and a producer that
 * uses them is legible to everyone.
 *
 * What is here is the algorithmic part of that convention plus the Latin
 * repertoire, rather than the whole list:
 *
 * - "uniXXXX" and "uXXXX[XX]" name a character by its code point outright.
 * - "gNN", "cidNN" and "indexNN" name a glyph by its position in the font
 *   file and say nothing about what it *is*. These are what a subsetting
 *   tool emits when it has given up on names, and they are unreadable by
 *   construction -- no table would help.
 * - Everything else is looked up in the table below, which covers
 *   WinAnsi's repertoire: what a document written in a Latin-script
 *   language actually renames.
 *
 * A name outside all of that returns null, and the caller shows U+FFFD --
 * so an undecodable page is visibly undecodable rather than quietly
 * missing characters.
 */
final class GlyphNames
{
    private function __construct()
    {
    }

    /** @return string|null UTF-8, or null if the name says nothing usable */
    public static function toText(string $name): ?string
    {
        if ($name === '' || $name === '.notdef') {
            return null;
        }

        // A name may carry a variant suffix ("a.sc", "one.oldstyle"); the
        // part before the first dot is what it stands for.
        $base = strstr($name, '.', true);

        if ($base !== false && $base !== '') {
            $name = $base;
        }

        if (preg_match('/^uni([0-9A-Fa-f]{4})$/', $name, $m) === 1) {
            return Utf8::fromCodePoint((int) hexdec($m[1]));
        }

        if (preg_match('/^u([0-9A-Fa-f]{4,6})$/', $name, $m) === 1) {
            $codePoint = (int) hexdec($m[1]);

            return $codePoint <= 0x10FFFF ? Utf8::fromCodePoint($codePoint) : null;
        }

        return self::table()[$name] ?? null;
    }

    /**
     * Glyph name => UTF-8, for the WinAnsi repertoire.
     *
     * Built from the code table rather than typed out twice: WinAnsi
     * already knows which character each code is, and these are the names
     * the same characters go by. One source, so the two cannot drift.
     *
     * @return array<string, string>
     */
    private static function table(): array
    {
        static $table = null;

        if ($table !== null) {
            return $table;
        }

        $names = [
            32 => 'space', 33 => 'exclam', 34 => 'quotedbl', 35 => 'numbersign',
            36 => 'dollar', 37 => 'percent', 38 => 'ampersand', 39 => 'quotesingle',
            40 => 'parenleft', 41 => 'parenright', 42 => 'asterisk', 43 => 'plus',
            44 => 'comma', 45 => 'hyphen', 46 => 'period', 47 => 'slash',
            48 => 'zero', 49 => 'one', 50 => 'two', 51 => 'three', 52 => 'four',
            53 => 'five', 54 => 'six', 55 => 'seven', 56 => 'eight', 57 => 'nine',
            58 => 'colon', 59 => 'semicolon', 60 => 'less', 61 => 'equal',
            62 => 'greater', 63 => 'question', 64 => 'at',
            91 => 'bracketleft', 92 => 'backslash', 93 => 'bracketright',
            94 => 'asciicircum', 95 => 'underscore', 96 => 'grave',
            123 => 'braceleft', 124 => 'bar', 125 => 'braceright', 126 => 'asciitilde',
            128 => 'Euro', 130 => 'quotesinglbase', 131 => 'florin', 132 => 'quotedblbase',
            133 => 'ellipsis', 134 => 'dagger', 135 => 'daggerdbl', 136 => 'circumflex',
            137 => 'perthousand', 138 => 'Scaron', 139 => 'guilsinglleft', 140 => 'OE',
            142 => 'Zcaron', 145 => 'quoteleft', 146 => 'quoteright', 147 => 'quotedblleft',
            148 => 'quotedblright', 149 => 'bullet', 150 => 'endash', 151 => 'emdash',
            152 => 'tilde', 153 => 'trademark', 154 => 'scaron', 155 => 'guilsinglright',
            156 => 'oe', 158 => 'zcaron', 159 => 'Ydieresis', 160 => 'space',
            161 => 'exclamdown', 162 => 'cent', 163 => 'sterling', 164 => 'currency',
            165 => 'yen', 166 => 'brokenbar', 167 => 'section', 168 => 'dieresis',
            169 => 'copyright', 170 => 'ordfeminine', 171 => 'guillemotleft',
            172 => 'logicalnot', 173 => 'hyphen', 174 => 'registered', 175 => 'macron',
            176 => 'degree', 177 => 'plusminus', 178 => 'twosuperior', 179 => 'threesuperior',
            180 => 'acute', 181 => 'mu', 182 => 'paragraph', 183 => 'periodcentered',
            184 => 'cedilla', 185 => 'onesuperior', 186 => 'ordmasculine',
            187 => 'guillemotright', 188 => 'onequarter', 189 => 'onehalf',
            190 => 'threequarters', 191 => 'questiondown', 192 => 'Agrave',
            193 => 'Aacute', 194 => 'Acircumflex', 195 => 'Atilde', 196 => 'Adieresis',
            197 => 'Aring', 198 => 'AE', 199 => 'Ccedilla', 200 => 'Egrave',
            201 => 'Eacute', 202 => 'Ecircumflex', 203 => 'Edieresis', 204 => 'Igrave',
            205 => 'Iacute', 206 => 'Icircumflex', 207 => 'Idieresis', 208 => 'Eth',
            209 => 'Ntilde', 210 => 'Ograve', 211 => 'Oacute', 212 => 'Ocircumflex',
            213 => 'Otilde', 214 => 'Odieresis', 215 => 'multiply', 216 => 'Oslash',
            217 => 'Ugrave', 218 => 'Uacute', 219 => 'Ucircumflex', 220 => 'Udieresis',
            221 => 'Yacute', 222 => 'Thorn', 223 => 'germandbls', 224 => 'agrave',
            225 => 'aacute', 226 => 'acircumflex', 227 => 'atilde', 228 => 'adieresis',
            229 => 'aring', 230 => 'ae', 231 => 'ccedilla', 232 => 'egrave',
            233 => 'eacute', 234 => 'ecircumflex', 235 => 'edieresis', 236 => 'igrave',
            237 => 'iacute', 238 => 'icircumflex', 239 => 'idieresis', 240 => 'eth',
            241 => 'ntilde', 242 => 'ograve', 243 => 'oacute', 244 => 'ocircumflex',
            245 => 'otilde', 246 => 'odieresis', 247 => 'divide', 248 => 'oslash',
            249 => 'ugrave', 250 => 'uacute', 251 => 'ucircumflex', 252 => 'udieresis',
            253 => 'yacute', 254 => 'thorn', 255 => 'ydieresis',
        ];

        $table = [];

        // The letters and digits are their own names' characters, so they
        // are generated rather than listed: /A is "A".
        foreach (range('A', 'Z') as $letter) {
            $table[$letter] = $letter;
            $table[strtolower($letter)] = strtolower($letter);
        }

        foreach ($names as $code => $name) {
            $table[$name] ??= \MightyPDF\Assembler\Types\WinAnsiEncoding::decode(chr($code));
        }

        return $table = array_filter($table, static fn (string $text): bool => $text !== '');
    }
}
