<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Shared\Audit\Infrastructure\Persistence;

use Erpify\Shared\Audit\Domain\AuditedEntity;
use Erpify\Shared\Audit\Domain\AuditResource;
use Erpify\Shared\Audit\Domain\AuditWriteOperation;
use Erpify\Shared\Audit\Domain\SealedUnderOwnerScope;
use Erpify\Shared\Privacy\Domain\PersonalData;
use Override;

/**
 * A dependent audited row whose one personal-data field belongs to an owner aggregate: the trail names the row,
 * the sealer keys the field under the owner.
 */
final class OwnerSealedAuditedFake implements AuditedEntity, SealedUnderOwnerScope
{
    public function __construct(
        private readonly string $id,
        private readonly string $ownerId,
        #[PersonalData]
        public string $secret = '',
    ) {
    }

    #[Override]
    public function auditResource(): AuditResource
    {
        return AuditResource::of('BankAccountEvidence', $this->id);
    }

    #[Override]
    public function auditSealingOwner(): AuditResource
    {
        return AuditResource::of('BankAccount', $this->ownerId);
    }

    #[Override]
    public function auditAction(AuditWriteOperation $operation): string
    {
        return $operation->name;
    }
}
