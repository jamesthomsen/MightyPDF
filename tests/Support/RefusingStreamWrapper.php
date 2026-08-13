<?php

declare(strict_types=1);

namespace MightyPDF\Tests\Support;

/**
 * A stream that accepts nothing: every write reports zero bytes taken.
 *
 * Standing in for the destinations that really do this -- a socket whose
 * peer has gone, a disk with no room left -- so that StreamSink's
 * refusal to treat a failed write as a successful one can be tested
 * without a full filesystem or a network.
 *
 * A userland wrapper cannot simulate a *short* write: PHP loops on
 * stream_write() internally until the buffer is drained, so fwrite()
 * hands back the full count however little each call accepts. Refusing
 * outright is the one failure this can reproduce.
 */
final class RefusingStreamWrapper
{
    public const string SCHEME = 'mightypdf-refusing';

    /** @var resource|null set by PHP when a context is passed */
    public $context;

    public static function register(): void
    {
        if (!in_array(self::SCHEME, stream_get_wrappers(), true)) {
            stream_wrapper_register(self::SCHEME, self::class);
        }
    }

    /** @return resource */
    public static function open()
    {
        self::register();

        $handle = fopen(self::SCHEME . '://refused', 'wb');

        if ($handle === false) {
            throw new \RuntimeException('Could not open the refusing stream.');
        }

        return $handle;
    }

    public function stream_open(string $path, string $mode, int $options, ?string &$openedPath): bool
    {
        return true;
    }

    public function stream_write(string $data): int
    {
        return 0;
    }

    public function stream_close(): void
    {
    }

    /** @return array<string, int> */
    public function stream_stat(): array
    {
        return [];
    }
}
