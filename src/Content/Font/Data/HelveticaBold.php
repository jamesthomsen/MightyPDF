<?php

declare(strict_types=1);

/**
 * WinAnsi code point => advance width (1/1000 em units) for Helvetica-Bold.
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
 *
 * Also used for Helvetica-BoldOblique, whose widths are identical for
 * the same reason.
 */
return [
    32 => 278, 33 => 333, 34 => 474, 35 => 556, 36 => 556, 37 => 889, 38 => 722, 39 => 238,
    40 => 333, 41 => 333, 42 => 389, 43 => 584, 44 => 278, 45 => 333, 46 => 278, 47 => 278,
    48 => 556, 49 => 556, 50 => 556, 51 => 556, 52 => 556, 53 => 556, 54 => 556, 55 => 556,
    56 => 556, 57 => 556, 58 => 333, 59 => 333, 60 => 584, 61 => 584, 62 => 584, 63 => 611,
    64 => 975, 65 => 722, 66 => 722, 67 => 722, 68 => 722, 69 => 667, 70 => 611, 71 => 778,
    72 => 722, 73 => 278, 74 => 556, 75 => 722, 76 => 611, 77 => 833, 78 => 722, 79 => 778,
    80 => 667, 81 => 778, 82 => 722, 83 => 667, 84 => 611, 85 => 722, 86 => 667, 87 => 944,
    88 => 667, 89 => 667, 90 => 611, 91 => 333, 92 => 278, 93 => 333, 94 => 584, 95 => 556,
    96 => 333, 97 => 556, 98 => 611, 99 => 556, 100 => 611, 101 => 556, 102 => 333, 103 => 611,
    104 => 611, 105 => 278, 106 => 278, 107 => 556, 108 => 278, 109 => 889, 110 => 611, 111 => 611,
    112 => 611, 113 => 611, 114 => 389, 115 => 556, 116 => 333, 117 => 611, 118 => 556, 119 => 778,
    120 => 556, 121 => 556, 122 => 500, 123 => 389, 124 => 280, 125 => 389, 126 => 584, 128 => 556,
    130 => 278, 131 => 556, 132 => 500, 133 => 1000, 134 => 556, 135 => 556, 136 => 333, 137 => 1000,
    138 => 667, 139 => 333, 140 => 1000, 142 => 611, 145 => 278, 146 => 278, 147 => 500, 148 => 500,
    149 => 350, 150 => 556, 151 => 1000, 152 => 333, 153 => 1000, 154 => 556, 155 => 333, 156 => 944,
    158 => 500, 159 => 667, 160 => 278, 161 => 333, 162 => 556, 163 => 556, 164 => 556, 165 => 556,
    166 => 280, 167 => 556, 168 => 333, 169 => 737, 170 => 370, 171 => 556, 172 => 584, 173 => 333,
    174 => 737, 175 => 333, 176 => 400, 177 => 584, 178 => 333, 179 => 333, 180 => 333, 181 => 611,
    182 => 556, 183 => 278, 184 => 333, 185 => 333, 186 => 365, 187 => 556, 188 => 834, 189 => 834,
    190 => 834, 191 => 611, 192 => 722, 193 => 722, 194 => 722, 195 => 722, 196 => 722, 197 => 722,
    198 => 1000, 199 => 722, 200 => 667, 201 => 667, 202 => 667, 203 => 667, 204 => 278, 205 => 278,
    206 => 278, 207 => 278, 208 => 722, 209 => 722, 210 => 778, 211 => 778, 212 => 778, 213 => 778,
    214 => 778, 215 => 584, 216 => 778, 217 => 722, 218 => 722, 219 => 722, 220 => 722, 221 => 667,
    222 => 667, 223 => 611, 224 => 556, 225 => 556, 226 => 556, 227 => 556, 228 => 556, 229 => 556,
    230 => 889, 231 => 556, 232 => 556, 233 => 556, 234 => 556, 235 => 556, 236 => 278, 237 => 278,
    238 => 278, 239 => 278, 240 => 611, 241 => 611, 242 => 611, 243 => 611, 244 => 611, 245 => 611,
    246 => 611, 247 => 584, 248 => 611, 249 => 611, 250 => 611, 251 => 611, 252 => 611, 253 => 556,
    254 => 611, 255 => 556,
];
