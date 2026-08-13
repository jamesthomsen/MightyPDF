<?php

declare(strict_types=1);

namespace MightyPDF\Crypt;

use MightyPDF\Assembler\Dictionary;
use MightyPDF\Assembler\Types\PdfArray;
use MightyPDF\Assembler\Types\PdfBoolean;
use MightyPDF\Assembler\Types\PdfHexString;
use MightyPDF\Assembler\Types\PdfInteger;
use MightyPDF\Assembler\Types\PdfName;
use MightyPDF\Assembler\Types\PdfString;
use MightyPDF\Assembler\Types\PdfValue;

/**
 * The standard security handler (ISO 32000-2 §7.6.4) -- the /Filter
 * /Standard encryption that essentially every encrypted PDF uses.
 *
 * The important thing to understand about it is that "encrypted" very
 * often does not mean "you need a password". The scheme has two of them:
 * an owner password restricting what may be done with the document, and a
 * user password restricting who may open it at all. Setting the first and
 * leaving the second empty is the overwhelmingly common configuration --
 * every viewer opens the file without prompting, because it unlocks with
 * the empty string. Such files look completely ordinary to a person and
 * are undecodable to a reader that has not implemented this.
 *
 * That is why this exists. Without it a bank statement or a government
 * form would parse "successfully" into a document full of binary noise:
 * field names matching nothing, content streams drawing nothing.
 *
 * open() reads whatever a file already uses, back to the 1996 original:
 * RC4 at 40 to 128 bits, AES-128, AES-256. create() writes only AES-256,
 * because there is no reason to put a broken cipher into a file being
 * made today, and every reader that can open an encrypted PDF at all has
 * understood AES-256 for a decade.
 *
 * What encryption here does and does not give you is worth stating
 * plainly. With a real user password, AES-256 is strong and the document
 * is genuinely unreadable without it. With an *empty* user password --
 * the usual arrangement, where only an owner password is set -- it gives
 * no confidentiality whatsoever: the file opens in every viewer without
 * a prompt, because the key derives from the empty string, which anybody
 * has. What it buys in that case is the /P permission flags, which are a
 * request rather than a restriction (see Permissions).
 */
final class StandardSecurityHandler
{
    /**
     * The padding string from Algorithm 2, used to bring any password to
     * exactly 32 bytes. Fixed by the spec, not a secret.
     */
    private const string PAD = "\x28\xBF\x4E\x5E\x4E\x75\x8A\x41\x64\x00\x4E\x56\xFF\xFA\x01\x08"
        . "\x2E\x2E\x00\xB6\xD0\x68\x3E\x80\x2F\x0C\xA9\xFE\x64\x53\x69\x7A";

    private function __construct(
        private readonly string $fileKey,
        private readonly int $revision,
        private readonly CryptMethod $stringMethod,
        private readonly CryptMethod $streamMethod,
        private readonly bool $encryptMetadata,
        private readonly ?Dictionary $encryptDictionary = null,
    ) {
    }

    /**
     * Sets up AES-256 encryption for a document being written.
     *
     * The file key is random and is never derived from either password --
     * at revision 6 the passwords only unlock a copy of it stored in /UE
     * and /OE. That is why changing a password does not require
     * re-encrypting the document, and why an empty user password leaves
     * the key readable by anyone.
     */
    public static function create(
        string $userPassword,
        string $ownerPassword,
        int $permissions,
        bool $encryptMetadata = true,
    ): self {
        $fileKey = random_bytes(32);

        $userValidationSalt = random_bytes(8);
        $userKeySalt = random_bytes(8);
        $user = self::modernHash($userPassword, $userValidationSalt, '', 6)
            . $userValidationSalt
            . $userKeySalt;

        // The owner hashes mix in the whole 48-byte /U, which is what ties
        // an owner password to this one document.
        $ownerValidationSalt = random_bytes(8);
        $ownerKeySalt = random_bytes(8);
        $owner = self::modernHash($ownerPassword, $ownerValidationSalt, $user, 6)
            . $ownerValidationSalt
            . $ownerKeySalt;

        $dictionary = (new Dictionary())
            ->set('Filter', new PdfName('Standard'))
            ->set('V', new PdfInteger(5))
            ->set('R', new PdfInteger(6))
            ->set('CF', (new Dictionary())->set('StdCF', (new Dictionary())
                ->set('CFM', new PdfName('AESV3'))
                ->set('Length', new PdfInteger(32))
                ->set('AuthEvent', new PdfName('DocOpen'))))
            ->set('StmF', new PdfName('StdCF'))
            ->set('StrF', new PdfName('StdCF'))
            ->set('P', new PdfInteger($permissions))
            ->set('O', PdfString::raw($owner))
            ->set('U', PdfString::raw($user))
            ->set('OE', PdfString::raw(self::wrapFileKey(
                $fileKey,
                self::modernHash($ownerPassword, $ownerKeySalt, $user, 6),
            )))
            ->set('UE', PdfString::raw(self::wrapFileKey(
                $fileKey,
                self::modernHash($userPassword, $userKeySalt, '', 6),
            )))
            ->set('Perms', PdfString::raw(self::permissionsBlock($permissions, $fileKey, $encryptMetadata)));

        // Written only when false: true is the default (Table 21), and a
        // reader that has to be told its own default is a reader being
        // given noise to parse.
        if (!$encryptMetadata) {
            $dictionary->set('EncryptMetadata', new PdfBoolean(false));
        }

        return new self($fileKey, 6, CryptMethod::Aes256, CryptMethod::Aes256, $encryptMetadata, $dictionary);
    }

