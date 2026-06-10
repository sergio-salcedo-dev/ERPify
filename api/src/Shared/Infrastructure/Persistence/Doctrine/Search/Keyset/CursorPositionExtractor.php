<?php

declare(strict_types=1);

namespace Erpify\Shared\Infrastructure\Persistence\Doctrine\Search\Keyset;

use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use InvalidArgumentException;
use Stringable;
use Symfony\Component\PropertyAccess\PropertyAccessorInterface;

/**
 * Extracts the boundary-row values for the ORDER BY columns, at COLUMN
 * precision — the values a cursor carries and the keyset predicate binds.
 *
 * Two corrections over the legacy `Paginator::extractFields()`:
 *   1. Datetimes are formatted to UTC at column precision — `Y-m-d\TH:i:sP`
 *      (seconds, the schema's `TIMESTAMP(0)`). The legacy emitted microseconds
 *      (`.uP`), so a boundary value never round-tripped exactly against a
 *      second-precision column — that off-by-a-fraction boundary bug is what
 *      this piece fixes.
 *   2. The property accessor is injected once (constructor), not instantiated
 *      per call — the impurity the ADR calls out by name.
 *
 * Deterministic and side-effect free: same row + columns ⇒ same position.
 */
final readonly class CursorPositionExtractor
{
    /** Column precision (`TIMESTAMP(0)` ⇒ seconds). NOT `.uP`: see class docblock. */
    private const string COLUMN_PRECISION_FORMAT = 'Y-m-d\TH:i:sP';

    public function __construct(private PropertyAccessorInterface $accessor)
    {
    }

    /**
     * @param array<string, mixed>|object $row
     *
     * @return array<string, mixed> map of ORDER BY column ⇒ boundary value
     */
    public function extract(OrderByColumns $columns, array|object $row): array
    {
        $position = [];

        foreach ($columns->columnNames() as $column) {
            $position[$column] = $this->normalize($this->accessor->getValue($row, $this->propertyPath($column, $row)));
        }

        return $position;
    }

    /**
     * Strips the optional `alias.` prefix of a DQL identifier to a property path,
     * bracketed for array rows (`[name]`) and bare for objects (`name`).
     *
     * @param array<string, mixed>|object $row
     */
    private function propertyPath(string $column, array|object $row): string
    {
        $parts = \explode('.', $column, 2);
        $field = $parts[1] ?? $parts[0];

        return \is_array($row) ? \sprintf('[%s]', $field) : $field;
    }

    private function normalize(mixed $value): mixed
    {
        if ($value instanceof DateTimeInterface) {
            return DateTimeImmutable::createFromInterface($value)
                ->setTimezone(new DateTimeZone('UTC'))
                ->format(self::COLUMN_PRECISION_FORMAT)
            ;
        }

        if (null === $value || \is_scalar($value)) {
            return $value;
        }

        if ($value instanceof Stringable) {
            return (string) $value;
        }

        throw new InvalidArgumentException(
            \sprintf('Cannot extract a cursor boundary value of type %s.', \get_debug_type($value)),
        );
    }
}
