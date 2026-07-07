<?php

declare(strict_types=1);

namespace Erpify\Shared\Search\Infrastructure\Persistence\Doctrine\Keyset;

use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use Erpify\Shared\Search\Domain\Filter;
use Erpify\Shared\Search\Domain\FilterOperator;
use ValueError;

/**
 * Derives the canonical representation of a {@see QueryExecutionTrace} and its
 * fingerprint. The canonical string is the contract whose stability the cursor
 * depends on; the fingerprint is the integrity binding stored in the cursor.
 *
 * Canonical chain (AR3): `tenant|entity|filters|sort|direction|limit|baseQuery`,
 * built exclusively from the trace's RECEIPTS, never from raw request input. The
 * trailing `baseQuery` segment is the repository-authored base query's structural
 * identity (see {@see QueryExecutionTrace::$baseQuery}). It is placed LAST because
 * it may itself carry a `|` (a DQL string literal could), so as the final segment
 * its bytes are the unambiguous remainder. Injectivity still holds: the only
 * EARLIER segment that can also carry a `|` is `filters`, and it is a
 * self-delimiting JSON array (no `json_encode` output is a proper prefix of
 * another), while `sort`/`direction`/`limit` are `|`-free — so the decomposition
 * stays unique regardless of any `|` inside `filters` or `baseQuery`. The
 * `filters` segment is a deterministic JSON array — JSON because a filter value
 * can contain any byte (a name with a `|`, a `,` or a `:`), and ad-hoc
 * delimiters are exactly where canonicalizers leak collisions; JSON escapes
 * every value, so the mapping (filters → string) is injective.
 *
 * Determinism rules:
 *   - filters sorted by (field, operator, serialized value) — a *lexicographic*
 *     order over stable tokens, so a future {@see FilterOperator} appends
 *     without renumbering existing entries (append-only stability, FR15);
 *   - `IN` value lists sorted;
 *   - values whitespace-trimmed (the value's pre-normalization spacing is not
 *     semantics);
 *   - temporal range bounds normalized to UTC at column precision
 *     (`Y-m-d\TH:i:sP`, seconds — the schema's `TIMESTAMP(0)`).
 *
 * Canonicalization is SYNTACTIC by design: it never asserts semantic
 * equivalence (`amount > 100` is NOT `amount >= 101`). `fp = xxh128(canonical)`
 * — a fast non-cryptographic digest is correct here: integrity is supplied by
 * the HMAC over the whole cursor, not by this hash.
 */
final readonly class FingerprintCanonicalizer
{
    private const string SEGMENT_SEPARATOR = '|';

    /** Output format for temporal bounds: UTC, column precision (`TIMESTAMP(0)` ⇒ seconds). */
    private const string CANONICAL_DATE_TIME_FORMAT = 'Y-m-d\TH:i:sP';

    /**
     * Accepted input formats for a temporal bound, tried in order — the same
     * RFC 3339 surface the `FilterApplier` accepts (offset/`Z`, optional
     * milli-/microsecond fractions). Receipts reaching here are post-validation,
     * so a value that matches none falls back to its trimmed string form rather
     * than throwing: canonicalization never re-validates input.
     */
    private const array INPUT_DATE_TIME_FORMATS = [
        DateTimeInterface::ATOM,
        DateTimeInterface::RFC3339_EXTENDED,
        'Y-m-d\TH:i:s.uP',
    ];

    public function fingerprint(QueryExecutionTrace $trace): string
    {
        return \hash('xxh128', $this->canonical($trace));
    }

    public function canonical(QueryExecutionTrace $trace): string
    {
        return \implode(self::SEGMENT_SEPARATOR, [
            $trace->tenant(),
            $trace->entity,
            $this->canonicalFilters($trace->filters),
            $trace->sort->field,
            $trace->sort->direction->value,
            (string) $trace->limit->value,
            $trace->baseQuery,
        ]);
    }

    private function canonicalFilters(AppliedFilters $filters): string
    {
        $entries = \array_map(
            fn (Filter $filter): array => [
                'field' => $filter->field,
                'operator' => $filter->operator->value,
                'value' => $this->canonicalValue($filter),
            ],
            $filters->all(),
        );

        // why: strcmp, not the array spaceship — `<=>` compares numeric-looking
        // strings numerically (SORT_REGULAR semantics), so distinct-but-numerically
        // -equal tokens ('1e3' vs '1000', '01' vs '1') would have no defined order
        // and PHP's unstable sort could emit them differently per input ordering,
        // breaking the byte-stable canonical contract (AR3/FR15). strcmp gives a
        // total lexicographic order over stable tokens.
        \usort(
            $entries,
            static fn (array $a, array $b): int => \strcmp($a['field'], $b['field'])
                ?: (\strcmp($a['operator'], $b['operator'])
                    ?: \strcmp(self::comparable($a['value']), self::comparable($b['value']))),
        );

        return \json_encode($entries, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    /**
     * @return string|list<string>
     */
    private function canonicalValue(Filter $filter): array|string
    {
        if (\is_array($filter->value)) {
            $values = \array_map(\mb_trim(...), $filter->value);
            // why: SORT_STRING (not the default SORT_REGULAR) so numeric-looking
            // IN members order lexicographically and a fixed multiset always
            // canonicalizes to the same byte sequence (AR3 byte-stability).
            \sort($values, SORT_STRING);

            return $values;
        }

        $trimmed = \mb_trim($filter->value);

        if ($this->isRangeOperator($filter->operator)) {
            return $this->normalizeTemporalBound($trimmed);
        }

        return $trimmed;
    }

    private function isRangeOperator(FilterOperator $operator): bool
    {
        return \in_array(
            $operator,
            [FilterOperator::Gt, FilterOperator::Gte, FilterOperator::Lt, FilterOperator::Lte],
            true,
        );
    }

    private function normalizeTemporalBound(string $value): string
    {
        foreach (self::INPUT_DATE_TIME_FORMATS as $format) {
            $dateTime = $this->parseStrict($format, $value);

            if ($dateTime instanceof DateTimeImmutable) {
                return $dateTime->setTimezone(new DateTimeZone('UTC'))->format(self::CANONICAL_DATE_TIME_FORMAT);
            }
        }

        return $value;
    }

    private function parseStrict(string $format, string $value): ?DateTimeImmutable
    {
        try {
            $dateTime = DateTimeImmutable::createFromFormat($format, $value);
        } catch (ValueError) {
            return null;
        }

        $errors = DateTimeImmutable::getLastErrors();
        $hasParseProblem = false !== $errors && ($errors['warning_count'] > 0 || $errors['error_count'] > 0);

        if (false === $dateTime || $hasParseProblem) {
            return null;
        }

        return $dateTime;
    }

    /**
     * @param string|list<string> $value
     */
    private static function comparable(array|string $value): string
    {
        return \is_array($value)
            ? \json_encode($value, JSON_THROW_ON_ERROR)
            : $value;
    }
}
