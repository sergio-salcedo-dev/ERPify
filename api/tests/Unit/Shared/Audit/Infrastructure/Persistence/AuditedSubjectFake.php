<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Shared\Audit\Infrastructure\Persistence;

use Erpify\Shared\Audit\Domain\AuditedEntity;
use Erpify\Shared\Audit\Domain\AuditResource;
use Erpify\Shared\Audit\Domain\AuditWriteOperation;
use Erpify\Shared\Privacy\Domain\PersonalData;
use Override;

/**
 * An audited aggregate with one personal-data field (`secret`) and one clear field (`plain`), for the
 * PII diff sealer unit test.
 */
final class AuditedSubjectFake implements AuditedEntity
{
    public function __construct(
        private readonly string $id,
        #[PersonalData]
        public string $secret = '',
        public string $plain = '',
    ) {
    }

    #[Override]
    public function auditResource(): AuditResource
    {
        return AuditResource::of('BankAccount', $this->id);
    }

    #[Override]
    public function auditAction(AuditWriteOperation $operation): string
    {
        return $operation->name;
    }
}
