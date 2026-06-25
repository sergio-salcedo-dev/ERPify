<?php

declare(strict_types=1);

namespace Erpify\Shared\Audit\Infrastructure\Persistence;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Types\Types;
use Erpify\Shared\Audit\Application\AuditLogPruner;
use Erpify\Shared\Audit\Domain\AuditLevel;
use Override;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;

/**
 * {@link AuditLogPruner} backed by the `audit_log` table — the sole `DELETE` against the otherwise
 * append-only log (see {@see DbalAuditLogWriter}, which deliberately carries no delete path). The
 * `WHERE level = … AND occurred_on < …` is served by the `audit_log_level_idx (level, occurred_on)`
 * index, so the prune is a ranged delete, not a full scan. Owns no transaction and does not swallow
 * database failures.
 */
#[AsAlias(AuditLogPruner::class)]
final readonly class DbalAuditLogPruner implements AuditLogPruner
{
    public function __construct(
        private Connection $connection,
    ) {
    }

    #[Override]
    public function pruneOlderThan(AuditLevel $level, DateTimeImmutable $threshold): int
    {
        return (int) $this->connection->executeStatement(
            'DELETE FROM audit_log WHERE level = :level AND occurred_on < :threshold',
            ['level' => $level->value, 'threshold' => $threshold],
            ['threshold' => Types::DATETIMETZ_IMMUTABLE],
        );
    }
}
