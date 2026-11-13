<?php

declare(strict_types=1);

namespace Erpify\Documents\Document\Application\Scanning;

use LogicException;
use Throwable;

/**
 * A write-only userland stream whose every write is a callback's. The sealed object is opened by a decryptor that
 * PUSHES plaintext into a sink and cannot be paused, so handing it a callback is the one way to pass the bytes on
 * as they are produced, without buffering a 100 MiB original and without touching a disk.
 *
 * The callback answers whether it took the bytes. `false`, or any throwable, is reported to the writer as zero bytes
 * written — which the decryptor turns into a transfer failure — so a callback that must tell its caller WHY keeps the
 * reason itself: nothing here carries an exception across the stream boundary.
 *
 * Callbacks are handed over through a per-process registry keyed by a random token, because a stream context is
 * copied by value; the entry is removed when the stream closes.
 *
 * @SuppressWarnings("PHPMD.CamelCaseMethodName") the stream wrapper protocol fixes these names
 * @SuppressWarnings("PHPMD.CamelCasePropertyName") the stream wrapper protocol fixes this name
 */
final class WriteCallbackStream
{
    private const string PROTOCOL = 'erpify-write-callback';

    /** @var array<string, callable(string): bool> */
    private static array $callbacks = [];

    /** @var resource|null set by PHP on every wrapper instance */
    public $context;

    /** @var (callable(string): bool)|null */
    private $callback;

    private string $token = '';

    /**
     * @param callable(string): bool $onWrite
     *
     * @return resource
     */
    public static function open(callable $onWrite)
    {
        if (!\in_array(self::PROTOCOL, \stream_get_wrappers(), true)) {
            \stream_wrapper_register(self::PROTOCOL, self::class);
        }

        $token = \bin2hex(\random_bytes(16));
        self::$callbacks[$token] = $onWrite;

        $stream = \fopen(self::PROTOCOL . '://' . $token, 'w');

        if (false === $stream) {
            unset(self::$callbacks[$token]);

            throw new LogicException('The write-callback stream could not be opened.');
        }

        return $stream;
    }

    // phpcs:ignore PSR1.Methods.CamelCapsMethodName.NotCamelCaps
    public function stream_open(string $address, string $mode): bool
    {
        $token = \substr($address, \strlen(self::PROTOCOL . '://'));

        if (!\str_starts_with($mode, 'w') || !isset(self::$callbacks[$token])) {
            return false;
        }

        $this->token = $token;
        $this->callback = self::$callbacks[$token];

        return true;
    }

    // phpcs:ignore PSR1.Methods.CamelCapsMethodName.NotCamelCaps
    public function stream_write(string $data): int
    {
        if (null === $this->callback) {
            return 0;
        }

        try {
            return ($this->callback)($data) ? \strlen($data) : 0;
        } catch (Throwable) {
            return 0;
        }
    }

    // phpcs:ignore PSR1.Methods.CamelCapsMethodName.NotCamelCaps
    public function stream_flush(): bool
    {
        return true;
    }

    /**
     * @return array<int|string, int>
     */
    // phpcs:ignore PSR1.Methods.CamelCapsMethodName.NotCamelCaps
    public function stream_stat(): array
    {
        return [];
    }

    // phpcs:ignore PSR1.Methods.CamelCapsMethodName.NotCamelCaps
    public function stream_close(): void
    {
        unset(self::$callbacks[$this->token]);
        $this->callback = null;
    }
}
