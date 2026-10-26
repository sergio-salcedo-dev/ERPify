<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Iam\Invitation\Application;

use Erpify\Shared\Audit\Application\AuditLogger;
use Erpify\Shared\Audit\Domain\AuditLevel;
use Erpify\Shared\Audit\Domain\AuditResource;
use Override;

/**
 * An {@see AuditLogger} that stamps each record with whether a unit of work was still open when it arrived.
 *
 * The stamp is what makes the invitation writers' placement falsifiable: their row is erasable only because it
 * commits with the invitation row locks that an erasure waits on, so a row moved after the transaction would
 * still be "written" — and would then be able to land after the erasure's pass over the trail.
 *
 * @internal
 */
final class TransactionObservingAuditLogger implements AuditLogger
{
    /**
     * @var list<array{
     *     action: string,
     *     level: AuditLevel,
     *     resource: ?AuditResource,
     *     metadata: array<string, mixed>,
     *     inTransaction: bool,
     * }>
     */
    public array $records = [];

    public function __construct(private readonly CountingTransactionManager $transactions)
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
            'inTransaction' => $this->transactions->opened > $this->transactions->committed
                + $this->transactions->abandoned,
        ];
    }
}
