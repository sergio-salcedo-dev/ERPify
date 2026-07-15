<?php

declare(strict_types=1);

namespace Erpify\Shared\Search\Infrastructure\Persistence\Doctrine\Keyset;

/**
 * Explicit configuration for the wire (HTTP) pagination policy: page-size
 * default and ceiling, boundary semantics and whether cursors are emitted.
 *
 * Kernel-only + policy-scoped configuration (ADR Process Patterns): the
 * {@see KeysetPredicateBuilder} is never shared "without context" — each policy
 * hands it explicit configuration, so a wire/batch divergence that tests would
 * miss cannot creep in through accidental reuse.
 *
 * The values live HERE, not in `SearchQuery`/`SearchCriteria` (touching those is
 * PR3). Form kept minimal (YAGNI): PR2's engine refines it as it consumes it.
 */
final readonly class WirePaginationPolicy
{
    public const int DEFAULT_LIMIT = 25;

    public const int MAX_LIMIT = 100;

    /**
     * @SuppressWarnings("PHPMD.BooleanArgumentFlag")
     */
    public function __construct(
        public int $defaultLimit = self::DEFAULT_LIMIT,
        public int $maxLimit = self::MAX_LIMIT,
        /** Exclusive boundary: the boundary row itself is never re-emitted (strict `>`/`<`). */
        public bool $exclusiveBoundary = true,
        public bool $emitsCursors = true,
    ) {
    }

    public static function wire(): self
    {
        return new self();
    }
}
