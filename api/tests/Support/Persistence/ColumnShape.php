<?php

declare(strict_types=1);

namespace Erpify\Tests\Support\Persistence;

use Doctrine\DBAL\Schema\Column;

/**
 * The load-bearing properties of one column — type, nullability, length, default and autoincrement — as a
 * plain array, so an expected column and one read off a DBAL {@see Column} compare with `assertSame`.
 *
 * Strict comparison is the point: a default of `false` and one of `0` are different migrations, and a loose
 * comparison would read them as equal. Each property that departs from the common case (NOT NULL, no length,
 * no default, no autoincrement) is stated by name, so an expectation reads as the column it describes.
 * Each modifier replaces its key in place: `===` on arrays is order-sensitive, so every shape keeps the key
 * order {@see read()} produces.
 *
 * @phpstan-type Shape array{type: string, notNull: bool, length: ?int, default: mixed, autoincrement: bool}
 *
 * @internal test support
 */
final readonly class ColumnShape
{
    /** @param Shape $shape */
    private function __construct(private array $shape)
    {
    }

    public static function of(string $type): self
    {
        return new self([
            'type' => $type,
            'notNull' => true,
            'length' => null,
            'default' => null,
            'autoincrement' => false,
        ]);
    }

    public static function read(Column $column): self
    {
        return new self([
            'type' => $column->getTypeName(),
            'notNull' => $column->getNotnull(),
            'length' => $column->getLength(),
            'default' => $column->getDefault(),
            'autoincrement' => $column->getAutoincrement(),
        ]);
    }

    /**
     * @param array<string, self> $columns
     *
     * @return array<string, Shape>
     */
    public static function toArrays(array $columns): array
    {
        return \array_map(static fn (self $column): array => $column->toArray(), $columns);
    }

    public function nullable(): self
    {
        return new self(\array_replace($this->shape, ['notNull' => false]));
    }

    public function length(int $length): self
    {
        return new self(\array_replace($this->shape, ['length' => $length]));
    }

    public function defaultValue(mixed $default): self
    {
        return new self(\array_replace($this->shape, ['default' => $default]));
    }

    public function autoincrement(): self
    {
        return new self(\array_replace($this->shape, ['autoincrement' => true]));
    }

    /** @return Shape */
    public function toArray(): array
    {
        return $this->shape;
    }
}
