<?php

declare(strict_types=1);

namespace Erpify\Tests\Functional\Documents\Document;

use Closure;

// phpcs:disable PSR1.Methods.CamelCapsMethodName -- the `stream_*` names are the streamWrapper protocol's own

/**
 * A request body that runs a callback before one chosen read — the way to make something happen to an upload
 * WHILE it streams (another connection expiring its intent) without threads. Bytes are a fixed pattern.
 *
 * @SuppressWarnings("PHPMD.CamelCaseMethodName") the stream wrapper protocol fixes these names
 * @SuppressWarnings("PHPMD.CamelCasePropertyName") the stream wrapper protocol fixes this name
 */
final class ScriptedBody
{
    private const string PROTOCOL = 'erpify-scripted-body';

    /** @var resource|null set by PHP on every wrapper instance */
    public $context;

    private static int $length = 0;

    private static int $beforeRead = 0;

    private static ?Closure $callback = null;

    private int $position = 0;

    private int $reads = 0;

    /**
     * @return resource
     */
    public static function open(int $length, int $beforeRead = 0, ?Closure $callback = null)
    {
        if (!\in_array(self::PROTOCOL, \stream_get_wrappers(), true)) {
            \stream_wrapper_register(self::PROTOCOL, self::class);
        }

        self::$length = $length;
        self::$beforeRead = $beforeRead;
        self::$callback = $callback;

        $stream = \fopen(self::PROTOCOL . '://body', 'r');
        \assert(\is_resource($stream));

        return $stream;
    }

    public function stream_open(): bool
    {
        return true;
    }

    public function stream_read(int $count): string
    {
        ++$this->reads;

        if ($this->reads === self::$beforeRead && self::$callback instanceof Closure) {
            (self::$callback)();
        }

        $chunk = \min($count, self::$length - $this->position);
        $this->position += $chunk;

        return \str_repeat('e', \max(0, $chunk));
    }

    public function stream_eof(): bool
    {
        return $this->position >= self::$length;
    }

    /**
     * @return array<int|string, int>
     */
    public function stream_stat(): array
    {
        return [];
    }
}
