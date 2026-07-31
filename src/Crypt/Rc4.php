<?php

declare(strict_types=1);

namespace MightyPDF\Crypt;

/**
 * RC4, hand-rolled because it is no longer available anywhere else.
 *
 * OpenSSL 3 moved RC4 into the legacy provider, which is not enabled in
 * most distribution builds -- `openssl_get_cipher_methods()` simply does
 * not list it -- and ext-mcrypt was removed in PHP 7.2. Every PDF using
 * the standard security handler at revision 2 or 3 is RC4, and those are
 * a large share of the encrypted PDFs that exist, so "the platform
 * dropped it" is not an answer a reader can give.
 *
 * RC4 is thoroughly broken as a cipher and nothing here should be taken
 * as an endorsement of it. It is implemented to *read* documents that
 * already use it, which is a decoding problem rather than a security
 * one -- and, since the standard handler's user password is very often
 * empty, one where the encryption was never protecting much anyway.
 */
final class Rc4
{
    /** Symmetric: encrypting and decrypting are the same operation. */
    public static function apply(string $key, string $data): string
    {
        $keyLength = strlen($key);

        if ($keyLength === 0) {
            return $data;
        }

        $state = range(0, 255);
        $j = 0;

        for ($i = 0; $i < 256; ++$i) {
            $j = ($j + $state[$i] + ord($key[$i % $keyLength])) % 256;
            [$state[$i], $state[$j]] = [$state[$j], $state[$i]];
        }

        $out = '';
        $i = 0;
        $j = 0;

        for ($n = 0, $length = strlen($data); $n < $length; ++$n) {
            $i = ($i + 1) % 256;
            $j = ($j + $state[$i]) % 256;
            [$state[$i], $state[$j]] = [$state[$j], $state[$i]];

            $out .= chr(ord($data[$n]) ^ $state[($state[$i] + $state[$j]) % 256]);
        }

        return $out;
    }
}
