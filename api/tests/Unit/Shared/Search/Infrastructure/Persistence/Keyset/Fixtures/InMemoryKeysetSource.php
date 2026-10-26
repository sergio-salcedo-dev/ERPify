<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Shared\Search\Infrastructure\Persistence\Keyset\Fixtures;

use Erpify\Shared\Search\Domain\NavigationDirection;
use Erpify\Shared\Search\Infrastructure\Persistence\Keyset\KeysetSource;
use Override;
use UnexpectedValueException;

/**
 * A keyset source over an in-memory table ordered by `rank ASC, id ASC`, so the paginator's walk is observed
 * with no database: rows are `{rank, id}` maps, items are their ids, and every fetch is recorded so a test can
 * assert what the paginator ASKED for, not only what it returned.
 *
 * @internal
 *
 * @implements KeysetSource<array{rank: string, id: string}, string>
 */
final class InMemoryKeysetSource implements KeysetSource
{
    public const string FINGERPRINT = 'in-memory-fingerprint';

    /** @var list<array{boundary: array<string, mixed>|null, direction: NavigationDirection, maxResults: int}> */
    public array $fetches = [];

    /** @var list<array{rank: string, id: string}> */
    private readonly array $rows;

    /**
     * @param list<array{rank: string, id: string}> $rows any order; held sorted by `rank, id`
     */
    public function __construct(
        array $rows,
        private readonly int $limit,
        private readonly ?int $count = null,
    ) {
        \usort($rows, $this->compare(...));
        $this->rows = $rows;
    }

    #[Override]
    public function boundaryColumns(): array
    {
        return ['rank', 'id'];
    }

    #[Override]
    public function fingerprint(): string
    {
        return self::FINGERPRINT;
    }

    #[Override]
    public function limit(): int
    {
        return $this->limit;
    }

    #[Override]
    public function fetch(?array $boundary, NavigationDirection $direction, int $maxResults): array
    {
        $this->fetches[] = ['boundary' => $boundary, 'direction' => $direction, 'maxResults' => $maxResults];

        $isBefore = NavigationDirection::Before === $direction;
        $pivot = null === $boundary
            ? null
            : ['rank' => $this->stringOf($boundary, 'rank'), 'id' => $this->stringOf($boundary, 'id')];
        $past = [];

        foreach ($isBefore ? \array_reverse($this->rows) : $this->rows as $row) {
            if (null === $pivot || $this->compare($row, $pivot) === ($isBefore ? -1 : 1)) {
                $past[] = $row;
            }
        }

        return \array_slice($past, 0, $maxResults);
    }

    /**
     * @param array{rank: string, id: string} $row
     */
    #[Override]
    public function position(mixed $row): array
    {
        return ['rank' => $row['rank'], 'id' => $row['id']];
    }

    /**
     * @param array{rank: string, id: string} $row
     */
    #[Override]
    public function item(mixed $row): string
    {
        return $row['id'];
    }

    #[Override]
    public function count(): ?int
    {
        return $this->count;
    }

    /**
     * @param array{rank: string, id: string} $left
     * @param array{rank: string, id: string} $right
     */
    private function compare(array $left, array $right): int
    {
        return [$left['rank'], $left['id']] <=> [$right['rank'], $right['id']];
    }

    /**
     * @param array<string, mixed> $boundary
     */
    private function stringOf(array $boundary, string $column): string
    {
        $value = $boundary[$column] ?? null;

        if (!\is_string($value)) {
            throw new UnexpectedValueException(\sprintf('The boundary carries no string "%s".', $column));
        }

        return $value;
    }
}
