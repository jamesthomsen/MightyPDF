<?php

declare(strict_types=1);

/**
 * WinAnsi code point => advance width (1/1000 em units) for Times-BoldItalic.
 *
 * Adobe's Core 14 AFM metrics (public domain, universally republished
 * across PDF tooling), covering the whole WinAnsiEncoding repertoire
 * rather than just its ASCII range. The upper half matters as much as
 * the lower one for anything not written in English: an em dash is
 * 1000 units and a curly quote 222, so measuring either as the 500-unit
 * default -- which is what an absent entry falls back to -- misplaces
 * every centred, right-aligned, wrapped or justified line containing
 * one.
 *
 * Read out of the URW base-35 AFMs by glyph name rather than by the
 * AFM's own encoding column, which is AdobeStandardEncoding and
 * disagrees with WinAnsi at codes 39 and 96. URW's fonts are metric
 * clones of the Core 14 -- that is why Ghostscript substitutes them --
 * and the 95 ASCII widths transcribed here by hand beforehand all
 * agree with them exactly, in all six families, which is the check
 * that says the two sources are the same numbers.
 *
 * Codes 0xA0 and 0xAD are a non-breaking space and a soft hyphen; per
 * ISO 32000-2 Annex D a reader draws and measures them as an ordinary
 * space and hyphen, so they carry those widths. The five codes CP1252
 * leaves undefined (0x81, 0x8D, 0x8F, 0x90, 0x9D) are absent, and
 * WinAnsiEncoding never emits them.
 *
 * The codes below 0x20, and 0x7F, are absent for a different reason:
 * WinAnsiEncoding assigns them no glyph, so a reader draws nothing and
 * advances nothing. They are still encodable, which is why they measure
 * zero rather than falling to the default width -- see
 * FontMetrics::forWinAnsi().
 */
return [
    32 => 250, 33 => 389, 34 => 555, 35 => 500, 36 => 500, 37 => 833, 38 => 778, 39 => 278,
    40 => 333, 41 => 333, 42 => 500, 43 => 570, 44 => 250, 45 => 333, 46 => 250, 47 => 278,
    48 => 500, 49 => 500, 50 => 500, 51 => 500, 52 => 500, 53 => 500, 54 => 500, 55 => 500,
    56 => 500, 57 => 500, 58 => 333, 59 => 333, 60 => 570, 61 => 570, 62 => 570, 63 => 500,
    64 => 832, 65 => 667, 66 => 667, 67 => 667, 68 => 722, 69 => 667, 70 => 667, 71 => 722,
    72 => 778, 73 => 389, 74 => 500, 75 => 667, 76 => 611, 77 => 889, 78 => 722, 79 => 722,
    80 => 611, 81 => 722, 82 => 667, 83 => 556, 84 => 611, 85 => 722, 86 => 667, 87 => 889,
    88 => 667, 89 => 611, 90 => 611, 91 => 333, 92 => 278, 93 => 333, 94 => 570, 95 => 500,
    96 => 333, 97 => 500, 98 => 500, 99 => 444, 100 => 500, 101 => 444, 102 => 333, 103 => 500,
    104 => 556, 105 => 278, 106 => 278, 107 => 500, 108 => 278, 109 => 778, 110 => 556, 111 => 500,
    112 => 500, 113 => 500, 114 => 389, 115 => 389, 116 => 278, 117 => 556, 118 => 444, 119 => 667,
    120 => 500, 121 => 444, 122 => 389, 123 => 348, 124 => 220, 125 => 348, 126 => 570, 128 => 500,
    130 => 333, 131 => 500, 132 => 500, 133 => 1000, 134 => 500, 135 => 500, 136 => 333, 137 => 1000,
    138 => 556, 139 => 333, 140 => 944, 142 => 611, 145 => 333, 146 => 333, 147 => 500, 148 => 500,
    149 => 350, 150 => 500, 151 => 1000, 152 => 333, 153 => 1000, 154 => 389, 155 => 333, 156 => 722,
    158 => 389, 159 => 611, 160 => 250, 161 => 389, 162 => 500, 163 => 500, 164 => 500, 165 => 500,
    166 => 220, 167 => 500, 168 => 333, 169 => 747, 170 => 266, 171 => 500, 172 => 606, 173 => 333,
    174 => 747, 175 => 333, 176 => 400, 177 => 570, 178 => 300, 179 => 300, 180 => 333, 181 => 576,
    182 => 500, 183 => 250, 184 => 333, 185 => 300, 186 => 300, 187 => 500, 188 => 750, 189 => 750,
    190 => 750, 191 => 500, 192 => 667, 193 => 667, 194 => 667, 195 => 667, 196 => 667, 197 => 667,
    198 => 944, 199 => 667, 200 => 667, 201 => 667, 202 => 667, 203 => 667, 204 => 389, 205 => 389,
    206 => 389, 207 => 389, 208 => 722, 209 => 722, 210 => 722, 211 => 722, 212 => 722, 213 => 722,
    214 => 722, 215 => 570, 216 => 722, 217 => 722, 218 => 722, 219 => 722, 220 => 722, 221 => 611,
    222 => 611, 223 => 500, 224 => 500, 225 => 500, 226 => 500, 227 => 500, 228 => 500, 229 => 500,
    230 => 722, 231 => 444, 232 => 444, 233 => 444, 234 => 444, 235 => 444, 236 => 278, 237 => 278,
    238 => 278, 239 => 278, 240 => 500, 241 => 556, 242 => 500, 243 => 500, 244 => 500, 245 => 500,
    246 => 500, 247 => 570, 248 => 500, 249 => 556, 250 => 556, 251 => 556, 252 => 556, 253 => 444,
    254 => 500, 255 => 444,
];
