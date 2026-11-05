<?php

declare(strict_types=1);

// phpcs:disable PSR1.Methods.CamelCapsMethodName -- the `stream_*` names are the streamWrapper protocol's own

namespace Erpify\Tests\Unit\Documents\Document\Infrastructure\Storage;

use RuntimeException;

/**
 * A stream wrapper whose reads are scripted, so a test can hand the storage adapter a source that yields some
 * bytes and then misbehaves in the two ways a real source does: it throws, or it reports a read error.
 *
 * Registered under `scripted://` for the duration of one test and unregistered after. The script is process
 * state, which is why the wrapper is a test double of its own and not an anonymous class.
 *
 * @internal
 *
 * @SuppressWarnings("PHPMD.CamelCaseMethodName") the `stream_*` names are the streamWrapper protocol's own
 * @SuppressWarnings("PHPMD.UnusedFormalParameter") the protocol fixes each method's parameter list
 */
final class ScriptedStream
{
    public const string PROTOCOL = 'scripted';

    public const string THEN_THROW = 'throw';

    public const string THEN_FAIL = 'fail';

    /** @var resource|null set by PHP when the wrapper is instantiated for a stream */
    public $context;

    private static string $bytes = '';

    private static string $ending = self::THEN_FAIL;

    private int $offset = 0;

    /**
     * @param string $then what the read AFTER the bytes does: {@see self::THEN_THROW} or {@see self::THEN_FAIL}
     *
     * @return resource
     */
    public static function open(string $bytes, string $then)
    {
        self::$bytes = $bytes;
        self::$ending = $then;

        if (!\in_array(self::PROTOCOL, \stream_get_wrappers(), true)) {
            \stream_wrapper_register(self::PROTOCOL, self::class);
        }

        $stream = \fopen(self::PROTOCOL . '://source', 'r');

        if (false === $stream) {
            throw new RuntimeException('The scripted stream could not be opened.');
        }

        return $stream;
    }

    public static function release(): void
    {
        if (\in_array(self::PROTOCOL, \stream_get_wrappers(), true)) {
            \stream_wrapper_unregister(self::PROTOCOL);
        }
    }

    public function stream_open(): bool
    {
        $this->offset = 0;

        return true;
    }

    public function stream_read(int $count): false|string
    {
        if ($this->offset < \strlen(self::$bytes)) {
            $chunk = \substr(self::$bytes, $this->offset, $count);
            $this->offset += \strlen($chunk);

            return $chunk;
        }

        if (self::THEN_THROW === self::$ending) {
            throw new RuntimeException('the source stream blew up');
        }

        return false;
    }

    public function stream_eof(): bool
    {
        return false;
    }

    /**
     * @return array<string, int>
     */
    public function stream_stat(): array
    {
        return [];
    }
}
