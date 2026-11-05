<?php

declare(strict_types=1);

namespace Erpify\Tests\Double\Stream;

use HashContext;

/**
 * What a {@see SyntheticStream::sink()} keeps of the bytes written to it: their SHA-256 and their count.
 */
final class SyntheticSink
{
    private readonly HashContext $hash;

    private int $bytes = 0;

    public function __construct()
    {
        $this->hash = \hash_init('sha256');
    }

    public function take(string $data): void
    {
        \hash_update($this->hash, $data);
        $this->bytes += \strlen($data);
    }

    public function bytes(): int
    {
        return $this->bytes;
    }

    public function digest(): string
    {
        return \hash_final(\hash_copy($this->hash));
    }
}
