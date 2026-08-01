<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Shared\Audit\Infrastructure\Persistence;

use Erpify\Shared\Audit\Domain\AuditedEntity;
use Erpify\Shared\Audit\Domain\AuditResource;
use Erpify\Shared\Audit\Domain\AuditWriteOperation;
use Erpify\Shared\Privacy\Domain\PersonalData;
use Erpify\Shared\Privacy\Domain\PersonSubjectReference;
use Override;

/**
 * An audited aggregate with one personal-data field (`secret`), one clear field (`plain`) and one person
 * reference (`userId`), for the PII diff sealer unit test.
 *
 * The third field is what keeps the two Privacy attributes from collapsing into each other. A reference is
 * an id the erasure chain has to REMOVE, not a value the sealer has to ENCRYPT: sealing it would file it
 * under the encryption scope of this aggregate, so it would die with this row and survive the erasure of the
 * person it names. The sealer must therefore leave it in clear, and it can only be shown to if some fake
 * carries both attributes at once.
 */
final class AuditedSubjectFake implements AuditedEntity
{
    public function __construct(
        private readonly string $id,
        #[PersonalData]
        public string $secret = '',
        public string $plain = '',
        #[PersonSubjectReference(erasedBy: 'src/Iam/Identity/Application/EraseIdentitySubject.php')]
        public string $userId = '',
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