    /**
     * The /Encrypt dictionary to write, for a handler made by create().
     *
     * Null for one made by open(): the dictionary is already in the file,
     * and is the one object that must not be rewritten -- it describes how
     * to decrypt everything else.
     */
    public function encryptDictionary(): ?Dictionary
    {
        return $this->encryptDictionary;
    }

    /**
     * @param ?PdfArray $id the trailer's /ID, which is mixed into the key
     *        for revisions 2-4 -- so a file whose /ID has been altered
     *        will not unlock even with the right password
     * @throws DecryptionException if $password opens neither lock
     */
    public static function open(Dictionary $encrypt, ?PdfArray $id, string $password): self
    {
        $filter = $encrypt->get('Filter');

        if ($filter instanceof PdfName && $filter->value() !== 'Standard') {
            throw new DecryptionException(
                "This PDF uses the /{$filter->value()} security handler, which is not the standard one and is not supported.",
            );
        }

        $revision = self::integer($encrypt, 'R', 0);
        $version = self::integer($encrypt, 'V', 0);
        $owner = self::bytes($encrypt->get('O'));
        $user = self::bytes($encrypt->get('U'));
        $permissions = self::integer($encrypt, 'P', 0);
        $encryptMetadata = !($encrypt->get('EncryptMetadata') instanceof PdfBoolean)
            || $encrypt->get('EncryptMetadata')->value();

        [$stringMethod, $streamMethod] = self::cryptMethods($encrypt, $version);

        $fileKey = $revision >= 5
            ? self::modernKey($encrypt, $password, $revision, $owner, $user)
            : self::legacyKey($encrypt, $password, $revision, $version, $owner, $user, $permissions, $id, $encryptMetadata);

        return new self($fileKey, $revision, $stringMethod, $streamMethod, $encryptMetadata);
    }

    public function encryptsMetadata(): bool
    {
        return $this->encryptMetadata;
    }

    public function decryptString(string $bytes, int $objectId, int $generation): string
    {
        return $this->transform($bytes, $objectId, $generation, $this->stringMethod, false);
    }

    public function decryptStream(string $bytes, int $objectId, int $generation): string
    {
        return $this->transform($bytes, $objectId, $generation, $this->streamMethod, false);
    }

    public function encryptString(string $bytes, int $objectId, int $generation): string
    {
        return $this->transform($bytes, $objectId, $generation, $this->stringMethod, true);
    }

    public function encryptStream(string $bytes, int $objectId, int $generation): string
    {
        return $this->transform($bytes, $objectId, $generation, $this->streamMethod, true);
    }

    private function transform(string $bytes, int $objectId, int $generation, CryptMethod $method, bool $encrypting): string
    {
        if ($method === CryptMethod::Identity || $bytes === '') {
            return $bytes;
        }

        $key = $this->objectKey($objectId, $generation, $method);

        if ($method === CryptMethod::Rc4) {
            return Rc4::apply($key, $bytes);
        }

        $cipher = $method === CryptMethod::Aes256 ? 'aes-256-cbc' : 'aes-128-cbc';

        return $encrypting
            ? self::aesEncrypt($bytes, $cipher, $key)
            : self::aesDecrypt($bytes, $cipher, $key);
    }

