<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Shared\Audit\Infrastructure\Double;

use Erpify\Shared\Audit\Application\AuditLogger;
use Erpify\Shared\Audit\Domain\AuditLevel;
use Erpify\Shared\Audit\Domain\AuditResource;
use Override;

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
        ];
    }
}
