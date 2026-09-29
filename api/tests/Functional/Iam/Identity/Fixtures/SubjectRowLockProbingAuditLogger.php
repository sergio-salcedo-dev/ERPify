<?php

declare(strict_types=1);

namespace Erpify\Tests\Functional\Iam\Identity\Fixtures;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\DriverException;
use Erpify\Shared\Audit\Application\AuditLogger;
use Erpify\Shared\Audit\Domain\AuditLevel;
use Erpify\Shared\Audit\Domain\AuditResource;
use Override;

/**
 * An {@see AuditLogger} that asks, at the instant of each write, whether the subject's `identity_user` row is
 * held by somebody else — and then delegates to the real logger.
 *
 * "Locked, then written" is true both when the lock and the INSERT share one transaction and when the lock is
 * taken in one and released before the write runs in another; only the second shape reopens the window an
 * erasure can slip through. The probe is what tells them apart: it runs on a SECOND connection with `NOWAIT`, so
 * `55P03` means the writer's transaction still holds the row while its INSERT is being made.
 *
 * @internal
 */
final class SubjectRowLockProbingAuditLogger implements AuditLogger
{
    /** @var list<array{action: string, rowHeld: bool}> */
    public array $writes = [];

    public function __construct(
        private readonly AuditLogger $inner,
        private readonly Connection $probe,
        private readonly string $subjectId,
    ) {
    }

    /**
     * @param array<string, mixed> $metadata
     */
    #[Override]
    public function log(string $action, AuditLevel $level, ?AuditResource $resource = null, array $metadata = []): void
    {
        $this->writes[] = ['action' => $action, 'rowHeld' => $this->rowIsHeldElsewhere()];

        $this->inner->log($action, $level, $resource, $metadata);
    }

    private function rowIsHeldElsewhere(): bool
    {
        $this->probe->beginTransaction();

        try {
            $this->probe->fetchOne(
                'SELECT id FROM identity_user WHERE id = CAST(:id AS UUID) FOR UPDATE NOWAIT',
                ['id' => $this->subjectId],
            );

            return false;
        } catch (DriverException $driverException) {
            if ('55P03' !== $driverException->getSQLState()) {
                throw $driverException;
            }

            return true;
        } finally {
            if ($this->probe->isTransactionActive()) {
                $this->probe->rollBack();
            }
        }
    }
}
