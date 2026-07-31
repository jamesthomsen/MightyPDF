<?php

declare(strict_types=1);

namespace MightyPDF\Crypt;

/**
 * How one class of data in the file is enciphered -- ISO 32000-2 Table 25,
 * the /CFM entry of a crypt filter.
 *
 * Strings and streams may use different methods, which is what /StrF and
 * /StmF select between; /Identity is a real and useful choice, since a
 * document can perfectly well encrypt its streams and leave its strings
 * alone.
 */
enum CryptMethod
{
    case Identity;
    case Rc4;
    case Aes128;
    case Aes256;

    public static function fromName(string $name): self
    {
        return match ($name) {
            'V2' => self::Rc4,
            'AESV2' => self::Aes128,
            'AESV3' => self::Aes256,
            'None', 'Identity' => self::Identity,
            default => throw new DecryptionException("Unsupported crypt filter method /$name."),
        };
    }

    public function isAes(): bool
    {
        return $this === self::Aes128 || $this === self::Aes256;
    }
}