    /**
     * Algorithm 1: the key an individual object is enciphered with, which
     * is the file key mixed with that object's number and generation.
     *
     * Per-object keys are why decryption cannot happen anywhere except
     * where the object number is known -- a string carries no hint of
     * which object it came from, so a value passed around and decrypted
     * later would be decrypted with the wrong key and yield noise.
     *
     * AES-256 skips all of this: at revision 5 and up the file key is
     * used directly, unmixed.
     */
    private function objectKey(int $objectId, int $generation, CryptMethod $method): string
    {
        if ($this->revision >= 5) {
            return $this->fileKey;
        }

        $extra = substr(pack('V', $objectId), 0, 3) . substr(pack('V', $generation), 0, 2);

        if ($method->isAes()) {
            // The literal bytes "sAlT", per Algorithm 1 step (b).
            $extra .= "\x73\x41\x6C\x54";
        }

        return substr(md5($this->fileKey . $extra, true), 0, min(strlen($this->fileKey) + 5, 16));
    }

    private static function aesDecrypt(string $bytes, string $cipher, string $key): string
    {
        // The first 16 bytes are the initialisation vector, not data.
        if (strlen($bytes) <= 16) {
            return '';
        }

        $plain = openssl_decrypt(substr($bytes, 16), $cipher, $key, OPENSSL_RAW_DATA, substr($bytes, 0, 16));

        if ($plain !== false) {
            return $plain;
        }

        // A truncated or badly padded final block. Decrypting without
        // padding validation salvages every whole block before the damage,
        // which for a content stream is almost all of it.
        $salvaged = openssl_decrypt(
            substr($bytes, 16, intdiv(strlen($bytes) - 16, 16) * 16),
            $cipher,
            $key,
            OPENSSL_RAW_DATA | OPENSSL_ZERO_PADDING,
            substr($bytes, 0, 16),
        );

        return $salvaged === false ? '' : $salvaged;
    }

    private static function aesEncrypt(string $bytes, string $cipher, string $key): string
    {
        $iv = random_bytes(16);
        $encrypted = openssl_encrypt($bytes, $cipher, $key, OPENSSL_RAW_DATA, $iv);

        if ($encrypted === false) {
            throw new DecryptionException('Failed to re-encrypt data for the updated document.');
        }

        return $iv . $encrypted;
    }

    /**
     * Algorithm 2, plus the password checks of Algorithms 6 and 7.
     */
    private static function legacyKey(
        Dictionary $encrypt,
        string $password,
        int $revision,
        int $version,
        string $owner,
        string $user,
        int $permissions,
        ?PdfArray $id,
        bool $encryptMetadata,
    ): string {
        $lengthBytes = self::keyLengthBytes($encrypt, $version, $revision);
        $firstId = self::firstId($id);

        $derive = static function (string $candidate) use (
            $owner, $permissions, $firstId, $revision, $lengthBytes, $encryptMetadata, $version
        ): string {
            $input = substr($candidate . self::PAD, 0, 32)
                . substr($owner, 0, 32)
                . pack('V', $permissions & 0xFFFFFFFF)
                . $firstId;

            if ($revision >= 4 && !$encryptMetadata) {
                $input .= "\xFF\xFF\xFF\xFF";
            }

            $key = md5($input, true);

            if ($revision >= 3) {
                for ($i = 0; $i < 50; ++$i) {
                    $key = md5(substr($key, 0, $lengthBytes), true);
                }
            }

            return substr($key, 0, $lengthBytes);
        };

        $key = $derive($password);

        if (self::userPasswordMatches($key, $user, $firstId, $revision)) {
            return $key;
        }

        // Not the user password. It may still be the *owner* password,
        // which unlocks the file by decrypting /O to recover the user
        // password and then proceeding as normal (Algorithm 7).
        $recovered = self::userPasswordFromOwner($password, $owner, $revision, $lengthBytes);
        $key = $derive($recovered);

        if (self::userPasswordMatches($key, $user, $firstId, $revision)) {
            return $key;
        }

        throw new DecryptionException(self::wrongPasswordMessage($password));
    }

    /** Algorithms 6 and 4/5. */
    private static function userPasswordMatches(string $key, string $user, string $firstId, int $revision): bool
    {
        if ($revision === 2) {
            return Rc4::apply($key, self::PAD) === substr($user, 0, 32);
        }

        $result = Rc4::apply($key, md5(self::PAD . $firstId, true));

        for ($round = 1; $round <= 19; ++$round) {
            $result = Rc4::apply(self::xorWith($key, $round), $result);
        }

        // Only the first 16 bytes are meaningful; the rest is arbitrary
        // padding the producer was free to fill however it liked.
        return substr($result, 0, 16) === substr($user, 0, 16);
    }

