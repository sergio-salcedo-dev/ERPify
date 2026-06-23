<?php

declare(strict_types=1);

namespace Erpify\Shared\Persistence\Infrastructure;

use DateTimeImmutable;
use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Types\DateTimeTzImmutableType;
use Doctrine\DBAL\Types\Exception\ValueNotConvertible;
use Exception;
use Override;

/**
 * Replaces Doctrine's built-in timezone-aware datetime declaration, widening its hardcoded
 * whole-second `TIMESTAMP(0)` to microsecond `TIMESTAMP(6) WITH TIME ZONE` across every
 * `datetimetz` column.
 *
 * Append-only logs such as `event_store` format and read their timestamps as raw
 * `Y-m-d H:i:s.uP` strings end to end (the `Clock` already produces microseconds), so the column
 * must keep the sub-second part those values carry; the stock declaration rounds it to the whole
 * second at the storage boundary. It is registered in `doctrine.yaml` as the implementation of
 * both `datetimetz` and `datetimetz_immutable` so the schema comparator sees `TIMESTAMP(6)` on the
 * declared and the introspected side alike — register only one and `make db.diff` reports a
 * perpetual phantom ALTER, because the introspected column resolves to the stock `TIMESTAMP(0)`.
 *
 * Because the column now carries microseconds, hydration is liberal: Postgres returns
 * `Y-m-d H:i:s` with no fraction and `Y-m-d H:i:s.u` with one, and the stock strict parser rejects
 * the latter — so reads parse the value loosely and writes keep the microseconds, leaving a
 * round-trip through Doctrine's type layer neither rejected nor silently truncated.
 */
final class DateTimeTzMicrosType extends DateTimeTzImmutableType
{
    private const string FORMAT = 'Y-m-d H:i:s.uP';

    /**
     * @param array<string, mixed> $column
     */
    #[Override]
    public function getSQLDeclaration(array $column, AbstractPlatform $platform): string
    {
        return \str_replace('(0)', '(6)', parent::getSQLDeclaration($column, $platform));
    }

    #[Override]
    public function convertToDatabaseValue(mixed $value, AbstractPlatform $platform): ?string
    {
        if ($value instanceof DateTimeImmutable) {
            return $value->format(self::FORMAT);
        }

        return parent::convertToDatabaseValue($value, $platform);
    }

    #[Override]
    public function convertToPHPValue(mixed $value, AbstractPlatform $platform): ?DateTimeImmutable
    {
        if (null === $value || !\is_string($value)) {
            return parent::convertToPHPValue($value, $platform);
        }

        try {
            return new DateTimeImmutable($value);
        } catch (Exception $exception) {
            throw ValueNotConvertible::new($value, DateTimeImmutable::class, $exception->getMessage(), $exception);
        }
    }
}
