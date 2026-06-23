<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Shared\Audit\Domain;

use Erpify\Shared\Audit\Domain\AuditLevel;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
#[CoversClass(AuditLevel::class)]
final class AuditLevelTest extends TestCase
{
    /**
     * The backing values are the lowercase tokens persisted in the `level` column, so the
     * mapping is the storage contract. The token arrives as a plain string, so `from()` is a
     * real runtime lookup, not a constant-folded literal.
     */
    #[DataProvider('provideEachTokenMapsToItsCaseCases')]
    public function testEachTokenMapsToItsCase(string $token, AuditLevel $expected): void
    {
        $this->assertSame($expected, AuditLevel::from($token));
    }

    /**
     * @return iterable<string, array{string, AuditLevel}>
     */
    public static function provideEachTokenMapsToItsCaseCases(): iterable
    {
        yield 'activity' => ['activity', AuditLevel::ACTIVITY];
        yield 'security' => ['security', AuditLevel::SECURITY];
    }

    public function testEveryLevelIsPinnedByAToken(): void
    {
        $pinnedLevels = [];

        foreach (self::provideEachTokenMapsToItsCaseCases() as $case) {
            $pinnedLevels[] = $case[1];
        }

        $this->assertSame(AuditLevel::cases(), $pinnedLevels);
    }
}
