<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Shared\Audit\Infrastructure\Double;

use Erpify\Shared\Audit\Application\AuditLogger;
use Erpify\Shared\Audit\Domain\AuditLevel;
use Erpify\Shared\Audit\Domain\AuditResource;
use Override;
use RuntimeException;

/**
 * An {@see AuditLogger} whose {@see log()} always throws, standing in for a self-audit write that fails
 * after a real erasure already committed — so a test can assert the command surfaces the gap rather than
 * reporting success.
 *
 * @internal
 */
final class FailingAuditLogger implements AuditLogger
{
    /**
     * @param array<string, mixed> $metadata
     */
    #[Override]
    public function log(string $action, AuditLevel $level, ?AuditResource $resource = null, array $metadata = []): void
    {
        throw new RuntimeException('audit write failed');
    }
}
