<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Shared\Audit\Infrastructure\Persistence;

use Erpify\Shared\Audit\Domain\AuditedEntity;
use Erpify\Shared\Audit\Domain\AuditResource;
use Erpify\Shared\Audit\Domain\AuditWriteOperation;
use Override;

/**
 * An audited aggregate with no personal-data field (a catalog, like `Bank`), for asserting the sealer is a
 * no-op that leaves the diff clear and references no scope.
 */
final class PlainAuditedFake implements AuditedEntity
{
    public function __construct(
        private readonly string $id,
        public string $name = '',
    ) {
    }

    #[Override]
    public function auditResource(): AuditResource
    {
        return AuditResource::of('Bank', $this->id);
    }

    #[Override]
    public function auditAction(AuditWriteOperation $operation): string
    {
        return $operation->name;
    }
}
