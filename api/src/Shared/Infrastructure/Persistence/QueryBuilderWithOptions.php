<?php

declare(strict_types=1);

namespace Erpify\Shared\Infrastructure\Persistence;

use Doctrine\ORM\QueryBuilder;

/**
 * QueryBuilder that carries a free-form options bag, consumed by the {@see Paginator}.
 */
class QueryBuilderWithOptions extends QueryBuilder
{
    /** @var array<string, mixed> */
    private array $options = [];

    /** @return array<string, mixed> */
    public function getOptions(): array
    {
        return $this->options;
    }

    /** @param array<string, mixed> $options */
    public function setOptions(array $options): self
    {
        $this->options = $options;

        return $this;
    }

    public function setOption(string $key, mixed $value): self
    {
        $this->options[$key] = $value;

        return $this;
    }
}
