<?php

declare(strict_types=1);

namespace Erpify\Shared\Persistence\Infrastructure;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use InvalidArgumentException;
use LogicException;
use UnexpectedValueException;

/**
 * Reads every distinct non-null value of one identifier column, in ascending order, as a sequence of bounded
 * keyset pages rather than one unbounded statement.
 *
 * Each page is `SELECT DISTINCT c … WHERE c IS NOT NULL AND (scope) [AND c > :after] ORDER BY c LIMIT :n`, so
 * no statement returns more than `pageSize` rows and each one is a range scan over an index whose leading
 * column is `c` (or whose leading columns the scope pins by equality). The loop ends on the first page
 * shorter than `pageSize`, which costs one extra, empty query when the column holds an exact multiple of it.
 * `OFFSET` is never used: it re-reads every skipped row, so the last page of a large column would cost a
 * full scan.
 *
 * The cursor is the last value exactly as the database returned it, compared with `>` in the column's own
 * type — the same ordering the `ORDER BY` uses, so a `uuid` column pages in `uuid` order rather than in some
 * text collation that could disagree with it. Two page shapes are built rather than `(:after IS NULL OR c >
 * :after)`: that form only reaches the range scan when the plan is tailored to the bound value, which a
 * prepared statement does not promise.
 *
 * The pages are not one snapshot. The strictly increasing cursor still hands each value out at most once,
 * and a row inserted behind the cursor mid-read is one a single read that had already started would have
 * missed as well.
 *
 * `$table`, `$column` and `$scope` are interpolated into the SQL, so they must be class literals of the
 * caller — never input. Values travel only as bound parameters, including the scope's own.
 */
final readonly class KeysetDistinctIds
{
    public const int DEFAULT_PAGE_SIZE = 5000;

    private const string AFTER = 'keyset_after';

    private const string LIMIT = 'keyset_limit';

    public function __construct(
        private Connection $connection,
        private int $pageSize = self::DEFAULT_PAGE_SIZE,
    ) {
        if ($pageSize < 1) {
            throw new InvalidArgumentException(
                \sprintf('Keyset page size must be at least 1, got %d.', $pageSize),
            );
        }
    }

    /**
     * @param string               $scope           an extra SQL predicate over the table, ANDed with the rest
     * @param array<string, mixed> $scopeParameters the scope's bound values, keyed by placeholder name
     *
     * @throws LogicException           when a scope parameter reuses a name the paging itself binds
     * @throws UnexpectedValueException when the database returns a value that is not a string — the callers'
     *                                  output is read as an absence, so a dropped value would be a
     *                                  fabricated finding rather than a smaller one
     *
     * @return list<string> distinct, ascending, empty when the column holds none
     */
    public function idsOf(string $table, string $column, string $scope = 'TRUE', array $scopeParameters = []): array
    {
        foreach ([self::AFTER, self::LIMIT] as $reserved) {
            if (\array_key_exists($reserved, $scopeParameters)) {
                throw new LogicException(
                    \sprintf('The scope parameter "%s" is reserved for keyset paging.', $reserved),
                );
            }
        }

        $select = \sprintf('SELECT DISTINCT %1$s FROM %2$s WHERE %1$s IS NOT NULL AND (%3$s)', $column, $table, $scope);
        $order = \sprintf(' ORDER BY %s LIMIT :%s', $column, self::LIMIT);
        $firstPage = $select . $order;
        $nextPage = \sprintf('%s AND %s > :%s%s', $select, $column, self::AFTER, $order);

        $parameters = $scopeParameters + [self::LIMIT => $this->pageSize];
        $types = [self::LIMIT => ParameterType::INTEGER];

        $ids = [];
        $after = null;

        do {
            $page = null === $after
                ? $this->connection->fetchFirstColumn($firstPage, $parameters, $types)
                : $this->connection->fetchFirstColumn($nextPage, $parameters + [self::AFTER => $after], $types);

            foreach ($page as $id) {
                if (!\is_string($id)) {
                    throw new UnexpectedValueException(\sprintf(
                        'Expected %s.%s to hold string identifiers, got %s.',
                        $table,
                        $column,
                        \get_debug_type($id),
                    ));
                }

                $ids[] = $id;
                $after = $id;
            }
        } while (\count($page) === $this->pageSize);

        return $ids;
    }
}
