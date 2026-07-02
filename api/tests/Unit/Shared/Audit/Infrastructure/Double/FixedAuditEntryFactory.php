<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Shared\Audit\Infrastructure\Double;

use DateTimeImmutable;
use Erpify\Shared\Audit\Application\AuditEntryFactory;
use Erpify\Shared\Audit\Application\AuditLogEntry;
use Erpify\Shared\Audit\Domain\ActorContext;
use Erpify\Shared\Audit\Domain\AuditLevel;
use Erpify\Shared\Audit\Domain\AuditResource;
use Erpify\Shared\Uuid\Domain\Uuid;
use Override;

/**
 * {@see AuditEntryFactory} that seals a preset actor, correlation id and instant onto a real entry, so a
 * logger test can assert which sealed entry is routed without wiring the request/clock collaborators.
 *
 * @internal
 */
final readonly class FixedAuditEntryFactory implements AuditEntryFactory
{
    public function __construct(
        private ActorContext $actor,
        private string $correlationId,
        private DateTimeImmutable $occurredOn,
    ) {
    }

    /**
     * @param array<string, mixed> $metadata
     */
    #[Override]
    public function create(
        string $action,
        AuditLevel $level,
        ?AuditResource $resource = null,
        array $metadata = [],
        ?string $encryptionScopeId = null,
        ?string $id = null,
    ): AuditLogEntry {
        return AuditLogEntry::create(
            $action,
            $level,
            $this->actor,
            $this->correlationId,
            $this->occurredOn,
            $resource,
            $metadata,
            null,
            null,
            $encryptionScopeId,
            $id,
        );
    }

    #[Override]
    public function mintId(): string
    {
        return Uuid::generate();
    }
}