    /** Algorithm 7: run Algorithm 3 backwards to get the user password. */
    private static function userPasswordFromOwner(string $ownerPassword, string $owner, int $revision, int $lengthBytes): string
    {
        $hash = md5(substr($ownerPassword . self::PAD, 0, 32), true);

        if ($revision >= 3) {
            for ($i = 0; $i < 50; ++$i) {
                $hash = md5($hash, true);
            }
        }

        $key = substr($hash, 0, $revision === 2 ? 5 : $lengthBytes);

        if ($revision === 2) {
            return Rc4::apply($key, $owner);
        }

        $result = $owner;

        for ($round = 19; $round >= 0; --$round) {
            $result = Rc4::apply(self::xorWith($key, $round), $result);
        }

        return $result;
    }

    /**
     * Revisions 5 and 6: AES-256, where the file key is a random value
     * stored enciphered in /UE or /OE rather than being derived from the
     * password at all. The password only unlocks it.
     */
    private static function modernKey(Dictionary $encrypt, string $password, int $revision, string $owner, string $user): string
    {
        $userValidation = substr($user, 32, 8);
        $userKeySalt = substr($user, 40, 8);

        if (self::modernHash($password, $userValidation, '', $revision) === substr($user, 0, 32)) {
            return self::unwrapFileKey(
                self::bytes($encrypt->get('UE')),
                self::modernHash($password, $userKeySalt, '', $revision),
            );
        }

        // The owner hashes additionally mix in the whole 48-byte /U, which
        // is what ties an owner password to one specific document.
        $ownerValidation = substr($owner, 32, 8);
        $ownerKeySalt = substr($owner, 40, 8);
        $userBytes = substr($user, 0, 48);

        if (self::modernHash($password, $ownerValidation, $userBytes, $revision) === substr($owner, 0, 32)) {
            return self::unwrapFileKey(
                self::bytes($encrypt->get('OE')),
                self::modernHash($password, $ownerKeySalt, $userBytes, $revision),
            );
        }

        throw new DecryptionException(self::wrongPasswordMessage($password));
    }

    /** The inverse of unwrapFileKey: Algorithms 8 and 9, forwards. */
    private static function wrapFileKey(string $fileKey, string $intermediateKey): string
    {
        $wrapped = openssl_encrypt(
            $fileKey,
            'aes-256-cbc',
            $intermediateKey,
            OPENSSL_RAW_DATA | OPENSSL_ZERO_PADDING,
            str_repeat("\x00", 16),
        );

        if ($wrapped === false) {
            throw new DecryptionException('Failed to wrap the file encryption key.');
        }

        return $wrapped;
    }

    /**
     * The /Perms block (Algorithm 10): the permissions again, enciphered
     * with the file key so that a reader can check nobody has edited /P
     * in the clear. Without it the flags could be rewritten by anyone with
     * a hex editor, since /P itself is not protected.
     */
    private static function permissionsBlock(int $permissions, string $fileKey, bool $encryptMetadata): string
    {
        $block = pack('V', $permissions & 0xFFFFFFFF)
            . "\xFF\xFF\xFF\xFF"
            // 'T' or 'F' for whether metadata is encrypted, then the
            // literal marker bytes the spec requires, then four bytes of
            // anything. This byte has to agree with /EncryptMetadata: it
            // is the enciphered copy a reader checks the plaintext one
            // against, so disagreeing is how a document reports itself as
            // tampered with.
            . ($encryptMetadata ? 'T' : 'F')
            . 'adb'
            . random_bytes(4);

        $encrypted = openssl_encrypt($block, 'aes-256-ecb', $fileKey, OPENSSL_RAW_DATA | OPENSSL_ZERO_PADDING);

        if ($encrypted === false) {
            throw new DecryptionException('Failed to build the /Perms block.');
        }

        return $encrypted;
    }

    private static function unwrapFileKey(string $wrapped, string $intermediateKey): string
    {
        // A zero IV and no padding, per Algorithms 8 and 9.
        $key = openssl_decrypt(
            $wrapped,
            'aes-256-cbc',
            $intermediateKey,
            OPENSSL_RAW_DATA | OPENSSL_ZERO_PADDING,
            str_repeat("\x00", 16),
        );

        if ($key === false || strlen($key) < 32) {
            throw new DecryptionException('The file encryption key could not be unwrapped; /UE or /OE looks damaged.');
        }

        return substr($key, 0, 32);
    }

