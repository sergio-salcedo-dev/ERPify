<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Shared\Audit\Infrastructure\Persistence;

use Doctrine\DBAL\Connection;
use Erpify\Shared\Audit\Infrastructure\Persistence\DbalAuditLogPruner;
use Erpify\Shared\Persistence\Infrastructure\PostgresAdvisoryLock;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * @internal
 */
#[CoversClass(DbalAuditLogPruner::class)]
final class DbalAuditLogPrunerTest extends TestCase
{
    public function testItRejectsANonPositiveBatchSize(): void
    {
        // A batch size of 0 would make `DELETE … LIMIT 0` delete nothing while the drain loop's
        // `deleted === batchSize` stays true forever — an infinite loop holding the advisory lock. The
        // constructor rejects it loudly instead, mirroring AuditRetentionPolicy's own window guard.
        $this->expectException(InvalidArgumentException::class);

        new DbalAuditLogPruner(
            $this->createStub(Connection::class),
            new PostgresAdvisoryLock($this->createStub(Connection::class)),
            new NullLogger(),
            0,
        );
    }
}
