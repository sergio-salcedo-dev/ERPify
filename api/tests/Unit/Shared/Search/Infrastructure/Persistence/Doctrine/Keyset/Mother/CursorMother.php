<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Shared\Search\Infrastructure\Persistence\Doctrine\Keyset\Mother;

use Erpify\Shared\Search\Infrastructure\Persistence\Doctrine\Keyset\Cursor;
use Erpify\Shared\Search\Infrastructure\Persistence\Doctrine\Keyset\CursorCodec;

/**
 * Builds {@see Cursor} value objects with sensible defaults (static methods,
 * following the {@see \Erpify\Tests\Unit\Shared\Search\Domain\Mother\FilterMother}
 * precedent).
 */
final class CursorMother
{
    /** A valid xxh128 shape (32 hex chars). */
    public const string DEFAULT_FINGERPRINT = '0123456789abcdef0123456789abcdef';

    public const string DEFAULT_ID = '0a8c1f5e-7b2d-4c3a-9e6f-1d2c3b4a5e6f';

    /**
     * @param array<string, mixed>|null $values
     */
    public static function create(
        int $version = CursorCodec::CURRENT_VERSION,
        string $direction = Cursor::DIRECTION_AFTER,
        ?array $values = null,
        string $fingerprint = self::DEFAULT_FINGERPRINT,
    ): Cursor {
        return new Cursor($version, $direction, $values ?? self::defaultValues(), $fingerprint);
    }

    /**
     * @return array<string, mixed>
     */
    public static function defaultValues(): array
    {
        return ['name' => 'BBVA', 'id' => self::DEFAULT_ID];
    }
}
