<?php

declare(strict_types=1);

namespace Erpify\Shared\Search\Domain;

use ArrayIterator;
use Countable;
use IteratorAggregate;
use Override;
use Traversable;

/**
 * Immutable collection of search filters. Several filters over the same
 * field are all kept — they compose with AND downstream.
 *
 * @implements IteratorAggregate<int, Filter>
 */
final readonly class Filters implements Countable, IteratorAggregate
{
    /**
     * @var list<Filter>
     */
    private array $items;

    public function __construct(Filter ...$filters)
    {
        $this->items = \array_values($filters);
    }

    public static function none(): self
    {
        return new self();
    }

    /**
     * @param list<Filter> $filters
     */
    public static function fromList(array $filters): self
    {
        return new self(...$filters);
    }

    public function isEmpty(): bool
    {
        return [] === $this->items;
    }

    /**
     * @return list<Filter>
     */
    public function all(): array
    {
        return $this->items;
    }

    #[Override]
    public function count(): int
    {
        return \count($this->items);
    }

    #[Override]
    public function getIterator(): Traversable
    {
        return new ArrayIterator($this->items);
    }
}
