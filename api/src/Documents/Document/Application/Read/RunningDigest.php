<?php

declare(strict_types=1);

namespace Erpify\Documents\Document\Application\Read;

use HashContext;

/**
 * The sha256 and the byte count of a plaintext as it streams past, compared at its end with what the row recorded.
 * One instance per pass: a pass that fed it nothing matches nothing.
 */
final class RunningDigest
{
    private readonly HashContext $context;

    private int $size = 0;

    public function __construct()
    {
        $this->context = \hash_init('sha256');
    }

    /**
     * Always true: shaped as a stream-callback answer, so it can be handed to the unseal sink as it is.
     */
    public function update(string $bytes): bool
    {
        \hash_update($this->context, $bytes);
        $this->size += \strlen($bytes);

        return true;
    }

    public function size(): int
    {
        return $this->size;
    }

    public function digestMatches(string $expected): bool
    {
        return \hash_equals($expected, \hash_final(\hash_copy($this->context)));
    }
}
