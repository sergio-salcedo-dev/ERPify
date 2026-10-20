<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Iam\Identity\Application;

use Erpify\Shared\Audit\Application\AuditLogger;
use Erpify\Shared\Audit\Domain\AuditLevel;
use Erpify\Shared\Audit\Domain\AuditResource;
use Override;

/**
 * An {@see AuditLogger} that stamps each record with whether the subject's row lock was held when the call
 * arrived.
 *
 * "The row was written" is as true of an unlocked write as of a locked one, and the difference is the whole of
 * what keeps a row naming the subject from committing after an erasure's pass over the trail. The stamp is what
 * makes that difference an assertion.
 *
 * @internal
 */
final class RowLockAwareAuditLogger implements AuditLogger
{
    /** @var list<array{action: string, level: AuditLevel, resource: ?AuditResource, metadata: array<string, mixed>, held: bool}> */
    public array $records = [];

    public function __construct(private readonly InMemoryIdentityRowLock $identityRows)
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
            'held' => $this->identityRows->holding,
        ];
    }
}
