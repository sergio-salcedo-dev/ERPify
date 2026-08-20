<?php

declare(strict_types=1);

namespace Erpify\Shared\Search\Infrastructure\Persistence\Doctrine;

use Erpify\Shared\Search\Domain\FilterOperator;
use LogicException;

/**
 * One entry of a repository's {@see SearchFieldMap}: where a public field lives in DQL, how
 * its values are normalized, and which operators it accepts (default: `eq` and `contains`; `In`
 * is opt-in — see the constructor. Restrict further when an operator would break at the SQL
 * level, e.g. CONTAINS on a UUID column).
 *
 * `requiresUuidValues` marks fields backed by a UUID column: the applier pre-validates the
 * format and rejects mismatches as a 400, instead of letting Postgres raise 22P02 (a 500).
 * It is incompatible with CONTAINS — a partial value can never be a valid UUID (perpetual
 * 400) and a full one would LIKE over a uuid column (SQL error) — so that combination is
 * rejected at construction.
 *
 * `requiresDateTimeValues` is its temporal sibling: it marks fields backed by a `timestamp`
 * column so the applier parses range bounds as strict ISO-8601 datetimes and binds them as a
 * typed parameter (a raw string against a timestamp column has no operator in Postgres → 500).
 * It is likewise incompatible with CONTAINS — a LIKE over a timestamp column breaks at the SQL
 * level — so that combination is rejected at construction too. EQ and IN are equally rejected:
 * only the range branch binds a typed datetime parameter, so an eq/in bind would send a raw
 * string against the timestamp column (Postgres 22007 → 500). The two value-kind flags are
 * mutually exclusive — a column cannot hold both UUIDs and timestamps.
 */
final readonly class FieldMapping
{
    /**
     * The guards are deliberately four flat, symmetric if-throws — one invariant each, no
     * shared predicate — so every rejected combination reads as its own sentence.
     *
     * `In` is absent from the default on purpose, so a field admits it only by asking. A default is a
     * capability grant over every field nobody configured, and `In` is the one operator in this
     * vocabulary whose value is a LIST — the only one whose wire spelling grows a sub-index. Left in the
     * default it reached six fields nobody had decided about, four of which nothing in the tree ever
     * filtered with a list — among them a person's address, an account holder's name and an account
     * number. The two that a scenario does exercise that way, a bank's name and its short code, now say so
     * at their own declaration, which is where a reader looks for the decision; so do the status, level,
     * actor type and resource type that always did.
     *
     * `SearchOperatorSurfaceGateTest` holds both directions: the default stays conservative, and every
     * field admitting `In` declares it explicitly.
     *
     * @param list<FilterOperator> $operators
     *
     * @SuppressWarnings("PHPMD.BooleanArgumentFlag")
     * @SuppressWarnings("PHPMD.CyclomaticComplexity")
     */
    public function __construct(
        public string $dqlPath,
        public ?FieldNormalizer $normalizer = null,
        private array $operators = [FilterOperator::Eq, FilterOperator::Contains],
        public bool $requiresUuidValues = false,
        public bool $requiresDateTimeValues = false,
    ) {
        $this->refuseAnEmptyOperatorSet();

        if ($this->requiresUuidValues && \in_array(FilterOperator::Contains, $this->operators, true)) {
            throw new LogicException('A field requiring UUID values cannot allow the CONTAINS operator.');
        }

        $this->refuseOperatorsATimestampColumnCannotServe();

        if ($this->requiresUuidValues && $this->requiresDateTimeValues) {
            throw new LogicException('A field cannot require both UUID and datetime values.');
        }
    }

    /**
     * Its own method rather than a sixth flat guard in the constructor, purely so the constructor's NPath
     * stays under the threshold — the other five read as one symmetric list and splitting them would cost
     * more than it buys.
     *
     * A mapped field admitting nothing is not a narrower field: every filter on it then answers
     * `unsupported-search-operator` where the caller would expect `unknown-search-field`. Reachable as a
     * typo now that declaring the list explicitly is the norm rather than the exception.
     */
    private function refuseAnEmptyOperatorSet(): void
    {
        if ([] === $this->operators) {
            throw new LogicException('A field mapping must allow at least one operator.');
        }
    }

    /**
     * The two datetime invariants travel together — a timestamp column is served by ranges and by nothing
     * else — and they sit here rather than in the constructor for the same reason as the guard above: the
     * threshold is on the constructor, and these two carry most of its branching between them.
     */
    private function refuseOperatorsATimestampColumnCannotServe(): void
    {
        if (!$this->requiresDateTimeValues) {
            return;
        }

        if (\in_array(FilterOperator::Contains, $this->operators, true)) {
            throw new LogicException('A field requiring datetime values cannot allow the CONTAINS operator.');
        }

        if (
            \in_array(FilterOperator::Eq, $this->operators, true)
            || \in_array(FilterOperator::In, $this->operators, true)
        ) {
            throw new LogicException('A field requiring datetime values cannot allow the EQ or IN operators.');
        }
    }

    public function allows(FilterOperator $operator): bool
    {
        return \in_array($operator, $this->operators, true);
    }
}
