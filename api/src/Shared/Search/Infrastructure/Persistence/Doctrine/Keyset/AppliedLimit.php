<?php

declare(strict_types=1);

namespace Erpify\Shared\Search\Infrastructure\Persistence\Doctrine\Keyset;

use InvalidArgumentException;

/**
 * Immutable receipt of the page size that was actually applied (the resolved,
 * policy-clamped `limit` — not the raw request value). Feeds the `…|limit`
 * segment of the canonical chain.
 *
 * A limit decides how many rows the page returns, so it is part of the cursor's
 * integrity surface: changing it must change the fingerprint, which it does by
 * being a canonical segment.
 */
final readonly class AppliedLimit
{
    public function __construct(public int $value)
    {
        if ($value < 1) {
            throw new InvalidArgumentException(
                \sprintf('Applied limit must be a positive integer, got %d.', $value),
            );
        }
    }
}
