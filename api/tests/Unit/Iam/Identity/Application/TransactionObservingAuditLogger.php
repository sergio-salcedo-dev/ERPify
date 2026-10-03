<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Iam\Identity\Application;

use ArrayObject;
use Erpify\Shared\Audit\Application\AuditLogger;
use Erpify\Shared\Audit\Domain\AuditLevel;
use Erpify\Shared\Audit\Domain\AuditResource;
use Erpify\Tests\Unit\Shared\Audit\Infrastructure\Double\RecordingAuditLogger;
use Override;

/**
 * Journals whether each write ran inside the transaction, then records it like {@see RecordingAuditLogger},
 * so an ordering test can read the lock and the write off one journal.
 *
 * @internal
 */
final readonly class TransactionObservingAuditLogger implements AuditLogger
{
    /**
     * @param ArrayObject<int, array{string, bool}> $journal
     */
    public function __construct(
        private RecordingAuditLogger $inner,
        private ArrayObject $journal,
        private InlineTransactionManager $transactions,
    ) {
    }

    #[Override]
    public function log(string $action, AuditLevel $level, ?AuditResource $resource = null, array $metadata = []): void
    {
        $this->journal->append(['write', $this->transactions->inside]);
        $this->inner->log($action, $level, $resource, $metadata);
    }
}
