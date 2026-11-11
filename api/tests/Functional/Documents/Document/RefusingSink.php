<?php

declare(strict_types=1);

namespace Erpify\Tests\Functional\Documents\Document;

use RuntimeException;

// phpcs:disable PSR1.Methods.CamelCapsMethodName -- the `stream_*` names are the streamWrapper protocol's own

/**
 * A writable stream that passes bytes through to the real staging sink until a byte count, and then refuses
 * every write — a disk that fills mid-seal. The partial object reaches real staging, so what removes it is
 * observable.
 *
 * @SuppressWarnings("PHPMD.CamelCaseMethodName") the stream wrapper protocol fixes these names
 * @SuppressWarnings("PHPMD.CamelCasePropertyName") the stream wrapper protocol fixes this name
 * @SuppressWarnings("PHPMD.UnusedFormalParameter") the protocol's signatures
 */
final class RefusingSink
{
    private const string PROTOCOL = 'erpify-refusing-sink';

    /** @var array<string, array{resource, int}> */
    private static array $sinks = [];

    /** @var resource|null set by PHP on every wrapper instance */
    public $context;

    private string $token = '';

    private int $written = 0;

    /**
     * @param resource $inner
     *
     * @return resource
     */
    public static function wrap($inner, int $acceptBytes)
    {
        if (!\in_array(self::PROTOCOL, \stream_get_wrappers(), true)) {
            \stream_wrapper_register(self::PROTOCOL, self::class);
        }

        $token = \bin2hex(\random_bytes(8));
        self::$sinks[$token] = [$inner, $acceptBytes];
        $stream = \fopen(self::PROTOCOL . '://' . $token, 'w');

        if (false === $stream) {
            throw new RuntimeException('The refusing sink could not be opened.');
        }

        return $stream;
    }

    public function stream_open(string $path): bool
    {
        $this->token = \substr($path, \strlen(self::PROTOCOL . '://'));

        return isset(self::$sinks[$this->token]);
    }

    public function stream_write(string $data): int
    {
        if (!isset(self::$sinks[$this->token])) {
            return 0;
        }

        [$inner, $acceptBytes] = self::$sinks[$this->token];
        $room = $acceptBytes - $this->written;

        if ($room <= 0) {
            return 0;
        }

        $written = \fwrite($inner, \substr($data, 0, $room));
        $this->written += false === $written ? 0 : $written;

        return false === $written ? 0 : $written;
    }

    public function stream_flush(): bool
    {
        if (!isset(self::$sinks[$this->token])) {
            return false;
        }

        [$inner, $acceptBytes] = self::$sinks[$this->token];

        return $this->written < $acceptBytes && \fflush($inner);
    }

    public function stream_close(): void
    {
        unset(self::$sinks[$this->token]);
    }
}
