<?php

declare(strict_types=1);

namespace Erpify\Shared\Search\Infrastructure\Persistence\Keyset;

/**
 * Explicit configuration for the wire (HTTP) pagination policy: page-size
 * default and ceiling, boundary semantics and whether cursors are emitted.
 *
 * Kernel-only + policy-scoped configuration (ADR Process Patterns): the ORM
 * {@see \Erpify\Shared\Search\Infrastructure\Persistence\Doctrine\Keyset\KeysetPredicateBuilder}
 * is never shared "without context" — each policy hands it explicit configuration,
 * so a wire/batch divergence that tests would miss cannot creep in through
 * accidental reuse.
 *
 * The values live HERE, not in `SearchQuery`/`SearchCriteria`, and {@see limitFor()}
 * is the one page-size resolution every keyset adapter applies.
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

    /**
     * The effective page size: a `null` request is "unspecified" and takes {@see $defaultLimit}, so an
     * adapter picks its default by selecting the policy, never by baking a value into the criteria; the
     * result is clamped to {@see $maxLimit} and is always ≥ 1. The size is part of every cursor's
     * fingerprint, so this is the value both the fetch and the fingerprint must read.
     *
     * @return positive-int
     */
    public function limitFor(?int $requested): int
    {
        return \max(1, \min($requested ?? $this->defaultLimit, $this->maxLimit));
    }
}
