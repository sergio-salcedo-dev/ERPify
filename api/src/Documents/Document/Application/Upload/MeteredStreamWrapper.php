<?php

declare(strict_types=1);

namespace Erpify\Documents\Document\Application\Upload;

use LogicException;

/**
 * The stream face of an {@see UploadMeter}: a read-only userland stream whose every read is the meter's. The
 * sealer only reads resources, and a userland wrapper is the one way to hand it bytes as they are produced
 * without buffering the body, which may be 100 MiB.
 *
 * Meters are handed over through a per-process registry keyed by a random token rather than through a stream
 * context, because a context option is copied as a value and the meter must be shared. The entry is removed
 * when the stream closes.
 *
 * @SuppressWarnings("PHPMD.CamelCaseMethodName") the stream wrapper protocol fixes these names
 * @SuppressWarnings("PHPMD.CamelCasePropertyName") the stream wrapper protocol fixes this name
 */
final class MeteredStreamWrapper
{
    private const string PROTOCOL = 'erpify-upload-meter';

    /** @var array<string, UploadMeter> */
    private static array $meters = [];

    /** @var resource|null set by PHP on every wrapper instance */
    public $context;

    private ?UploadMeter $meter = null;

    private string $token = '';

    /**
     * @return resource
     */
    public static function open(UploadMeter $meter)
    {
        if (!\in_array(self::PROTOCOL, \stream_get_wrappers(), true)) {
            \stream_wrapper_register(self::PROTOCOL, self::class);
        }

        $token = \bin2hex(\random_bytes(16));
        self::$meters[$token] = $meter;

        $stream = \fopen(self::PROTOCOL . '://' . $token, 'r');

        if (false === $stream) {
            unset(self::$meters[$token]);

            throw new LogicException('The upload meter stream could not be opened.');
        }

        return $stream;
    }

    // phpcs:ignore PSR1.Methods.CamelCapsMethodName.NotCamelCaps
    public function stream_open(string $address, string $mode): bool
    {
        $token = \substr($address, \strlen(self::PROTOCOL . '://'));

        if (!\str_starts_with($mode, 'r') || !isset(self::$meters[$token])) {
            return false;
        }

        $this->token = $token;
        $this->meter = self::$meters[$token];

        return true;
    }

    // phpcs:ignore PSR1.Methods.CamelCapsMethodName.NotCamelCaps
    public function stream_read(int $count): false|string
    {
        return $this->meter?->read($count) ?? false;
    }

    // phpcs:ignore PSR1.Methods.CamelCapsMethodName.NotCamelCaps
    public function stream_eof(): bool
    {
        return $this->meter?->ended() ?? true;
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
        unset(self::$meters[$this->token]);
        $this->meter = null;
    }
}
