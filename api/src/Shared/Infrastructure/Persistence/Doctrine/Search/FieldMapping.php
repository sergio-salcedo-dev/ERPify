<?php

declare(strict_types=1);

namespace Erpify\Shared\Infrastructure\Persistence\Doctrine\Search;

use Erpify\Shared\Domain\Search\FilterOperator;

/**
 * One entry of a repository's {@see SearchFieldMap}: where a public field lives in DQL, how
 * its values are normalized, and which operators it accepts (default: all three — restrict
 * when an operator would break at the SQL level, e.g. CONTAINS on a UUID column).
 *
 * `requiresUuidValues` marks fields backed by a UUID column: the applier pre-validates the
 * format and rejects mismatches as a 400, instead of letting Postgres raise 22P02 (a 500).
 */
final readonly class FieldMapping
{
    /**
     * @param list<FilterOperator> $operators
     *
     * @SuppressWarnings("PHPMD.BooleanArgumentFlag")
     */
    public function __construct(
        public string $dqlPath,
        public ?FieldNormalizer $normalizer = null,
        private array $operators = [FilterOperator::Eq, FilterOperator::In, FilterOperator::Contains],
        public bool $requiresUuidValues = false,
    ) {
    }

    public function allows(FilterOperator $operator): bool
    {
        return \in_array($operator, $this->operators, true);
    }
}
