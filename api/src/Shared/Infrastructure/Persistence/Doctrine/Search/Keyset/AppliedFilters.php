<?php

declare(strict_types=1);

namespace Erpify\Shared\Infrastructure\Persistence\Doctrine\Search\Keyset;

use Erpify\Shared\Domain\Search\Filter;

/**
 * Immutable receipt of the filters that were actually applied to a search query.
 *
 * A receipt — not an input. The {@see QueryExecutionTrace} (and therefore the
 * fingerprint) is derived exclusively from receipts, never from the raw request
 * criteria, so a drift between "what was requested" and "what was applied" can
 * never silently corrupt a cursor's integrity binding. In PR2 the
 * `FilterApplier` produces this; in PR1 it is constructed directly by callers
 * and test object mothers.
 *
 * Filter ordering here is irrelevant: the {@see FingerprintCanonicalizer} sorts
 * filters deterministically, so two receipts that differ only in filter order
 * canonicalize identically.
 */
final readonly class AppliedFilters
{
    /**
     * @var list<Filter>
     */
    private array $filters;

    public function __construct(Filter ...$filters)
    {
        $this->filters = \array_values($filters);
    }

    public static function none(): self
    {
        return new self();
    }

    /**
     * @return list<Filter>
     */
    public function all(): array
    {
        return $this->filters;
    }
}
