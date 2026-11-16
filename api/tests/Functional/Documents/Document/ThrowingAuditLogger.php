<?php

declare(strict_types=1);

namespace Erpify\Tests\Functional\Documents\Document;

use Erpify\Shared\Audit\Application\AuditLogger;
use Erpify\Shared\Audit\Domain\AuditLevel;
use Erpify\Shared\Audit\Domain\AuditResource;
use Override;
use Throwable;

/**
 * An {@see AuditLogger} whose store is down: every write throws what it was given, so a test can prove an action
 * that must be audited first is refused rather than served unrecorded.
 *
 * @internal
 */
final readonly class ThrowingAuditLogger implements AuditLogger
{
    public function __construct(private Throwable $failure)
    {
    }

    #[Override]
    public function log(string $action, AuditLevel $level, ?AuditResource $resource = null, array $metadata = []): void
    {
        throw $this->failure;
    }
}
