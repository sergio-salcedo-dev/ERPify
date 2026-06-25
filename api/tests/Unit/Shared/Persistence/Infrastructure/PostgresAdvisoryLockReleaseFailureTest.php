<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Shared\Persistence\Infrastructure;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\ConnectionException;
use Erpify\Shared\Persistence\Infrastructure\PostgresAdvisoryLock;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * With a stubbed connection (no Postgres needed): when the unlock statement throws, withTryLock swallows
 * the failure inside its `finally` and still returns true. The `exactly(2)` expectation pins that the
 * release really was attempted (acquire, then release) — so the swallow is exercised rather than skipped,
 * and a best-effort release never turns a completed unit of work into a surfaced teardown error.
 *
 * @internal
 */
#[CoversClass(PostgresAdvisoryLock::class)]
final class PostgresAdvisoryLockReleaseFailureTest extends TestCase
{
    public function testReleaseFailureIsSwallowedAndWithTryLockStillReturnsTrue(): void
    {
        $unlockFailed = $this->createStub(ConnectionException::class);
        $connection = $this->createMock(Connection::class);
        $connection->expects($this->exactly(2))->method('fetchOne')->willReturnCallback(
            static function (string $sql) use ($unlockFailed): int {
                if (\str_contains($sql, 'pg_advisory_unlock')) {
                    throw $unlockFailed;
                }

                return 1;
            },
        );

        $completed = (new PostgresAdvisoryLock($connection))->withTryLock(
            'any-lock',
            static function (): void {
            },
        );

        $this->assertTrue($completed, 'a failed release is swallowed, so withTryLock still returns true');
    }
}
