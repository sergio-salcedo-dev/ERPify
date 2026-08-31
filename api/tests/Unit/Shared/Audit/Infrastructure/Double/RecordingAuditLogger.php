<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Shared\Audit\Infrastructure\Double;

use Erpify\Shared\Audit\Application\AuditLogger;
use Erpify\Shared\Audit\Domain\AuditLevel;
use Erpify\Shared\Audit\Domain\AuditResource;
use Override;
use RuntimeException;

/**
 * Spy {@see AuditLogger} capturing every recorded action, so a test can assert the erasure self-audits
 * exactly once with the expected action, level and metadata.
 *
 * @internal
 */
final class RecordingAuditLogger implements AuditLogger
{
    /** @var list<array{action: string, level: AuditLevel, resource: ?AuditResource, metadata: array<string, mixed>}> */
    public array $records = [];

    /**
     * Makes every write throw, so a test can drive the `catch` of a best-effort audit projection — the branch
     * that turns a trail outage into a log line instead of a 500, and the only place those collaborators
     * write to a logger at all.
     */
    public bool $failOnLog = false;

    /**
     * @param array<string, mixed> $metadata
     */
    #[Override]
    public function log(string $action, AuditLevel $level, ?AuditResource $resource = null, array $metadata = []): void
    {
        if ($this->failOnLog) {
            throw new RuntimeException('Audit trail unavailable.');
        }

        $this->records[] = [
            'action' => $action,
            'level' => $level,
            'resource' => $resource,
            'metadata' => $metadata,
        ];
    }
}