    /**
     * Algorithm 2.A for revision 5, and 2.B for revision 6.
     *
     * Revision 6's is deliberately expensive: at least 64 rounds of
     * AES-CBC over a buffer 64 copies long, continuing until a data-
     * dependent stopping condition is met. That is the whole point --
     * it makes guessing passwords slow.
     */
    private static function modernHash(string $password, string $salt, string $userData, int $revision): string
    {
        $k = hash('sha256', $password . $salt . $userData, true);

        if ($revision === 5) {
            return $k;
        }

        $round = 0;

        while (true) {
            $block = str_repeat($password . $k . $userData, 64);

            $encrypted = openssl_encrypt(
                $block,
                'aes-128-cbc',
                substr($k, 0, 16),
                OPENSSL_RAW_DATA | OPENSSL_ZERO_PADDING,
                substr($k, 16, 16),
            );

            if ($encrypted === false) {
                throw new DecryptionException('AES is unavailable, so this document cannot be unlocked.');
            }

            $sum = 0;

            for ($i = 0; $i < 16; ++$i) {
                $sum += ord($encrypted[$i]);
            }

            $k = match ($sum % 3) {
                0 => hash('sha256', $encrypted, true),
                1 => hash('sha384', $encrypted, true),
                default => hash('sha512', $encrypted, true),
            };

            ++$round;

            if ($round >= 64 && ord($encrypted[strlen($encrypted) - 1]) <= $round - 32) {
                return substr($k, 0, 32);
            }
        }
    }

    /**
     * How many bytes of key Algorithm 2 should produce.
     *
     * The awkward part is that /Length in the /Encrypt dictionary only
     * describes versions 2 and 3. From version 4 the length belongs to the
     * individual crypt filter, and a conforming V4 file omits the outer
     * /Length entirely -- Ghostscript warns about one that includes it.
     * Reading the outer value regardless would quietly fall back to the
     * 40-bit default and derive a key that unlocks nothing.
     *
     * The crypt filter's own /Length is specified in bytes but written in
     * bits by a fair number of producers, so both are accepted: no real
     * key is 128 *bytes*, and none is 16 *bits*.
     */
    private static function keyLengthBytes(Dictionary $encrypt, int $version, int $revision): int
    {
        if ($revision === 2) {
            return 5;
        }

        if ($version >= 4) {
            $filters = $encrypt->get('CF');
            $name = $encrypt->get('StmF');
            $filter = $filters instanceof Dictionary && $name instanceof PdfName
                ? $filters->get($name->value())
                : null;

            $declared = $filter instanceof Dictionary ? self::integer($filter, 'Length', 16) : 16;

            return self::clampKeyLength($declared > 40 ? intdiv($declared, 8) : $declared);
        }

        return self::clampKeyLength(intdiv(self::integer($encrypt, 'Length', 40), 8));
    }

    private static function clampKeyLength(int $bytes): int
    {
        return max(5, min(16, $bytes));
    }

    /**
     * Which method enciphers strings and which enciphers streams.
     *
     * @return array{0: CryptMethod, 1: CryptMethod}
     */
    private static function cryptMethods(Dictionary $encrypt, int $version): array
    {
        if ($version < 4) {
            // Before crypt filters existed there was only ever RC4.
            return [CryptMethod::Rc4, CryptMethod::Rc4];
        }

        $filters = $encrypt->get('CF');
        $filters = $filters instanceof Dictionary ? $filters : new Dictionary();

        $lookup = static function (string $key) use ($encrypt, $filters): CryptMethod {
            $name = $encrypt->get($key);
            $name = $name instanceof PdfName ? $name->value() : 'Identity';

            if ($name === 'Identity') {
                return CryptMethod::Identity;
            }

            $filter = $filters->get($name);

            if (!$filter instanceof Dictionary) {
                return CryptMethod::Identity;
            }

            $method = $filter->get('CFM');

            return $method instanceof PdfName ? CryptMethod::fromName($method->value()) : CryptMethod::Identity;
        };

        return [$lookup('StrF'), $lookup('StmF')];
    }

    private static function xorWith(string $key, int $value): string
    {
        $out = '';

        for ($i = 0, $length = strlen($key); $i < $length; ++$i) {
            $out .= chr(ord($key[$i]) ^ $value);
        }

        return $out;
    }

    private static function firstId(?PdfArray $id): string
    {
        $first = $id?->items()[0] ?? null;

        return self::bytes($first);
    }

    private static function bytes(?PdfValue $value): string
    {
        return match (true) {
            $value instanceof PdfString => $value->bytes(),
            $value instanceof PdfHexString => $value->bytes(),
            default => '',
        };
    }

    private static function integer(Dictionary $dictionary, string $key, int $default): int
    {
        $value = $dictionary->get($key);

        return $value instanceof PdfInteger ? $value->value() : $default;
    }

    private static function wrongPasswordMessage(string $password): string
    {
        return $password === ''
            ? 'This PDF is password-protected and does not open with an empty password. Supply the password to PdfEditor::open().'
            : 'The supplied password opens neither the user nor the owner lock on this PDF.';
    }
}
