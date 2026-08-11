<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Iam\Identity\Application;

use Erpify\Shared\Audit\Application\AuditLogger;
use Erpify\Shared\Audit\Domain\AuditLevel;
use Erpify\Shared\Audit\Domain\AuditResource;
use Override;

/**
 * An {@see AuditLogger} that stamps each record with whether the unit of work had already committed when the
 * call arrived.
 *
 * The stamp is the whole point: "the audit row was written" is true on both sides of the transaction
 * boundary, so a test asserting only that would stay green if the write were moved back inside the closure —
 * which is the one placement that lets a failed audit INSERT roll the lockout back.
 *
 * @internal
 */
final class TransactionAwareAuditLogger implements AuditLogger
{
    /** @var list<array{action: string, level: AuditLevel, resource: ?AuditResource, metadata: array<string, mixed>, committed: bool}> */
    public array $records = [];

    public function __construct(private readonly InlineTransactionManager $transactionManager)
    {
    }

    /**
     * @param array<string, mixed> $metadata
     */
    #[Override]
    public function log(string $action, AuditLevel $level, ?AuditResource $resource = null, array $metadata = []): void
    {
        $this->records[] = [
            'action' => $action,
            'level' => $level,
            'resource' => $resource,
            'metadata' => $metadata,
            'committed' => $this->transactionManager->committed,
        ];
    }
}
