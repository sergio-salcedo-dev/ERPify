<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Shared\Architecture\Fixture\PersonCapture;

use Erpify\Shared\Audit\Domain\AuditedEntity;
use Erpify\Shared\Audit\Domain\AuditResource;
use Erpify\Shared\Audit\Domain\AuditWriteOperation;
use Override;

/**
 * An aggregate that opts into write change-data-capture, so the containment rule has an entity to report.
 * The committed tree offers none: its only {@see AuditedEntity} implementors are `Bank` and `BankAccount`,
 * and every column of both is classified `non-person`, so a rule asserted over `src` alone is satisfied by
 * an empty intersection and stays green even if its body never runs.
 *
 * It lives in a directory of its own rather than beside the person-reference fixtures because that folder is
 * SWEPT: the rules gate pins the exact universe derived from it, so a seventh entity there would be a change
 * to those lists rather than a new case. Nothing here needs sweeping — the rule takes a classification as
 * plain data, and all this class contributes is a name that `is_a()` can answer for. It therefore carries no
 * ORM mapping and no `#[PersonSubjectReference]`: the marker under test is {@see AuditedEntity} alone.
 *
 * @internal test fixture
 */
final class CapturedPersonFixtureEntity implements AuditedEntity
{
    public string $subjectId = '';

    public string $tenantId = '';

    #[Override]
    public function auditResource(): AuditResource
    {
        return AuditResource::of('CapturedPersonFixture', $this->subjectId);
    }

    #[Override]
    public function auditAction(AuditWriteOperation $operation): string
    {
        return 'CAPTURED_PERSON_FIXTURE_' . $operation->name;
    }
}
